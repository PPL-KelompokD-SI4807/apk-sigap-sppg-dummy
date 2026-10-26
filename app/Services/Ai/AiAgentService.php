<?php

namespace App\Services\Ai;

use App\Enums\HasilAi;
use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\Drafts\DraftLimitException;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Llm\SupportsVision;
use App\Services\Ai\Llm\ToolCall;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\ArgumentValidator;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolException;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\Tools\ToolSelector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Orkestrator Agen AI (FR-11). Alur per pesan:
 *   1. pengguna aktif + rate limit (per menit & per jam, NFR-12);
 *   2. masukan dibersihkan, dipotong, dibungkus sebagai data tak tepercaya (IntentParser);
 *      foto pada klien SupportsVision: tahap 1 model vision tanpa tool -> teks hasil baca = data (D6);
 *   3. tool disaring per peran (forUser) lalu, bila aktif, dipilih sesuai niat (ToolSelector, subset);
 *   4. loop tool-calling maks config ai.agent.max_iterations; tiap panggilan melewati pelindung token
 *      global (TokenBudget); tiap tool call: nama dikenal? -> tool milik peran pengguna? -> ditawarkan?
 *      -> argumen JSON sah? -> argumen terlindungi dibuang -> skema argumen valid? -> eksekusi
 *      (baca: langsung ber-scope; tulis: hanya AiDraft);
 *   5. jawaban teks polos dipotong; jejak + jumlah token (angka) dicatat di ai_interactions (tanpa isi pesan).
 * LLM gagal/tidak tersedia/terlalu lama -> balasan ramah per kategori + fallback formulir manual.
 */
class AiAgentService
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolRegistry $registry,
        private readonly IntentParser $parser,
        private readonly ArgumentValidator $validator,
        private readonly ToolSelector $selector,
        private readonly TokenBudget $budget,
    ) {}

    /**
     * False bila klien aktif membaca gambar lewat model vision terpisah yang belum dikonfigurasi
     * (LLM_VISION_MODEL kosong): AiChatHandler membalas ramah SEBELUM mengunduh foto (D6).
     * Klien tanpa SupportsVision (Anthropic, Fake) menerima gambar langsung (perilaku lama).
     */
    public function acceptsImages(): bool
    {
        return ! $this->llm instanceof SupportsVision || $this->llm->supportsVision();
    }

    public function handle(User $user, AiInput $input): AiReply
    {
        $started = microtime(true);

        if (! $user->canAuthenticate()) {
            return new AiReply('Akun Anda nonaktif. Hubungi Admin SPPG.', fallback: true);
        }

        $interaction = AiInteraction::query()->create([
            'sppg_id' => $user->isInvestor() ? $user->sppgAktifId() : $user->sppg_id,
            'user_id' => $user->getKey(),
            'jenis_input' => $input->type,
            'model' => mb_substr($this->llm->modelName(), 0, 100),
            'hasil' => HasilAi::Gagal,
        ]);

        $log = ['iterasi' => 0, 'tools' => [], 'panjang_masukan' => 0, 'gambar' => count($input->images)];

        try {
            if (! $this->withinRateLimit($user)) {
                return $this->finish($interaction, $started, $log, HasilAi::Ditolak, 'rate_limited', new AiReply(
                    'Batas penggunaan Agen AI tercapai. Coba lagi nanti atau isi formulir di Mini App.',
                    fallback: true,
                    interactionId: $interaction->id,
                ));
            }

            // Investor (R-06a) di chat bot tidak mengirim header SPPG aktif: hanya boleh bila SPPG-nya satu.
            if ($user->isInvestor() && $user->sppgAktifId() === null) {
                return $this->finish($interaction, $started, $log, HasilAi::Ditolak, 'sppg_aktif_belum_dipilih', new AiReply(
                    'Anda memiliki lebih dari satu SPPG (atau belum terhubung ke SPPG). Buka Mini App dan pilih SPPG aktif untuk melihat datanya.',
                    fallback: true,
                    interactionId: $interaction->id,
                ));
            }

            $text = $this->parser->sanitize($input->text, (int) config('ai.agent.max_input_chars', 2000));
            $log['panjang_masukan'] = mb_strlen($text);

            if ($text === '' && $input->images === []) {
                return $this->finish($interaction, $started, $log, HasilAi::Ditolak, 'masukan_kosong', new AiReply(
                    'Silakan ketik permintaan Anda, kirim foto nota, atau pesan suara.',
                    interactionId: $interaction->id,
                ));
            }

            return ActingUser::run($user, function () use ($user, $input, $text, $interaction, $started, &$log) {
                return $this->loop($user, $input, $text, $interaction, $started, $log);
            });
        } catch (LlmException $e) {
            // Hanya kategori + status HTTP + kode detail yang dicatat (tanpa isi respons/kunci, D5).
            return $this->finish($interaction, $started, $log, HasilAi::Fallback, $e->logLabel(), new AiReply(
                $e->friendlyMessage(),
                fallback: true,
                interactionId: $interaction->id,
            ));
        } catch (Throwable $e) {
            report($e);

            return $this->finish($interaction, $started, $log, HasilAi::Gagal, class_basename($e), new AiReply(
                'Maaf, terjadi kesalahan saat memproses pesan. Silakan isi lewat formulir di Mini App.',
                fallback: true,
                interactionId: $interaction->id,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $log
     */
    private function loop(User $user, AiInput $input, string $text, AiInteraction $interaction, float $started, array &$log): AiReply
    {
        $budget = (float) config('ai.agent.time_budget_seconds', 25);
        $deadline = $started + $budget;
        $nonce = $this->parser->nonce();

        // D6: klien dengan model vision terpisah -> tahap 1 baca gambar tanpa tool; hasilnya data.
        $imageText = $input->images !== [] && $this->llm instanceof SupportsVision
            ? $this->readImages($this->llm, $input, $deadline, $log)
            : null;

        // D7: subset tool milik peran yang relevan (selalu subset forUser()).
        $selection = $this->selector->select($this->registry->forUser($user), trim($text.' '.($imageText ?? '')), $input->type);
        $tools = $selection->tools;
        $schemas = $selection->schemas;
        $log['tool_ditawarkan'] = count($tools);

        if ($selection->active) {
            $log['tool_dipilih'] = true;
        }

        $system = $this->parser->systemPrompt($user, array_keys($tools), $nonce, $selection->active ? $selection->otherModules : []);
        $messages = [$this->parser->userMessage($input, $text, $nonce, $imageText)];
        $context = new ToolContext($interaction->id, $input->type);

        $maxIterations = max(1, (int) config('ai.agent.max_iterations', 4));
        $maxCalls = max(1, (int) config('ai.agent.max_tool_calls_per_turn', 5));
        $drafts = [];
        $final = null;
        $model = $this->llm->modelName();

        for ($i = 0; $i < $maxIterations; $i++) {
            if (microtime(true) - $started > $budget) {
                break;
            }

            $log['iterasi'] = $i + 1;
            $estimate = TokenBudget::estimateRequest($system, $messages, $schemas);
            $this->budget->check($model, $estimate);
            $response = $this->llm->chat($messages, $schemas, ['system' => $system, 'deadline' => $deadline]);
            $this->recordUsage($model, $response, $estimate, $log);

            if (! $response->hasToolCalls()) {
                $final = $response->text;
                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->text,
                'tool_calls' => array_map(fn (ToolCall $c) => $c->toArray(), $response->toolCalls),
            ];

            foreach ($response->toolCalls as $n => $call) {
                $result = $n < $maxCalls
                    ? $this->runTool($user, $call, $context, $log, $tools)
                    : $this->record($log, $call->name, ToolResult::denied('Terlalu banyak pemanggilan tool dalam satu langkah.'), []);

                if ($result->draft !== null) {
                    $drafts[] = $result->draft;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'name' => $call->name,
                    'content' => $this->parser->wrapToolResult($result->toModelContent((int) config('ai.agent.max_tool_result_chars', 6000)), $nonce),
                    'is_error' => $result->isError(),
                ];
            }
        }

        $statuses = array_column($log['tools'], 'status');
        $deniedOnly = $statuses !== [] && array_diff($statuses, [ToolResult::DITOLAK, ToolResult::INVALID]) === [];

        if ($final === null) {
            // Loop terpotong (iterasi/waktu habis): jangan teruskan; draf yang sudah ada tetap ditawarkan.
            return $this->finish($interaction, $started, $log, HasilAi::Fallback, 'loop_terpotong', new AiReply(
                $drafts === []
                    ? 'Maaf, permintaan ini belum dapat diselesaikan otomatis. Silakan isi lewat formulir di Mini App.'
                    : 'Sebagian permintaan sudah disiapkan sebagai draf. Periksa dan simpan di Mini App.',
                $drafts,
                fallback: $drafts === [],
                interactionId: $interaction->id,
            ));
        }

        $reply = $this->cleanOutput($final);

        if ($reply === '') {
            $reply = $drafts === [] ? 'Baik.' : 'Draf sudah disiapkan. Periksa dan simpan di Mini App.';
        }

        return $this->finish($interaction, $started, $log, $deniedOnly ? HasilAi::Ditolak : HasilAi::Sukses, null, new AiReply(
            $reply,
            $drafts,
            interactionId: $interaction->id,
        ));
    }

    /**
     * @param  array<string, mixed>  $log
     * @param  array<string, AiTool>  $offered  tool yang ditawarkan pada pesan ini (subset forUser())
     */
    private function runTool(User $user, ToolCall $call, ToolContext $context, array &$log, array $offered): ToolResult
    {
        try {
            $tool = $this->registry->resolveFor($user, $call->name);
        } catch (ToolException $e) {
            Log::warning('ai.tool_ditolak', ['user_id' => $user->getKey(), 'alasan' => $e->reason, 'tool' => mb_substr($call->name, 0, 64)]);

            return $this->record($log, $call->name, $e->reason === ToolException::UNKNOWN
                ? ToolResult::invalid('Tool tidak dikenal.')
                : ToolResult::denied(), []);
        }

        // Pemilihan tool dinamis: hanya tool yang ditawarkan yang boleh dijalankan (skemanya dikenal model).
        if (! isset($offered[$tool->name()])) {
            return $this->record($log, $call->name, ToolResult::invalid('Tool tidak ditawarkan untuk pesan ini. Minta pengguna menyebut modulnya dengan jelas.'), []);
        }

        if (! $call->argumentsValid) {
            return $this->record($log, $call->name, ToolResult::invalid('Argumen harus berupa objek JSON.'), []);
        }

        [$arguments, $stripped] = ArgumentValidator::stripProtected($call->arguments, (array) config('ai.protected_arguments', []));

        $json = (string) json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (mb_strlen($json) > (int) config('ai.agent.max_argument_chars', 4000)) {
            return $this->record($log, $call->name, ToolResult::invalid('Argumen terlalu panjang.'), [], $stripped);
        }

        $errors = $this->validator->validate($tool->parameters(), $arguments);

        if ($errors !== []) {
            return $this->record($log, $call->name, ToolResult::invalid('Argumen tidak valid: '.implode(' ', array_slice($errors, 0, 8))), $arguments, $stripped);
        }

        try {
            $result = $tool->execute($user, $arguments, $context);
        } catch (AuthorizationException) {
            $result = ToolResult::denied();
        } catch (DraftLimitException $e) {
            $result = ToolResult::denied($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $result = ToolResult::failed();
        }

        return $this->record($log, $call->name, $result, $arguments, $stripped);
    }

    /**
     * Catat jejak tool (argumen diminimalkan: nilai teks dipotong, kunci rahasia disamarkan).
     *
     * @param  array<string, mixed>  $log
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $stripped
     */
    private function record(array &$log, string $name, ToolResult $result, array $arguments, array $stripped = []): ToolResult
    {
        if (count($log['tools']) < 20) {
            $log['tools'][] = array_filter([
                'nama' => mb_substr($name, 0, 64),
                'status' => $result->status,
                'argumen' => $this->redact($arguments),
                'argumen_dibuang' => $stripped,
                'draf' => $result->draft?->uuid,
            ], fn ($v) => $v !== [] && $v !== null);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function redact(array $arguments, int $depth = 0): array
    {
        $out = [];

        foreach ($arguments as $key => $value) {
            if (is_string($key) && preg_match('/token|password|sandi|secret|api_key|nik/i', $key) === 1) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = $depth >= 3 ? '[...]' : $this->redact($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = mb_substr($value, 0, 120);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Tahap 1 vision (D6): model vision TANPA tool menyalin teks gambar. Hasilnya data tak tepercaya
     * (dibersihkan & dibungkus nonce oleh IntentParser::userMessage). Gagal -> LlmException ramah.
     *
     * @param  array<string, mixed>  $log
     *
     * @throws LlmException
     */
    private function readImages(SupportsVision $vision, AiInput $input, float $deadline, array &$log): string
    {
        if (! $vision->supportsVision()) {
            throw LlmException::visionUnsupported();
        }

        $model = (string) $vision->visionModelName();
        $estimate = TokenBudget::estimate($this->parser->visionInstruction()) + count($input->images) * TokenBudget::IMAGE_TOKENS;
        $this->budget->check($model, $estimate);

        try {
            $response = $vision->readImages($input->images, $this->parser->visionInstruction(), ['deadline' => $deadline]);
        } catch (LlmException $e) {
            // Sibuk/kuota/konfigurasi/terlalu besar tetap informatif; galat lain = foto tidak terbaca.
            throw in_array($e->category, [LlmException::SIBUK, LlmException::KUOTA, LlmException::KONFIGURASI, LlmException::TERLALU_BESAR], true)
                ? $e
                : LlmException::visionFailed($e);
        }

        $this->recordUsage($model, $response, $estimate, $log, 'token_vision');
        $text = $this->parser->sanitize($response->text, (int) config('ai.agent.max_input_chars', 2000));
        $log['panjang_hasil_baca'] = mb_strlen($text);

        if ($text === '') {
            throw LlmException::visionFailed();
        }

        return $text;
    }

    /**
     * Catat pemakaian token (angka saja, tanpa isi prompt) ke log interaksi dan pelindung token global.
     *
     * @param  array<string, mixed>  $log
     */
    private function recordUsage(string $model, LlmResponse $response, int $estimate, array &$log, string $key = 'token'): void
    {
        $used = $response->inputTokens + $response->outputTokens;
        $this->budget->record($model, $used > 0 ? $used : $estimate);

        $log[$key] = [
            'masuk' => (int) ($log[$key]['masuk'] ?? 0) + $response->inputTokens,
            'keluar' => (int) ($log[$key]['keluar'] ?? 0) + $response->outputTokens,
            'estimasi' => (int) ($log[$key]['estimasi'] ?? 0) + $estimate,
        ];
    }

    private function withinRateLimit(User $user): bool
    {
        $id = $user->getKey();
        $perMinute = max(1, (int) config('ai.rate_limit.per_minute', 20));
        $perHour = max(1, (int) config('ai.rate_limit.per_hour', 30));

        if (RateLimiter::tooManyAttempts("ai:m:{$id}", $perMinute) || RateLimiter::tooManyAttempts("ai:h:{$id}", $perHour)) {
            return false;
        }

        RateLimiter::hit("ai:m:{$id}", 60);
        RateLimiter::hit("ai:h:{$id}", 3600);

        return true;
    }

    /** Teks polos aman: tanpa karakter kontrol, dipotong ke batas keluaran. */
    private function cleanOutput(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = trim($text);
        $max = (int) config('ai.agent.max_output_chars', 3500);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    /**
     * @param  array<string, mixed>  $log
     */
    private function finish(AiInteraction $interaction, float $started, array $log, HasilAi $hasil, ?string $error, AiReply $reply): AiReply
    {
        $names = array_values(array_unique(array_column($log['tools'], 'nama')));

        $interaction->forceFill([
            'tool_name' => $names === [] ? null : mb_substr(implode(',', $names), 0, 100),
            'argumen' => $log,
            'latensi_ms' => (int) round((microtime(true) - $started) * 1000),
            'hasil' => $hasil,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
        ])->save();

        return $reply;
    }
}

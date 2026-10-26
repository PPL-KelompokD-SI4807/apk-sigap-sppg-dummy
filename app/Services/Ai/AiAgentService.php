<?php

namespace App\Services\Ai;

use App\Enums\HasilAi;
use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\Drafts\DraftLimitException;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\ToolCall;
use App\Services\Ai\Tools\ArgumentValidator;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolException;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Orkestrator Agen AI (FR-11). Alur per pesan:
 *   1. pengguna aktif + rate limit (per menit & per jam, NFR-12);
 *   2. masukan dibersihkan, dipotong, dibungkus sebagai data tak tepercaya (IntentParser);
 *   3. loop tool-calling maks config ai.agent.max_iterations; tiap tool call:
 *      nama dikenal? -> tool milik peran pengguna? -> argumen terlindungi dibuang ->
 *      skema argumen valid? -> eksekusi (baca: langsung ber-scope; tulis: hanya AiDraft);
 *   4. jawaban teks polos dipotong; seluruh jejak dicatat di ai_interactions (tanpa isi pesan).
 * LLM gagal/tidak tersedia/terlalu lama -> fallback formulir manual.
 */
class AiAgentService
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolRegistry $registry,
        private readonly IntentParser $parser,
        private readonly ArgumentValidator $validator,
    ) {}

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
            return $this->finish($interaction, $started, $log, HasilAi::Fallback, $e->getMessage(), new AiReply(
                'Agen AI sedang tidak tersedia. Silakan isi lewat formulir di Mini App.',
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
        $tools = $this->registry->forUser($user);
        $schemas = $this->registry->schemasFor($user);
        $nonce = $this->parser->nonce();
        $system = $this->parser->systemPrompt($user, array_keys($tools), $nonce);
        $messages = [$this->parser->userMessage($input, $text, $nonce)];
        $context = new ToolContext($interaction->id, $input->type);

        $maxIterations = max(1, (int) config('ai.agent.max_iterations', 4));
        $maxCalls = max(1, (int) config('ai.agent.max_tool_calls_per_turn', 5));
        $budget = (float) config('ai.agent.time_budget_seconds', 25);
        $drafts = [];
        $final = null;

        for ($i = 0; $i < $maxIterations; $i++) {
            if (microtime(true) - $started > $budget) {
                break;
            }

            $log['iterasi'] = $i + 1;
            $response = $this->llm->chat($messages, $schemas, ['system' => $system]);

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
                    ? $this->runTool($user, $call, $context, $log)
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
     */
    private function runTool(User $user, ToolCall $call, ToolContext $context, array &$log): ToolResult
    {
        try {
            $tool = $this->registry->resolveFor($user, $call->name);
        } catch (ToolException $e) {
            Log::warning('ai.tool_ditolak', ['user_id' => $user->getKey(), 'alasan' => $e->reason, 'tool' => mb_substr($call->name, 0, 64)]);

            return $this->record($log, $call->name, $e->reason === ToolException::UNKNOWN
                ? ToolResult::invalid('Tool tidak dikenal.')
                : ToolResult::denied(), []);
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

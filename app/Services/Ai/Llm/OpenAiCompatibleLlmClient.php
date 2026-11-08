<?php

namespace App\Services\Ai\Llm;

use App\Support\HttpsEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use stdClass;
use Throwable;

/**
 * LlmClient untuk API Chat Completions kompatibel OpenAI (target utama Groq; juga DeepSeek dan
 * penyedia lain dengan format yang sama). Dipilih lewat LLM_PROVIDER=openai_compat.
 *
 * Pemetaan format ternormalisasi LlmClient -> Chat Completions (D3):
 *   system (opsi)            -> {role: system, content}
 *   user {content}           -> {role: user, content: string} (gambar TIDAK lewat chat(), lihat readImages())
 *   assistant {tool_calls}   -> {role: assistant, content|null, tool_calls: [{id, type: function,
 *                                function: {name, arguments: JSON string}}]}
 *   tool {tool_call_id, ...} -> {role: tool, tool_call_id, content}; is_error ditandai DI DALAM konten
 *                                yang sudah dibungkus nonce oleh AiAgentService (pembungkus tidak diubah)
 *   tool schema              -> {type: function, function: {name, description, parameters}}
 * Balasan: choices[0].message -> LlmResponse. arguments di-json_decode; JSON rusak / bukan objek ->
 * ToolCall::argumentsValid=false (agen menolak, tool tidak dijalankan). Field reasoning /
 * reasoning_content (gpt-oss, DeepSeek) dan blok <think> dibuang: tidak diteruskan, tidak di-log.
 *
 * Keamanan (D2, D5):
 * - URL hanya dari config (LLM_BASE_URL, TANPA default): wajib https, tanpa userinfo/query; path tetap
 *   {base_url}/chat/completions; redirect tidak diikuti. Config tidak valid -> LlmException::notConfigured()
 *   tanpa mengirim permintaan.
 * - Kunci hanya di header Authorization: Bearer. LlmException tidak memuat kunci, header, isi pesan,
 *   maupun body respons penyedia (hanya kategori + status HTTP + ringkasan tersaring infoGalat(): kode/tipe galat,
 *   nama tool yang dikirim, JSON pointer properti skema; tanpa error.message mentah dan failed_generation).
 * - Retry maks 1 untuk koneksi/429/5xx; Retry-After dihormati hanya bila <= retry_after_max_seconds dan
 *   masih dalam anggaran waktu per pesan (opsi `deadline` dari AiAgentService, default time_budget_seconds).
 * - Hanya parameter umum yang dikirim (model, messages, max_tokens, tools); tanpa parameter khusus vendor.
 */
class OpenAiCompatibleLlmClient implements LlmClient, SupportsVision
{
    public const PATH = '/chat/completions';

    public const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/';

    /** Batas panjang argumen tool mentah dari penyedia (byte) sebelum di-decode. */
    private const MAX_RAW_ARGUMENTS = 65536;

    /** Batas jumlah tool call yang diterima dari satu respons (sisanya dibuang). */
    private const MAX_TOOL_CALLS = 20;

    /** Batas body galat penyedia (byte) yang masih diringkas; lebih besar = tidak dibaca. */
    private const MAX_ERROR_BODY = 32768;

    /** Sisa anggaran waktu minimum (detik) agar percobaan ulang setelah galat koneksi masih layak. */
    private const MIN_RETRY_WINDOW = 5.0;

    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $apiKey,
        private readonly ?string $model,
        private readonly ?string $visionModel = null,
        private readonly int $maxTokens = 1024,
        private readonly int $visionMaxTokens = 800,
        private readonly int $visionMaxBase64Bytes = 4 * 1024 * 1024,
        private readonly int $timeout = 20,
        private readonly int $connectTimeout = 5,
        private readonly int $retries = 1,
        private readonly int $retrySleepMs = 500,
        private readonly int $retryAfterMaxSeconds = 3,
        private readonly int $timeBudgetSeconds = 25,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('ai.openai_compat');
        $str = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return new self(
            baseUrl: $str($c['base_url'] ?? null),
            apiKey: $str($c['api_key'] ?? null),
            model: $str($c['model'] ?? null),
            visionModel: $str($c['vision_model'] ?? null),
            maxTokens: max(1, (int) ($c['max_tokens'] ?? 1024)),
            visionMaxTokens: max(1, (int) ($c['vision_max_tokens'] ?? 800)),
            visionMaxBase64Bytes: max(1, (int) ($c['vision_max_base64_bytes'] ?? 4 * 1024 * 1024)),
            timeout: max(1, (int) ($c['timeout'] ?? 20)),
            connectTimeout: max(1, (int) ($c['connect_timeout'] ?? 5)),
            retries: (int) ($c['retries'] ?? 1),
            retrySleepMs: max(0, (int) ($c['retry_sleep_ms'] ?? 500)),
            retryAfterMaxSeconds: max(0, (int) ($c['retry_after_max_seconds'] ?? 3)),
            timeBudgetSeconds: max(1, (int) config('ai.agent.time_budget_seconds', 25)),
        );
    }

    public function modelName(): string
    {
        return $this->model !== null && preg_match(self::MODEL_PATTERN, $this->model) === 1 ? $this->model : 'openai_compat';
    }

    public function supportsVision(): bool
    {
        return $this->visionModel !== null && preg_match(self::MODEL_PATTERN, $this->visionModel) === 1;
    }

    public function visionModelName(): ?string
    {
        return $this->supportsVision() ? $this->visionModel : null;
    }

    /** True bila base_url https sah, kunci dan model terisi (tanpa memanggil jaringan). */
    public function isConfigured(?string $model = null): bool
    {
        $model ??= $this->model;

        return HttpsEndpoint::valid($this->baseUrl)
            && $this->apiKey !== null && $this->apiKey !== ''
            && $model !== null && preg_match(self::MODEL_PATTERN, $model) === 1;
    }

    public function chat(array $messages, array $tools, array $options = []): LlmResponse
    {
        if (! $this->isConfigured()) {
            throw LlmException::notConfigured();
        }

        $system = isset($options['system']) && is_string($options['system']) ? $options['system'] : null;

        $body = [
            'model' => (string) $this->model,
            'messages' => $this->convertMessages($messages, $system),
            'max_tokens' => max(1, (int) ($options['max_tokens'] ?? $this->maxTokens)),
        ];

        if ($tools !== []) {
            $body['tools'] = $this->convertTools($tools);
        }

        return $this->parse($this->send($body, $options), (string) $this->model);
    }

    public function readImages(array $images, string $instruction, array $options = []): LlmResponse
    {
        if (! $this->supportsVision()) {
            throw LlmException::visionUnsupported();
        }

        if (! $this->isConfigured($this->visionModel)) {
            throw LlmException::notConfigured();
        }

        $allowed = (array) config('ai.input.photo_mimes', ['image/jpeg', 'image/png', 'image/webp']);
        // Satu gambar per pesan (AiChatHandler hanya mengirim satu); membatasi biaya token gambar.
        $images = array_slice(array_values($images), 0, 1);
        $parts = [['type' => 'text', 'text' => 'Baca gambar terlampir sesuai instruksi sistem.']];

        foreach ($images as $image) {
            $mime = is_array($image) ? (string) ($image['media_type'] ?? '') : '';
            $data = is_array($image) ? (string) ($image['data'] ?? '') : '';

            if (! in_array($mime, $allowed, true) || $data === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $data) !== 1) {
                throw LlmException::visionFailed();
            }

            if (strlen($data) > $this->visionMaxBase64Bytes) {
                throw LlmException::tooLarge('gambar_base64');
            }

            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.$data]];
        }

        if (count($parts) < 2) {
            throw LlmException::visionFailed();
        }

        $body = [
            'model' => (string) $this->visionModel,
            'messages' => [
                ['role' => 'system', 'content' => $instruction],
                ['role' => 'user', 'content' => $parts],
            ],
            'max_tokens' => max(1, (int) ($options['max_tokens'] ?? $this->visionMaxTokens)),
        ];

        $response = $this->parse($this->send($body, $options), (string) $this->visionModel);

        // Model vision dipanggil tanpa tool: tool call apa pun dari model vision diabaikan.
        return new LlmResponse($response->text, [], $response->stopReason, $response->model, $response->inputTokens, $response->outputTokens);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function convertMessages(array $messages, ?string $system = null): array
    {
        $out = [];

        if ($system !== null && $system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';

            if ($role === 'tool') {
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($m['tool_call_id'] ?? ''),
                    'content' => $this->markError((string) ($m['content'] ?? ''), (bool) ($m['is_error'] ?? false)),
                ];

                continue;
            }

            if ($role === 'assistant') {
                $content = is_string($m['content'] ?? null) && trim($m['content']) !== '' ? $m['content'] : null;
                $calls = [];

                foreach ($m['tool_calls'] ?? [] as $call) {
                    $args = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
                    $calls[] = [
                        'id' => (string) $call['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => (string) $call['name'],
                            'arguments' => (string) json_encode($args === [] ? new stdClass : $args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                        ],
                    ];
                }

                $msg = ['role' => 'assistant', 'content' => $content ?? ($calls === [] ? '' : null)];

                if ($calls !== []) {
                    $msg['tool_calls'] = $calls;
                }

                $out[] = $msg;

                continue;
            }

            // Peran lain (termasuk 'system' palsu di riwayat) selalu diperlakukan sebagai pengguna.
            if (($m['images'] ?? []) !== []) {
                // Gambar wajib lewat readImages() (model vision terpisah, tanpa tool).
                throw new LlmException('Gambar harus dibaca lewat model vision.', LlmException::GAMBAR_TIDAK_DIDUKUNG, null, 'gambar_di_chat');
            }

            $out[] = ['role' => 'user', 'content' => (string) ($m['content'] ?? '')];
        }

        return $out;
    }

    /**
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     * @return list<array<string, mixed>>
     */
    public function convertTools(array $tools): array
    {
        return array_values(array_map(fn (array $t) => [
            'type' => 'function',
            'function' => [
                'name' => (string) $t['name'],
                'description' => (string) $t['description'],
                'parameters' => self::normalizeSchema(is_array($t['input_schema'] ?? null) && $t['input_schema'] !== []
                    ? $t['input_schema']
                    : ['type' => 'object', 'properties' => []]),
            ],
        ], $tools));
    }

    /**
     * Argumen tool dari penyedia: string JSON (format OpenAI) atau array (sebagian penyedia).
     * Hanya objek JSON yang sah; selain itu argumentsValid=false dan tool tidak dijalankan.
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public static function decodeArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw === [] || ! array_is_list($raw) ? [$raw, true] : [[], false];
        }

        if (! is_string($raw)) {
            return [[], false];
        }

        $raw = trim($raw);

        if ($raw === '') {
            return [[], true];
        }

        if (strlen($raw) > self::MAX_RAW_ARGUMENTS) {
            return [[], false];
        }

        $object = json_decode($raw, false, 32);

        if (! $object instanceof stdClass) {
            return [[], false];
        }

        $array = json_decode($raw, true, 32);

        return is_array($array) ? [$array, true] : [[], false];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $options
     */
    private function send(array $body, array $options): Response
    {
        $deadline = isset($options['deadline']) && is_numeric($options['deadline'])
            ? (float) $options['deadline']
            : microtime(true) + $this->timeBudgetSeconds;
        $perCall = max(1, min($this->timeout, (int) ($options['timeout'] ?? $this->timeout)));
        $attempts = 1 + max(0, min(1, $this->retries));
        $url = rtrim((string) $this->baseUrl, '/').self::PATH;

        for ($attempt = 1; ; $attempt++) {
            $remaining = $deadline - microtime(true);

            if ($remaining < 1) {
                throw LlmException::timeout();
            }

            $timeout = max(1, min($perCall, (int) floor($remaining)));

            try {
                $response = Http::withToken((string) $this->apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->withoutRedirecting()
                    ->timeout($timeout)
                    ->connectTimeout(min($this->connectTimeout, $timeout))
                    ->post($url, $body);
            } catch (ConnectionException) {
                if ($attempt < $attempts && $deadline - microtime(true) >= self::MIN_RETRY_WINDOW) {
                    Sleep::for($this->retrySleepMs)->milliseconds();

                    continue;
                }

                throw LlmException::timeout();
            } catch (Throwable $e) {
                // Pesan exception asli tidak diteruskan (bisa memuat URL/header).
                throw new LlmException('Permintaan ke layanan LLM gagal ('.class_basename($e).').', LlmException::TIDAK_TERSEDIA, null, 'klien');
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $retryAfter = $status === 429 ? self::retryAfterSeconds($response) : null;

            if (($status === 429 || $status >= 500) && $attempt < $attempts) {
                $wait = $retryAfter ?? $this->retrySleepMs / 1000;

                if ($wait <= $this->retryAfterMaxSeconds && microtime(true) + $wait + 1 < $deadline) {
                    Sleep::for((int) ceil($wait * 1000))->milliseconds();

                    continue;
                }
            }

            // Body galat penyedia hanya diringkas (kode/tipe/path; bisa memuat potongan prompt -> tidak disimpan mentah).
            throw LlmException::fromProvider($status, $retryAfter, self::infoGalat($response, is_array($body['tools'] ?? null) ? $body['tools'] : []));
        }
    }

    /**
     * Ringkasan galat penyedia yang AMAN untuk log dan catatan perbaikan ke model: hanya `error.code` dan `error.type`
     * (pola ketat), dan khusus `tool_use_failed`: nama tool (hanya bila termasuk tool yang DIKIRIM), JSON pointer
     * argumen yang ditolak (setiap segmen harus nama properti skema tool itu atau indeks), dan kode masalah dari
     * kosakata tetap. `error.message` mentah dan `failed_generation` (memuat keluaran model = isi pesan/argumen
     * pengguna) TIDAK pernah disimpan, di-log, atau dikirim balik. Satu-satunya turunan failed_generation: `argumen` =
     * objek argumen tool yang ditolak (hanya bila nama tool = tool yang dikenali di atas), untuk jalur draf "perlu
     * diperbaiki" setelah percobaan ulang gagal; argumen itu tetap melewati pipeline tool biasa (skema, Form Request).
     *
     * @param  list<array<string, mixed>>  $tools  tool format Chat Completions pada permintaan yang gagal
     * @return array{kode?: string, tipe?: string, tool?: string, masalah?: list<string>, jalur?: list<string>, argumen?: array<string, mixed>}
     */
    public static function infoGalat(Response $response, array $tools = []): array
    {
        $raw = $response->body();

        if ($raw === '' || strlen($raw) > self::MAX_ERROR_BODY) {
            return [];
        }

        $json = json_decode($raw, true, 16);
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        if (! is_array($error)) {
            return [];
        }

        $info = array_filter([
            'kode' => self::kodeAman($error['code'] ?? null),
            'tipe' => self::kodeAman($error['type'] ?? null),
        ]);

        if (($info['kode'] ?? null) !== LlmException::KODE_TOOL_GAGAL || ! is_string($error['message'] ?? null)) {
            return $info;
        }

        $pesan = substr($error['message'], 0, 4000);
        $skema = [];

        foreach ($tools as $t) {
            $fn = is_array($t['function'] ?? null) ? $t['function'] : [];

            if (is_string($fn['name'] ?? null)) {
                $skema[$fn['name']] = is_array($fn['parameters'] ?? null) ? $fn['parameters'] : [];
            }
        }

        $tool = preg_match('/\bfor tool [`\'"]?([A-Za-z0-9_.-]{1,64})/', $pesan, $m) === 1 && isset($skema[$m[1]]) ? $m[1] : null;

        $masalah = [];

        foreach ([
            'tool_tak_ditawarkan' => '/not in request\.tools|unknown tool|tool .* does not exist/i',
            'tipe' => '/expected [a-z]+.{0,20}but got|invalid type/i',
            'wajib' => '/missing propert|is required/i',
            'tak_dikenal' => '/additional ?propert|not allowed/i',
            'enum' => '/must be one of|\benum\b/i',
            'batas' => '/minimum|maximum|min(?:Length|Items)|max(?:Length|Items)|less than|greater than|too (?:long|short|many|few)/i',
            'format' => '/failed to (?:call|parse)|invalid json|malformed/i',
        ] as $kode => $pola) {
            if (preg_match($pola, $pesan) === 1) {
                $masalah[] = $kode;
            }
        }

        $info['masalah'] = $masalah === [] ? ['lain'] : $masalah;

        if ($tool !== null) {
            $info['tool'] = $tool;
            preg_match_all('#(?<![A-Za-z0-9_])(/[A-Za-z0-9_]{1,64}(?:/[A-Za-z0-9_]{1,64}){0,5})#', $pesan, $semua);
            $jalur = $semua[1];

            // "missing properties: 'a', 'b'" -> /a, /b.
            if (preg_match('/missing propert[a-z]*:?\s*((?:[`\'"][A-Za-z0-9_]{1,64}[`\'"][,\s]*){1,10})/i', $pesan, $mm) === 1) {
                preg_match_all('/[A-Za-z0-9_]{1,64}/', $mm[1], $wajib);

                foreach ($wajib[0] as $w) {
                    $jalur[] = '/'.$w;
                }
            }

            // Segmen pertama = nama properti skema tool; indeks hanya \d{1,2} (deret angka panjang/NIK dibuang).
            $jalur = JalurSkema::saring($jalur, JalurSkema::namaProperti($skema[$tool]));

            if ($jalur !== []) {
                $info['jalur'] = $jalur;
            }

            $argumen = self::argumenGagal($error['failed_generation'] ?? null, $tool);

            if ($argumen !== null) {
                $info['argumen'] = $argumen;
            }
        }

        return $info;
    }

    /**
     * Objek argumen dari failed_generation (`{"name": ..., "arguments": {...}|"<json>"}`, boleh dibungkus larik/`tool_calls`)
     * HANYA untuk tool $tool. Bentuk lain / nama lain = null.
     *
     * @return array<string, mixed>|null
     */
    private static function argumenGagal(mixed $mentah, string $tool): ?array
    {
        if (! is_string($mentah) || $mentah === '' || strlen($mentah) > 16000) {
            return null;
        }

        $json = json_decode($mentah, true, 8);
        $calon = is_array($json) && array_is_list($json) ? ($json[0] ?? null) : $json;
        $calon = is_array($calon['tool_calls'][0] ?? null) ? $calon['tool_calls'][0] : $calon;
        $calon = is_array($calon['function'] ?? null) ? $calon['function'] : $calon;

        if (! is_array($calon) || ($calon['name'] ?? null) !== $tool) {
            return null;
        }

        $argumen = $calon['arguments'] ?? $calon['parameters'] ?? null;
        $argumen = is_string($argumen) ? json_decode($argumen, true, 8) : $argumen;

        return is_array($argumen) && ($argumen === [] || ! array_is_list($argumen)) ? $argumen : null;
    }

    private static function kodeAman(mixed $nilai): ?string
    {
        return is_string($nilai) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $nilai) === 1 ? strtolower($nilai) : null;
    }

    private function parse(Response $response, string $model): LlmResponse
    {
        $json = $response->json();
        $message = is_array($json) ? ($json['choices'][0]['message'] ?? null) : null;

        if (! is_array($message)) {
            throw LlmException::invalidResponse();
        }

        $calls = [];
        $seen = [];

        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            if (count($calls) >= self::MAX_TOOL_CALLS) {
                break;
            }

            $fn = is_array($call) ? ($call['function'] ?? null) : null;

            if (! is_array($fn) || ! is_string($fn['name'] ?? null) || $fn['name'] === ''
                || (isset($call['type']) && $call['type'] !== 'function')) {
                continue;
            }

            $id = is_string($call['id'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $call['id']) === 1 && ! isset($seen[$call['id']])
                ? $call['id']
                : 'call_'.bin2hex(random_bytes(6));
            $seen[$id] = true;

            [$arguments, $valid] = self::decodeArguments($fn['arguments'] ?? null);
            $calls[] = new ToolCall($id, mb_substr($fn['name'], 0, 64), $arguments, $valid);
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $finish = $json['choices'][0]['finish_reason'] ?? null;

        return new LlmResponse(
            text: self::contentText($message['content'] ?? null),
            toolCalls: $calls,
            stopReason: is_string($finish) ? mb_substr($finish, 0, 32) : null,
            model: is_string($json['model'] ?? null) ? mb_substr($json['model'], 0, 100) : $model,
            inputTokens: max(0, (int) ($usage['prompt_tokens'] ?? 0)),
            outputTokens: max(0, (int) ($usage['completion_tokens'] ?? 0)),
        );
    }

    /** Teks jawaban tanpa penalaran model (<think>...</think>); field reasoning tidak pernah dibaca. */
    private static function contentText(mixed $content): string
    {
        if (is_array($content)) {
            $content = implode("\n", array_map(
                fn ($part) => is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null) ? $part['text'] : '',
                $content,
            ));
        }

        if (! is_string($content)) {
            return '';
        }

        $content = (string) preg_replace('#<think>.*?</think>#is', '', $content);
        $open = stripos($content, '<think>');

        if ($open !== false) {
            $content = substr($content, 0, $open);
        }

        return trim($content);
    }

    /** Tandai hasil tool galat di DALAM blok bernonce (OpenAI tidak punya is_error). */
    private function markError(string $content, bool $isError): string
    {
        if (! $isError) {
            return $content;
        }

        if (preg_match('/^<hasil_tool_[0-9a-f]+>\n/', $content, $m) === 1) {
            return $m[0].'[galat_tool] '.substr($content, strlen($m[0]));
        }

        return '[galat_tool] '.$content;
    }

    /** Detik dari header Retry-After (angka atau tanggal HTTP); null bila tidak ada/tidak sah. */
    private static function retryAfterSeconds(Response $response): ?float
    {
        $value = trim($response->header('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        $at = strtotime($value);

        return $at === false ? null : (float) max(0, $at - time());
    }

    /**
     * JSON Schema: 'properties' kosong harus objek ({}), bukan array ([]), agar diterima penyedia.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function normalizeSchema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && $value === []) {
                $schema[$key] = new stdClass;
            } elseif (is_array($value)) {
                $schema[$key] = self::normalizeSchema($value);
            }
        }

        return $schema;
    }
}

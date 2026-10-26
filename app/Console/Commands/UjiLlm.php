<?php

namespace App\Console\Commands;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Services\Ai\IntentParser;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\SupportsVision;
use App\Services\Ai\Speech\SpeechToTextException;
use App\Services\Ai\Speech\SpeechToTextService;
use App\Services\Ai\TokenBudget;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolSelector;
use finfo;
use Illuminate\Console\Command;
use Throwable;

/**
 * Uji koneksi Agen AI tanpa membocorkan rahasia (D10). Output HANYA: nama provider/model (bukan
 * rahasia), balasan model yang dipotong, atau pesan galat ramah + kategori. Tidak pernah mencetak
 * kunci, URL lengkap, header, maupun body respons mentah.
 *
 *   php artisan sigap:llm-uji                      kirim "balas dengan kata: siap" (tanpa tool)
 *   php artisan sigap:llm-uji --stt=uji.ogg        transkripsi berkas audio uji (fiktif)
 *   php artisan sigap:llm-uji --vision=nota.jpg    baca berkas gambar uji (fiktif) lewat model vision
 *   php artisan sigap:llm-uji --ukur [--teks=..]   hitung estimasi token per peran (offline)
 */
class UjiLlm extends Command
{
    protected $signature = 'sigap:llm-uji
        {--stt= : Berkas audio uji (fiktif) untuk STT}
        {--vision= : Berkas gambar uji (fiktif) untuk model vision}
        {--ukur : Hitung estimasi token prompt + definisi tool per peran tanpa jaringan}
        {--teks= : Teks contoh untuk simulasi pemilihan tool (bersama --ukur)}';

    protected $description = 'Uji koneksi LLM/STT/vision Agen AI atau ukur token per peran, tanpa mencetak kunci.';

    private const MAX_PRINT = 300;

    public function handle(): int
    {
        if ($this->option('ukur')) {
            return $this->ukur();
        }

        if (is_string($this->option('stt')) && $this->option('stt') !== '') {
            return $this->stt((string) $this->option('stt'));
        }

        if (is_string($this->option('vision')) && $this->option('vision') !== '') {
            return $this->vision((string) $this->option('vision'));
        }

        return $this->chat();
    }

    private function chat(): int
    {
        $llm = app(LlmClient::class);
        $this->line('Provider: '.$this->provider().' | model: '.$llm->modelName());

        try {
            $response = $llm->chat(
                [['role' => 'user', 'content' => 'balas dengan kata: siap']],
                [],
                ['system' => 'Jawab hanya dengan satu kata yang diminta pengguna.', 'max_tokens' => 512],
            );
        } catch (LlmException $e) {
            return $this->gagal($e);
        }

        $this->info('Balasan: '.$this->clip($response->text === '' ? '(kosong)' : $response->text));
        $this->line('Token: masuk '.$response->inputTokens.', keluar '.$response->outputTokens);

        return self::SUCCESS;
    }

    private function stt(string $path): int
    {
        $stt = app(SpeechToTextService::class);

        if (! $stt->isAvailable()) {
            $this->error('STT belum dikonfigurasi (STT_DRIVER=http dan STT_URL https wajib diisi).');

            return self::FAILURE;
        }

        $data = $this->bacaBerkas($path, (int) config('ai.input.voice_max_bytes', 10 * 1024 * 1024));

        if ($data === null) {
            return self::FAILURE;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: '';

        if (! str_starts_with($mime, 'audio/') && ! in_array($mime, ['application/ogg', 'video/ogg', 'video/webm', 'video/mp4'], true)) {
            $this->error('Berkas bukan audio yang didukung.');

            return self::FAILURE;
        }

        try {
            $text = $stt->transcribe($data, $mime, 'uji.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'ogg'));
        } catch (SpeechToTextException $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Transkrip: '.$this->clip($text));

        return self::SUCCESS;
    }

    private function vision(string $path): int
    {
        $llm = app(LlmClient::class);

        if (! $llm instanceof SupportsVision) {
            $this->warn('Provider aktif tidak memakai model vision terpisah; gambar dikirim langsung ke model teks.');

            return self::FAILURE;
        }

        if (! $llm->supportsVision()) {
            $this->warn('Vision tidak didukung (LLM_VISION_MODEL kosong). Foto di bot dibalas dengan arahan ke formulir manual.');

            return self::FAILURE;
        }

        $data = $this->bacaBerkas($path, (int) config('ai.input.photo_max_bytes', 5 * 1024 * 1024));

        if ($data === null) {
            return self::FAILURE;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: '';

        if (! in_array($mime, (array) config('ai.input.photo_mimes', ['image/jpeg', 'image/png', 'image/webp']), true)) {
            $this->error('Format gambar tidak didukung (hanya JPEG, PNG, atau WebP).');

            return self::FAILURE;
        }

        $this->line('Model vision: '.$llm->visionModelName());

        try {
            $response = $llm->readImages([['media_type' => $mime, 'data' => base64_encode($data)]], app(IntentParser::class)->visionInstruction());
        } catch (LlmException $e) {
            return $this->gagal($e);
        }

        $this->info('Hasil baca: '.$this->clip($response->text === '' ? '(kosong)' : $response->text));

        return self::SUCCESS;
    }

    /** Offline: tidak memanggil penyedia; pengguna contoh dibuat di memori (tidak disimpan). */
    private function ukur(): int
    {
        $registry = app(ToolRegistry::class);
        $selector = app(ToolSelector::class);
        $parser = app(IntentParser::class);
        $teks = is_string($this->option('teks')) ? (string) $this->option('teks') : '';
        $rows = [];

        foreach ($this->penggunaContoh() as $label => $user) {
            try {
                $allowed = $registry->forUser($user);
                $schemas = ToolSelector::schemas($allowed, false);
                $system = $parser->systemPromptText($label, 'SPPG Contoh', array_keys($allowed), str_repeat('0', 12));
                $toolChars = mb_strlen((string) json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $row = [
                    $label,
                    count($allowed),
                    $toolChars,
                    mb_strlen($system),
                    TokenBudget::estimate($schemas) + TokenBudget::estimate($system),
                ];

                if ($teks !== '') {
                    $forced = $this->withSelection(fn () => $selector->select($allowed, $teks));
                    $row[] = count($forced->tools);
                    $row[] = $forced->selectedTokens + TokenBudget::estimate($parser->systemPromptText($label, 'SPPG Contoh', array_keys($forced->tools), str_repeat('0', 12), $forced->otherModules));
                }

                $rows[] = $row;
            } catch (Throwable $e) {
                $rows[] = [$label, '-', '-', '-', 'tidak dapat diukur ('.class_basename($e).')'];
            }
        }

        $headers = ['Peran', 'Tool', 'Karakter tool', 'Karakter prompt', 'Estimasi token (penuh)'];

        if ($teks !== '') {
            $headers[] = 'Tool terpilih';
            $headers[] = 'Estimasi token (terpilih)';
        }

        $this->table($headers, $rows);
        $this->line(sprintf(
            'Estimasi ~karakter/4. Pemilihan tool: mode %s, aktif bila token tool > %d (maks %d tool / %d token). Pelindung token: %d/menit, %d/hari (0 = nonaktif).',
            (string) config('ai.tool_selection.mode', 'auto'),
            (int) config('ai.tool_selection.threshold_tokens', 2500),
            (int) config('ai.tool_selection.max_tools', 10),
            (int) config('ai.tool_selection.max_tool_tokens', 2500),
            (int) config('ai.token_budget.per_minute', 0),
            (int) config('ai.token_budget.per_day', 0),
        ));

        return self::SUCCESS;
    }

    /** @return array<string, User> */
    private function penggunaContoh(): array
    {
        $out = [];

        foreach (Role::cases() as $role) {
            $units = $role === Role::Relawan ? RelawanUnit::cases() : [null];

            foreach ($units as $unit) {
                $user = new User;
                $user->forceFill([
                    'name' => 'Pengguna Contoh',
                    'role' => $role,
                    'unit' => $unit,
                    'is_active' => true,
                    'sppg_id' => $role->tanpaSppgId() ? null : 1,
                ]);
                $user->id = 0;
                $out[$role->label().($unit !== null ? ' unit '.$unit->label() : '')] = $user;
            }
        }

        return $out;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function withSelection(callable $fn): mixed
    {
        $mode = config('ai.tool_selection.mode');
        $threshold = config('ai.tool_selection.threshold_tokens');
        config(['ai.tool_selection.mode' => 'on', 'ai.tool_selection.threshold_tokens' => 0]);

        try {
            return $fn();
        } finally {
            config(['ai.tool_selection.mode' => $mode, 'ai.tool_selection.threshold_tokens' => $threshold]);
        }
    }

    private function bacaBerkas(string $path, int $max): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Berkas tidak ditemukan atau tidak dapat dibaca.');

            return null;
        }

        $size = filesize($path);

        if ($size === false || $size === 0 || $size > $max) {
            $this->error('Ukuran berkas tidak valid (maksimal '.intdiv($max, 1024 * 1024).' MB).');

            return null;
        }

        $data = file_get_contents($path);

        return $data === false ? null : $data;
    }

    private function gagal(LlmException $e): int
    {
        $this->error('Gagal: '.$e->friendlyMessage());
        $this->line('Kategori: '.$e->logLabel());

        return self::FAILURE;
    }

    private function provider(): string
    {
        $provider = (string) config('ai.provider');

        return in_array($provider, ['anthropic', 'openai_compat', 'fake'], true) ? $provider : '(nonaktif)';
    }

    /** Teks polos satu baris, dipotong; tidak pernah memuat isi respons mentah selain teks jawaban. */
    private function clip(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

        return mb_strlen($text) > self::MAX_PRINT ? mb_substr($text, 0, self::MAX_PRINT - 1).'…' : $text;
    }
}

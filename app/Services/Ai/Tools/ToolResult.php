<?php

namespace App\Services\Ai\Tools;

use App\Models\AiDraft;

/**
 * Hasil eksekusi tool. Konten untuk model selalu JSON (diperlakukan model sebagai data).
 * status: ok | draft | invalid | ditolak | gagal.
 */
final class ToolResult
{
    public const OK = 'ok';

    public const DRAFT = 'draft';

    public const INVALID = 'invalid';

    public const DITOLAK = 'ditolak';

    public const GAGAL = 'gagal';

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly string $status,
        public readonly array $data = [],
        public readonly ?string $message = null,
        public readonly ?AiDraft $draft = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(array $data): self
    {
        return new self(self::OK, $data);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function draft(AiDraft $draft, array $errors = []): self
    {
        return new self(self::DRAFT, [
            'draf_dibuat' => true,
            'modul' => $draft->module,
            'aksi' => $draft->action->value,
            'perlu_dicek' => array_values(array_unique(array_map(fn ($k) => explode('.', (string) $k)[0], array_keys($errors)))),
            'catatan' => 'Data BELUM disimpan. Pengguna harus memeriksa dan menekan Simpan di Mini App.',
        ], null, $draft);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, [], $message);
    }

    public static function denied(string $message = 'Tool tidak tersedia untuk hak akses pengguna ini.'): self
    {
        return new self(self::DITOLAK, [], $message);
    }

    public static function failed(string $message = 'Tool gagal dijalankan.'): self
    {
        return new self(self::GAGAL, [], $message);
    }

    public function isError(): bool
    {
        return ! in_array($this->status, [self::OK, self::DRAFT], true);
    }

    /** JSON untuk model, dipotong ke $maxChars agar konteks tidak membengkak. */
    public function toModelContent(int $maxChars): string
    {
        $payload = $this->isError()
            ? ['status' => $this->status, 'pesan' => $this->message]
            : ['status' => $this->status, 'data' => $this->data];

        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (mb_strlen($json) > $maxChars) {
            $json = (string) json_encode([
                'status' => $this->status,
                'terpotong' => true,
                'data_parsial' => mb_substr($json, 0, max(0, $maxChars - 80)),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $json;
    }
}

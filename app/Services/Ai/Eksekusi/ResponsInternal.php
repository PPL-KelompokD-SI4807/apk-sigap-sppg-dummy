<?php

namespace App\Services\Ai\Eksekusi;

/**
 * Respons sub-request internal (status HTTP + isi JSON). Isi dapat memuat rahasia (token undangan): jangan
 * di-log atau disimpan; hanya diolah oleh tool pemilik (WriteDraftTool::olahHasil).
 */
final class ResponsInternal
{
    /** @param array<string, mixed>|null $json */
    public function __construct(
        public readonly int $status,
        #[\SensitiveParameter]
        public readonly ?array $json,
    ) {}

    public function sukses(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Pesan galat pertama dari server (pesan validasi/pesan galat API), bukan teks bebas pengguna. */
    public function pesanGalat(): ?string
    {
        $errors = is_array($this->json['errors'] ?? null) ? $this->json['errors'] : [];

        foreach ($errors as $daftar) {
            $pertama = is_array($daftar) ? ($daftar[0] ?? null) : $daftar;

            if (is_string($pertama) && $pertama !== '') {
                return mb_substr($pertama, 0, 300);
            }
        }

        $pesan = $this->json['message'] ?? null;

        return is_string($pesan) && $pesan !== '' ? mb_substr($pesan, 0, 300) : null;
    }

    /** @return list<string> */
    public function kunciGalat(): array
    {
        return is_array($this->json['errors'] ?? null) ? array_map('strval', array_keys($this->json['errors'])) : [];
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'json' => '[redacted]'];
    }
}

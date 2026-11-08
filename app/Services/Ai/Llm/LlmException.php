<?php

namespace App\Services\Ai\Llm;

use RuntimeException;

/**
 * Galat penyedia LLM (timeout, kuota, respons rusak, belum dikonfigurasi). Pesan aman untuk
 * dicatat: tidak memuat kunci API, isi percakapan, header, maupun body respons penyedia.
 *
 * - category: kode kategori stabil (lihat konstanta) untuk memilih balasan ramah;
 * - status: kode HTTP penyedia bila ada (angka saja);
 * - detail: kode singkat buatan sistem (mis. 'timeout', 'redirect'), bukan teks dari penyedia.
 * - kodePenyedia/tipePenyedia/tool/masalah/jalur: ringkasan galat penyedia yang disaring ketat (pola karakter
 *   terbatas; nama tool hanya bila termasuk tool yang dikirim); pesan mentah & failed_generation tidak disimpan.
 * Yang disimpan ke ai_interactions hanya logLabel() (kategori + status + detail); log aplikasi memakai konteksLog().
 */
class LlmException extends RuntimeException
{
    public const KONFIGURASI = 'konfigurasi';

    public const KUOTA = 'kuota';

    public const TERLALU_BESAR = 'terlalu_besar';

    public const SIBUK = 'sibuk';

    public const TIDAK_TERSEDIA = 'tidak_tersedia';

    public const RESPONS_RUSAK = 'respons_rusak';

    public const GAMBAR_TIDAK_DIDUKUNG = 'gambar_tidak_didukung';

    public const GAMBAR_GAGAL = 'gambar_gagal';

    /** Penyedia menolak tool call buatan model (Groq HTTP 400 `tool_use_failed`); agen boleh mengulang SEKALI. */
    public const TOOL_GAGAL = 'tool_gagal';

    public const KODE_TOOL_GAGAL = 'tool_use_failed';

    /**
     * @param  string|null  $kodePenyedia  error.code penyedia (mis. tool_use_failed, rate_limit_exceeded), pola [a-z0-9_.-]
     * @param  string|null  $tipePenyedia  error.type penyedia (mis. invalid_request_error), pola sama
     * @param  string|null  $tool  nama tool yang ditolak, HANYA bila termasuk tool yang dikirim pada permintaan
     * @param  list<string>  $masalah  kode masalah validasi buatan sistem (tipe, wajib, enum, batas, ...)
     * @param  list<string>  $jalur  JSON pointer argumen yang ditolak (mis. /bahan/0/bahan_baku_id), tanpa nilai
     */
    public function __construct(
        string $message = 'Layanan LLM tidak tersedia.',
        public readonly string $category = self::TIDAK_TERSEDIA,
        public readonly ?int $status = null,
        public readonly ?string $detail = null,
        public readonly ?string $kodePenyedia = null,
        public readonly ?string $tipePenyedia = null,
        public readonly ?string $tool = null,
        public readonly array $masalah = [],
        public readonly array $jalur = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Dari status HTTP + ringkasan galat penyedia yang SUDAH disaring (lihat OpenAiCompatibleLlmClient::infoGalat):
     * hanya kode/tipe/nama tool/path, tanpa pesan mentah, failed_generation, atau isi percakapan.
     *
     * @param  array{kode?: string|null, tipe?: string|null, tool?: string|null, masalah?: list<string>, jalur?: list<string>}  $info
     */
    public static function fromProvider(int $status, ?float $retryAfterSeconds, array $info): self
    {
        $dasar = self::fromStatus($status, $retryAfterSeconds);
        $kode = $info['kode'] ?? null;

        if ($status === 400 && $kode === self::KODE_TOOL_GAGAL) {
            return new self('Penyedia LLM menolak tool call model (HTTP 400).', self::TOOL_GAGAL, $status, self::KODE_TOOL_GAGAL,
                $kode, $info['tipe'] ?? null, $info['tool'] ?? null, $info['masalah'] ?? [], $info['jalur'] ?? []);
        }

        return new self($dasar->getMessage(), $dasar->category, $status, $dasar->detail,
            $kode, $info['tipe'] ?? null, $info['tool'] ?? null, $info['masalah'] ?? [], $info['jalur'] ?? []);
    }

    public function toolGagal(): bool
    {
        return $this->category === self::TOOL_GAGAL;
    }

    /**
     * Konteks log aman (Log::warning 'ai.llm_galat'): angka, kode, dan nama saja.
     *
     * @return array<string, mixed>
     */
    public function konteksLog(): array
    {
        return array_filter([
            'kategori' => $this->category,
            'status' => $this->status,
            'detail' => $this->detail,
            'kode_penyedia' => $this->kodePenyedia,
            'tipe_penyedia' => $this->tipePenyedia,
            'tool' => $this->tool,
            'masalah' => $this->masalah,
            'jalur' => $this->jalur,
            'kelas' => class_basename($this),
        ], fn ($v) => $v !== null && $v !== []);
    }

    public static function notConfigured(): self
    {
        return new self('Agen AI belum dikonfigurasi.', self::KONFIGURASI, null, 'belum_dikonfigurasi');
    }

    /** Kategori dari status HTTP penyedia (body respons sengaja tidak dibaca). */
    public static function fromStatus(int $status, ?float $retryAfterSeconds = null): self
    {
        $category = match (true) {
            $status === 401, $status === 403, $status === 404 => self::KONFIGURASI,
            $status === 402 => self::KUOTA,
            $status === 413 => self::TERLALU_BESAR,
            // Retry-After panjang pada 429 = batas harian penyedia habis, bukan lonjakan sesaat.
            $status === 429 => $retryAfterSeconds !== null && $retryAfterSeconds > 60 ? self::KUOTA : self::SIBUK,
            $status >= 300 && $status < 400 => self::TIDAK_TERSEDIA,
            default => self::TIDAK_TERSEDIA,
        };

        $detail = $status >= 300 && $status < 400 ? 'redirect' : null;

        return new self('Layanan LLM menolak permintaan (HTTP '.$status.').', $category, $status, $detail);
    }

    public static function timeout(): self
    {
        return new self('Layanan LLM tidak dapat dihubungi (timeout/koneksi).', self::TIDAK_TERSEDIA, null, 'timeout');
    }

    public static function invalidResponse(): self
    {
        return new self('Respons layanan LLM tidak dapat dibaca.', self::RESPONS_RUSAK, null, 'json');
    }

    /** Pelindung token global (bukan dari penyedia): sistem menolak sebelum memanggil penyedia. */
    public static function busy(string $detail = 'token_per_menit'): self
    {
        return new self('Pelindung token Agen AI aktif.', self::SIBUK, null, $detail);
    }

    public static function quota(string $detail = 'token_per_hari'): self
    {
        return new self('Kuota token Agen AI habis.', self::KUOTA, null, $detail);
    }

    public static function tooLarge(string $detail): self
    {
        return new self('Masukan terlalu besar untuk layanan LLM.', self::TERLALU_BESAR, null, $detail);
    }

    public static function visionUnsupported(): self
    {
        return new self('Membaca gambar tidak didukung penyedia aktif.', self::GAMBAR_TIDAK_DIDUKUNG, null, 'vision_kosong');
    }

    public static function visionFailed(?self $previous = null): self
    {
        return new self('Gambar tidak dapat dibaca.', self::GAMBAR_GAGAL, $previous?->status, $previous?->category ?? 'kosong');
    }

    /** Balasan untuk pengguna Telegram (teks polos, selalu mengarahkan ke formulir manual). */
    public function friendlyMessage(): string
    {
        return match ($this->category) {
            self::KONFIGURASI => 'Agen AI belum siap digunakan (konfigurasi). Hubungi Admin SPPG; sementara itu silakan isi lewat formulir di Mini App.',
            self::KUOTA => 'Kuota Agen AI sedang habis. Silakan isi lewat formulir di Mini App atau coba lagi nanti.',
            self::TERLALU_BESAR => 'Pesan atau gambar terlalu besar untuk Agen AI. Persingkat pesan atau isi lewat formulir di Mini App.',
            self::SIBUK => 'Agen AI sedang sibuk. Coba lagi sebentar lagi atau isi lewat formulir di Mini App.',
            self::GAMBAR_TIDAK_DIDUKUNG => 'Membaca foto belum didukung. Silakan ketik isinya atau isi lewat formulir di Mini App.',
            self::GAMBAR_GAGAL => 'Foto tidak dapat dibaca Agen AI. Silakan ketik isinya atau isi lewat formulir di Mini App.',
            self::TOOL_GAGAL => 'Agen AI belum dapat menyusun draf dari pesan ini. Coba tulis ulang lebih singkat (satu data per pesan) atau isi lewat formulir di Mini App.',
            default => 'Agen AI sedang tidak tersedia. Silakan isi lewat formulir di Mini App.',
        };
    }

    /** Label aman untuk kolom ai_interactions.error: kategori, status HTTP, dan kode detail saja. */
    public function logLabel(): string
    {
        return 'llm:'.$this->category
            .($this->status !== null ? ' http='.$this->status : '')
            .($this->detail !== null ? ' ('.$this->detail.')' : '');
    }
}

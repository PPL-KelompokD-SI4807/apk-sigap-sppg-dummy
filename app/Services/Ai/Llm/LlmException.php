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
 * Yang disimpan ke ai_interactions hanya logLabel() (kategori + status + detail).
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

    public function __construct(
        string $message = 'Layanan LLM tidak tersedia.',
        public readonly string $category = self::TIDAK_TERSEDIA,
        public readonly ?int $status = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
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

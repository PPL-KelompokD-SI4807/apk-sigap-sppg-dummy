<?php

namespace App\Services\Ai\Foto;

use RuntimeException;

/**
 * Foto chat ditolak sebelum dikirim ke penyedia AI. Pesan = teks ramah untuk pengguna (bahasa Indonesia, tanpa
 * detail teknis); $alasan = kode singkat untuk log (format, terlalu_besar, rusak, gd_tidak_ada).
 */
class FotoChatDitolak extends RuntimeException
{
    public const FORMAT = 'format';

    public const TERLALU_BESAR = 'terlalu_besar';

    public const RUSAK = 'rusak';

    public const TANPA_GD = 'gd_tidak_ada';

    public function __construct(string $message, public readonly string $alasan)
    {
        parent::__construct($message);
    }

    public static function format(): self
    {
        return new self('Format foto tidak didukung (hanya JPEG, PNG, atau WebP). Kirim sebagai foto biasa, bukan berkas lain.', self::FORMAT);
    }

    public static function terlaluBesar(): self
    {
        return new self('Foto terlalu besar (maksimal 5 MB). Kirim foto yang lebih kecil atau isi lewat formulir di Mini App.', self::TERLALU_BESAR);
    }

    /** Masih melebihi batas penyedia setelah kompresi bertahap. */
    public static function fileTerlaluBesar(): self
    {
        return new self('File foto terlalu besar untuk dibaca Agen AI walau sudah diperkecil. Kirim foto yang lebih sederhana atau isi lewat formulir di Mini App.', self::TERLALU_BESAR);
    }

    public static function rusak(): self
    {
        return new self('Foto rusak atau tidak dapat dibuka. Coba kirim ulang fotonya.', self::RUSAK);
    }

    public static function tanpaGd(): self
    {
        return new self('Membaca foto belum didukung di server ini. Silakan ketik isinya atau isi lewat formulir di Mini App.', self::TANPA_GD);
    }
}

<?php

namespace App\Services\Ai\Referensi;

use App\Models\Armada;
use App\Models\BahanBaku;
use App\Models\Menu;
use App\Models\Pemasok;
use App\Models\TitikLayanan;
use Illuminate\Database\Eloquent\Model;

/**
 * Konfigurasi satu jenis data master yang dapat dirujuk dengan NAMA oleh tool tulis Agen AI (ResolverReferensi).
 * Satu definisi per jenis: model, kolom nama yang dicocokkan, kolom satuan (opsional, ditampilkan di pilihan),
 * kolom SPPG untuk isolasi tenant, dan slug modul RBAC yang hak BACA-nya (R) wajib dimiliki pengguna.
 *
 * Tidak memuat jenis berisi nama orang (pengemudi, penerima manfaat, pengguna): nama orang tidak pernah
 * dikembalikan utuh ke LLM (UU PDP; lihat CatalogDefinitions::$initials).
 */
final class JenisReferensi
{
    /**
     * @param  class-string<Model>  $model
     * @param  string|null  $toolBuat  tool draf untuk menambah data master ini (ditawarkan saat nama tidak ditemukan)
     */
    public function __construct(
        public readonly string $kunci,
        public readonly string $label,
        public readonly string $model,
        public readonly string $kolomNama,
        public readonly ?string $kolomSatuan,
        public readonly string $modul,
        public readonly string $kolomSppg = 'sppg_id',
        public readonly ?string $toolBuat = null,
    ) {}

    /** @return array<string, self> */
    public static function semua(): array
    {
        return [
            'bahan_baku' => new self('bahan_baku', 'bahan baku', BahanBaku::class, 'nama', 'satuan', 'bahan-baku', toolBuat: 'buat_bahan_baku'),
            'pemasok' => new self('pemasok', 'pemasok', Pemasok::class, 'nama', null, 'pemasok', toolBuat: 'buat_pemasok'),
            'titik_layanan' => new self('titik_layanan', 'titik layanan', TitikLayanan::class, 'nama', null, 'titik-layanan', toolBuat: 'buat_titik_layanan'),
            'menu' => new self('menu', 'menu', Menu::class, 'nama_menu', null, 'menu-gizi', toolBuat: 'buat_menu'),
            'armada' => new self('armada', 'armada', Armada::class, 'plat_nomor', null, 'armada', toolBuat: 'buat_armada'),
        ];
    }

    public static function dari(mixed $kunci): ?self
    {
        return is_string($kunci) ? (self::semua()[$kunci] ?? null) : null;
    }

    public static function bahanBaku(): self
    {
        return self::semua()['bahan_baku'];
    }
}

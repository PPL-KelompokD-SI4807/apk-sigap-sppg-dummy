<?php

namespace App\Models;

use App\Enums\StatusOperasional;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sppg extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $table = 'sppgs';

    /** @var list<string> */
    protected array $searchable = ['nama', 'kode', 'alamat', 'mitra_yayasan'];

    /** @var list<string> */
    protected $fillable = [
        'kode',
        'nama',
        'id_sppg_bgn',
        'alamat',
        'kecamatan',
        'kab_kota',
        'provinsi',
        'mitra_yayasan',
        'nama_ketua_yayasan',
        'telepon',
        'no_va',
        'kapasitas_porsi_harian',
        'status_operasional',
        'acuan_bahan_baku_kecil',
        'acuan_bahan_baku_lainnya',
        'insentif_fasilitas_per_hari',
        'insentif_fasilitas_maks_hari_minggu',
    ];

    // saldo_awal dan saldo_awal_tanggal TIDAK mass-assignable: hanya SaldoAwalService (Akuntan/Admin + audit).

    /** Nilai bawaan pengaturan anggaran bila kolom kosong (Q-1 R-07). */
    public const ACUAN_KECIL_BAWAAN = 8000;

    public const ACUAN_LAINNYA_BAWAAN = 10000;

    public const INSENTIF_FASILITAS_BAWAAN = 6_000_000;

    public const MAKS_HARI_MINGGU_BAWAAN = 6;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kapasitas_porsi_harian' => 'integer',
            'status_operasional' => StatusOperasional::class,
            'saldo_awal' => 'decimal:2',
            'saldo_awal_tanggal' => 'date:Y-m-d',
            'acuan_bahan_baku_kecil' => 'integer',
            'acuan_bahan_baku_lainnya' => 'integer',
            'insentif_fasilitas_per_hari' => 'integer',
            'insentif_fasilitas_maks_hari_minggu' => 'integer',
        ];
    }

    /** @return array{acuan_bahan_baku_kecil: int, acuan_bahan_baku_lainnya: int, insentif_fasilitas_per_hari: int, insentif_fasilitas_maks_hari_minggu: int} nilai efektif (kolom kosong = bawaan) */
    public function pengaturanAnggaran(): array
    {
        return [
            'acuan_bahan_baku_kecil' => $this->acuan_bahan_baku_kecil ?? self::ACUAN_KECIL_BAWAAN,
            'acuan_bahan_baku_lainnya' => $this->acuan_bahan_baku_lainnya ?? self::ACUAN_LAINNYA_BAWAAN,
            'insentif_fasilitas_per_hari' => $this->insentif_fasilitas_per_hari ?? self::INSENTIF_FASILITAS_BAWAAN,
            'insentif_fasilitas_maks_hari_minggu' => $this->insentif_fasilitas_maks_hari_minggu ?? self::MAKS_HARI_MINGGU_BAWAAN,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function titikLayanans(): HasMany
    {
        return $this->hasMany(TitikLayanan::class);
    }

    public function pemasoks(): HasMany
    {
        return $this->hasMany(Pemasok::class);
    }

    public function bahanBakus(): HasMany
    {
        return $this->hasMany(BahanBaku::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function standarGizis(): HasMany
    {
        return $this->hasMany(StandarGizi::class);
    }

    public function jadwalProduksis(): HasMany
    {
        return $this->hasMany(JadwalProduksi::class);
    }

    public function jadwalDistribusis(): HasMany
    {
        return $this->hasMany(JadwalDistribusi::class);
    }

    public function armadas(): HasMany
    {
        return $this->hasMany(Armada::class);
    }

    public function pengemudis(): HasMany
    {
        return $this->hasMany(Pengemudi::class);
    }

    public function pencairanDanas(): HasMany
    {
        return $this->hasMany(PencairanDana::class);
    }

    public function pengeluarans(): HasMany
    {
        return $this->hasMany(Pengeluaran::class);
    }

    public function feedbackKeluhans(): HasMany
    {
        return $this->hasMany(FeedbackKeluhan::class);
    }

    public function rekapHarians(): HasMany
    {
        return $this->hasMany(RekapHarian::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status_operasional', StatusOperasional::Aktif->value);
    }
}

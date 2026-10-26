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
        'alamat',
        'mitra_yayasan',
        'telepon',
        'kapasitas_porsi_harian',
        'status_operasional',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kapasitas_porsi_harian' => 'integer',
            'status_operasional' => StatusOperasional::class,
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

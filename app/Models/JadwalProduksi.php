<?php

namespace App\Models;

use App\Enums\HasilMutu;
use App\Enums\StatusJadwalProduksi;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class JadwalProduksi extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'jadwal_produksis';

    /** @var list<string> */
    protected array $searchable = ['catatan'];

    /** @var list<string> */
    protected $fillable = [
        'menu_id',
        'dibuat_oleh',
        'tanggal_produksi',
        'jam_mulai_masak',
        'target_porsi',
        'status',
        'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_produksi' => 'date:Y-m-d',
            'target_porsi' => 'integer',
            'status' => StatusJadwalProduksi::class,
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function logProduksi(): HasOne
    {
        return $this->hasOne(LogProduksi::class);
    }

    public function pemeriksaanMutus(): HasMany
    {
        return $this->hasMany(PemeriksaanMutu::class);
    }

    public function jadwalDistribusis(): HasMany
    {
        return $this->hasMany(JadwalDistribusi::class);
    }

    public function feedbackKeluhans(): HasMany
    {
        return $this->hasMany(FeedbackKeluhan::class);
    }

    public function mutasiStoks(): HasMany
    {
        return $this->hasMany(MutasiStok::class);
    }

    public function scopeTanggal(Builder $query, string $tanggal): Builder
    {
        return $query->whereDate('tanggal_produksi', $tanggal);
    }

    /** Pemeriksaan mutu terbaru. */
    public function pemeriksaanMutuTerbaru(): ?PemeriksaanMutu
    {
        return $this->pemeriksaanMutus()->reorder()->orderByDesc('waktu_pemeriksaan')->orderByDesc('id')->first();
    }

    /** Gerbang distribusi: hanya layak bila pemeriksaan terbaru berhasil "Layak". */
    public function isLayakDistribusi(): bool
    {
        return $this->pemeriksaanMutuTerbaru()?->hasil === HasilMutu::Layak;
    }
}

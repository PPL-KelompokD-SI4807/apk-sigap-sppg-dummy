<?php

namespace App\Models;

use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\StatusKeluhan;
use App\Enums\SumberKeluhan;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedbackKeluhan extends Model
{
    use BelongsToSppg, HasFactory, HasLampiran, Searchable, SoftDeletes;

    protected $table = 'feedback_keluhans';

    /** @var list<string> */
    protected array $searchable = ['deskripsi', 'tindak_lanjut'];

    /** @var list<string> */
    protected $fillable = [
        'titik_layanan_id',
        'jadwal_produksi_id',
        'dicatat_oleh',
        'ditangani_oleh',
        'sumber',
        'kategori',
        'prioritas',
        'status',
        'deskripsi',
        'foto_path',
        'tindak_lanjut',
        'dieskalasi_pada',
        'selesai_pada',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sumber' => SumberKeluhan::class,
            'kategori' => KategoriKeluhan::class,
            'prioritas' => PrioritasKeluhan::class,
            'status' => StatusKeluhan::class,
            'dieskalasi_pada' => 'datetime',
            'selesai_pada' => 'datetime',
        ];
    }

    public function titikLayanan(): BelongsTo
    {
        return $this->belongsTo(TitikLayanan::class);
    }

    public function jadwalProduksi(): BelongsTo
    {
        return $this->belongsTo(JadwalProduksi::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    public function penangan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditangani_oleh');
    }

    public function scopeKritis(Builder $query): Builder
    {
        return $query->where('prioritas', PrioritasKeluhan::Kritis->value);
    }

    public function scopeTerbuka(Builder $query): Builder
    {
        return $query->whereIn('status', StatusKeluhan::terbukaValues());
    }
}

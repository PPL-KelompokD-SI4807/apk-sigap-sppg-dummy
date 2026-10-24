<?php

namespace App\Models;

use App\Enums\StatusJadwalDistribusi;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class JadwalDistribusi extends Model
{
    use Auditable, BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'jadwal_distribusis';

    /** @var list<string> */
    protected array $searchable = ['catatan'];

    /** @var list<string> */
    protected $fillable = [
        'jadwal_produksi_id',
        'titik_layanan_id',
        'armada_id',
        'pengemudi_id',
        'petugas_id',
        'tanggal_distribusi',
        'jam_berangkat',
        'jam_rencana_tiba',
        'jumlah_porsi',
        'status',
        'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_distribusi' => 'date:Y-m-d',
            'jumlah_porsi' => 'integer',
            'status' => StatusJadwalDistribusi::class,
        ];
    }

    public function jadwalProduksi(): BelongsTo
    {
        return $this->belongsTo(JadwalProduksi::class);
    }

    public function titikLayanan(): BelongsTo
    {
        return $this->belongsTo(TitikLayanan::class);
    }

    public function armada(): BelongsTo
    {
        return $this->belongsTo(Armada::class);
    }

    public function pengemudi(): BelongsTo
    {
        return $this->belongsTo(Pengemudi::class);
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function buktiDistribusi(): HasOne
    {
        return $this->hasOne(BuktiDistribusi::class);
    }

    public function notifikasiPerjalanans(): HasMany
    {
        return $this->hasMany(NotifikasiPerjalanan::class);
    }

    public function scopeTanggal(Builder $query, string $tanggal): Builder
    {
        return $query->whereDate('tanggal_distribusi', $tanggal);
    }
}

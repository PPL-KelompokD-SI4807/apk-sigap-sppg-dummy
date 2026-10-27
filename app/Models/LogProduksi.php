<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class LogProduksi extends Model
{
    use Auditable, BelongsToSppg, HasFactory, HasLampiran, Searchable, SoftDeletes;

    protected $table = 'log_produksis';

    /** @var list<string> */
    protected array $searchable = ['kendala', 'kode_batch'];

    /** @var list<string> */
    protected $fillable = [
        'jadwal_produksi_id',
        'dicatat_oleh',
        'kode_batch',
        'realisasi_porsi',
        'waktu_mulai',
        'sisa_makanan_kg',
        'jam_selesai',
        'kendala',
        'foto_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'realisasi_porsi' => 'integer',
            'sisa_makanan_kg' => 'decimal:2',
        ];
    }

    public function jadwalProduksi(): BelongsTo
    {
        return $this->belongsTo(JadwalProduksi::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    /**
     * Hanya log yang dicatat pengguna yang sedang login (ActingUser). Tanpa pengguna login tidak ada hasil
     * (fail closed). Dipakai filter `milik_saya` tool baca (layar Bot relawan: milik sendiri).
     *
     * @param  Builder<LogProduksi>  $query
     * @return Builder<LogProduksi>
     */
    public function scopeMilikSaya(Builder $query): Builder
    {
        $id = Auth::hasUser() ? Auth::id() : null;

        return $id === null ? $query->whereRaw('1 = 0') : $query->where($query->getModel()->qualifyColumn('dicatat_oleh'), $id);
    }
}

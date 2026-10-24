<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LogProduksi extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'log_produksis';

    /** @var list<string> */
    protected array $searchable = ['kendala'];

    /** @var list<string> */
    protected $fillable = [
        'jadwal_produksi_id',
        'dicatat_oleh',
        'realisasi_porsi',
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
}

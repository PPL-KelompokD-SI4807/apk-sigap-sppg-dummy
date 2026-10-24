<?php

namespace App\Models;

use App\Enums\KategoriPengeluaran;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pengeluaran extends Model
{
    use Auditable, BelongsToSppg, HasFactory, HasLampiran, Searchable, SoftDeletes;

    protected $table = 'pengeluarans';

    /** @var list<string> */
    protected array $searchable = ['uraian'];

    /** @var list<string> */
    protected $fillable = [
        'pencairan_dana_id',
        'pemasok_id',
        'dicatat_oleh',
        'tanggal',
        'kategori',
        'uraian',
        'nominal',
        'melebihi_anggaran',
        'nota_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'kategori' => KategoriPengeluaran::class,
            'nominal' => 'decimal:2',
            'melebihi_anggaran' => 'boolean',
        ];
    }

    public function pencairanDana(): BelongsTo
    {
        return $this->belongsTo(PencairanDana::class);
    }

    public function pemasok(): BelongsTo
    {
        return $this->belongsTo(Pemasok::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }
}

<?php

namespace App\Models;

use App\Enums\JenisMutasi;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MutasiStok extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'mutasi_stoks';

    /** @var list<string> */
    protected array $searchable = ['catatan'];

    /** @var list<string> */
    protected $fillable = [
        'bahan_baku_id',
        'jadwal_produksi_id',
        'pemasok_id',
        'dicatat_oleh',
        'jenis',
        'jumlah',
        'harga_satuan',
        'tanggal_mutasi',
        'tanggal_kedaluwarsa',
        'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'jenis' => JenisMutasi::class,
            'jumlah' => 'decimal:3',
            'harga_satuan' => 'decimal:2',
            'tanggal_mutasi' => 'date:Y-m-d',
            'tanggal_kedaluwarsa' => 'date:Y-m-d',
        ];
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function jadwalProduksi(): BelongsTo
    {
        return $this->belongsTo(JadwalProduksi::class);
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

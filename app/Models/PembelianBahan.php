<?php

namespace App\Models;

use App\Enums\StatusPembelianBahan;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pembelian bahan baku (R-06b-2): dicatat staf gudang dengan foto nota, diverifikasi akuntan.
 * `total`, `satuan`, `status`, `versi`, dan kolom verifikasi TIDAK mass-assignable: diisi
 * PembelianBahanService (total dihitung server dengan bcmath). Teks bebas, jalur nota, dan alasan
 * penolakan tidak masuk jejak audit.
 */
class PembelianBahan extends Model
{
    use Auditable, BelongsToSppg, HasFactory, HasLampiran, SoftDeletes;

    protected $table = 'pembelian_bahans';

    /** @var list<string> */
    protected array $auditExclude = ['catatan', 'alasan_penolakan', 'nota_path'];

    /** @var list<string> */
    protected $fillable = [
        'bahan_baku_id',
        'pemasok_id',
        'tanggal_beli',
        'jumlah',
        'harga_satuan',
        'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_beli' => 'date:Y-m-d',
            'jumlah' => 'decimal:3',
            'harga_satuan' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => StatusPembelianBahan::class,
            'versi' => 'integer',
            'diverifikasi_pada' => 'datetime',
        ];
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class)->withTrashed();
    }

    public function pemasok(): BelongsTo
    {
        return $this->belongsTo(Pemasok::class)->withTrashed();
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    public function pemverifikasi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }

    public function pengeluaran(): BelongsTo
    {
        return $this->belongsTo(Pengeluaran::class)->withTrashed();
    }
}

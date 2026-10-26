<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Riwayat harga bahan per pemasok (R-06b-1). Append-only: koreksi = entri baru; tidak ada
 * endpoint ubah/hapus dan model menolak update/delete.
 */
class HargaPemasok extends Model
{
    use Auditable, BelongsToSppg, HasFactory;

    protected $table = 'harga_pemasoks';

    /** @var list<string> */
    protected $fillable = [
        'pemasok_id',
        'bahan_baku_id',
        'harga',
        'gram_per_satuan',
        'berlaku_mulai',
        'catatan',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat harga pemasok tidak boleh diubah; catat entri baru.'));
        static::deleting(fn () => throw new LogicException('Riwayat harga pemasok tidak boleh dihapus.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'harga' => 'decimal:2',
            'gram_per_satuan' => 'decimal:3',
            'berlaku_mulai' => 'date:Y-m-d',
        ];
    }

    public function pemasok(): BelongsTo
    {
        return $this->belongsTo(Pemasok::class)->withTrashed();
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class)->withTrashed();
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }
}

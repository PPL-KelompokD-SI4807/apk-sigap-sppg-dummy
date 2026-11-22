<?php

namespace App\Models;

use App\Enums\StatusPengajuanPengeluaran;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Pengajuan persetujuan pengeluaran ke investor (R-06b-2), snapshot nilai saat diajukan. IMUTABEL:
 * delete ditolak; update hanya satu transisi `diajukan` -> {disetujui, ditolak, dibatalkan} yang
 * menyentuh kolom keputusan saja. Dibuat/diubah hanya lewat PersetujuanPengeluaranService
 * ($fillable kosong). Catatan keputusan (teks bebas) tidak masuk jejak audit.
 */
class PersetujuanPengeluaran extends Model
{
    use Auditable, BelongsToSppg, HasFactory;

    protected $table = 'persetujuan_pengeluarans';

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected array $auditExclude = ['catatan_keputusan'];

    private const KOLOM_KEPUTUSAN = ['status', 'diputuskan_oleh', 'diputuskan_pada', 'catatan_keputusan', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            $dirty = array_keys($model->getDirty());

            if ($dirty === []) {
                return;
            }

            $asal = $model->getOriginal('status');
            $baru = $model->getAttribute('status');
            $baru = $baru instanceof StatusPengajuanPengeluaran ? $baru->value : $baru;
            $asal = $asal instanceof StatusPengajuanPengeluaran ? $asal->value : $asal;

            if ($asal !== StatusPengajuanPengeluaran::Diajukan->value
                || $baru === StatusPengajuanPengeluaran::Diajukan->value
                || ! in_array($baru, StatusPengajuanPengeluaran::values(), true)
                || array_diff($dirty, self::KOLOM_KEPUTUSAN) !== []) {
                throw new LogicException('Persetujuan pengeluaran tidak boleh diubah selain keputusan pertama.');
            }
        });

        static::deleting(fn () => throw new LogicException('Persetujuan pengeluaran tidak boleh dihapus.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusPengajuanPengeluaran::class,
            'nomor' => 'integer',
            'pengeluaran_versi' => 'integer',
            'nominal' => 'decimal:2',
            'ambang_persetujuan' => 'decimal:2',
            'tanggal' => 'date:Y-m-d',
            'diajukan_pada' => 'datetime',
            'diputuskan_pada' => 'datetime',
        ];
    }

    public function pengeluaran(): BelongsTo
    {
        return $this->belongsTo(Pengeluaran::class)->withTrashed();
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }
}

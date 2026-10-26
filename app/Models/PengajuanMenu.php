<?php

namespace App\Models;

use App\Enums\StatusPengajuanMenu;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Pengajuan persetujuan menu ke investor dengan snapshot harga (R-06b-1). IMUTABEL:
 * - delete selalu ditolak (tanpa soft delete);
 * - update hanya boleh satu transisi `diajukan` -> {disetujui, ditolak, perlu_revisi, dibatalkan}
 *   yang menyentuh kolom keputusan saja. Snapshot (rincian, biaya, porsi, versi) tidak pernah berubah.
 * Dibuat/diubah hanya lewat PersetujuanMenuService (tidak ada mass assignment: $fillable kosong).
 *
 * @property array<string, mixed> $rincian
 */
class PengajuanMenu extends Model
{
    use Auditable, BelongsToSppg, HasFactory;

    protected $table = 'pengajuan_menus';

    /** @var list<string> */
    protected $fillable = [];

    /** Kolom yang boleh berubah pada transisi keputusan. */
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
            $baru = $baru instanceof StatusPengajuanMenu ? $baru->value : $baru;
            $asal = $asal instanceof StatusPengajuanMenu ? $asal->value : $asal;

            if ($asal !== StatusPengajuanMenu::Diajukan->value
                || $baru === StatusPengajuanMenu::Diajukan->value
                || ! in_array($baru, StatusPengajuanMenu::values(), true)
                || array_diff($dirty, self::KOLOM_KEPUTUSAN) !== []) {
                throw new LogicException('Pengajuan menu tidak boleh diubah selain keputusan pertama.');
            }
        });

        static::deleting(fn () => throw new LogicException('Pengajuan menu tidak boleh dihapus.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusPengajuanMenu::class,
            'rincian' => 'array',
            'menu_versi' => 'integer',
            'jumlah_porsi' => 'integer',
            'nomor' => 'integer',
            'biaya_per_porsi' => 'decimal:2',
            'total_biaya' => 'decimal:2',
            'diajukan_pada' => 'datetime',
            'diputuskan_pada' => 'datetime',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class)->withTrashed();
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

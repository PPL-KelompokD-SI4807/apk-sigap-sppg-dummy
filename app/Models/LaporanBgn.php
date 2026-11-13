<?php

namespace App\Models;

use App\Models\Scopes\SppgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pencatat laporan BGN (Lampiran 30) per SPPG + periode + penomor LPA. Hanya LaporanBgnService /
 * PenomoranLaporanService yang menulis (semua kolom diisi eksplisit); tidak ada mass assignment dari request.
 *
 * Sengaja TIDAK memakai trait BelongsToSppg: hook `creating`-nya memaksa sppg_id = SPPG pengguna dan investor
 * (tanpa sppg_id) akan menghasilkan null. Isolasi baca tetap lewat SppgScope; penulisan memakai sppg_id eksplisit
 * dari service (sudah divalidasi Policy).
 *
 * @property int $id
 * @property int $sppg_id
 * @property string $jenis
 * @property int|null $nomor_urut
 * @property string|null $nomor
 */
class LaporanBgn extends Model
{
    protected $table = 'laporan_bgns';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::addGlobalScope(new SppgScope);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sppg_id' => 'integer',
            'periode_awal' => 'date:Y-m-d',
            'periode_akhir' => 'date:Y-m-d',
            'tahun' => 'integer',
            'nomor_urut' => 'integer',
        ];
    }

    public function sppg(): BelongsTo
    {
        return $this->belongsTo(Sppg::class, 'sppg_id');
    }
}

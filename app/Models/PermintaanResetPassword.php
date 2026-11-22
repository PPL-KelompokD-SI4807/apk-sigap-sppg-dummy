<?php

namespace App\Models;

use App\Enums\StatusPermintaanReset;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permintaan reset password (R-03): pengguna meminta lewat Mini App, Admin dalam scope-nya
 * menyetujui/menolak, lalu pemilik menetapkan password baru setelah token 6 digit dari bot.
 * Lihat PermintaanResetService. Tidak pernah memuat password, token, atau no telepon.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $sppg_id
 * @property StatusPermintaanReset $status
 * @property Carbon $kedaluwarsa_pada
 * @property int|null $diputuskan_oleh
 * @property Carbon|null $diputuskan_pada
 * @property Carbon|null $selesai_pada
 */
class PermintaanResetPassword extends Model
{
    use BelongsToSppg, HasUlids;

    protected $table = 'permintaan_reset_passwords';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'status' => StatusPermintaanReset::class,
            'kedaluwarsa_pada' => 'datetime',
            'diputuskan_oleh' => 'integer',
            'diputuskan_pada' => 'datetime',
            'selesai_pada' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh')->withTrashed();
    }

    public function kedaluwarsa(): bool
    {
        return in_array($this->status, [StatusPermintaanReset::Menunggu, StatusPermintaanReset::Disetujui], true)
            && ! $this->kedaluwarsa_pada->isFuture();
    }

    /** Status untuk ditampilkan: menunggu/disetujui yang lewat 24 jam menjadi "kedaluwarsa". */
    public function statusEfektif(): string
    {
        return $this->kedaluwarsa() ? StatusPermintaanReset::KEDALUWARSA : $this->status->value;
    }

    /**
     * Masih berjalan: menunggu atau disetujui dan belum kedaluwarsa.
     *
     * @param  Builder<PermintaanResetPassword>  $query
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereIn('status', [StatusPermintaanReset::Menunggu->value, StatusPermintaanReset::Disetujui->value])
            ->where('kedaluwarsa_pada', '>', now());
    }
}

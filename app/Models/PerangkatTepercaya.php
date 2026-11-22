<?php

namespace App\Models;

use App\Services\Auth\SesiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perangkat yang sudah lolos token 6 digit untuk satu pengguna (R-03). Milik pengguna, bukan SPPG:
 * selalu diakses lewat relasi User::perangkatTepercaya() atau filter user_id eksplisit.
 * Kepercayaan berlaku 180 hari sejak diverifikasi_pada (token bot terakhir), tidak diperpanjang oleh pemakaian;
 * maks SesiService::maksSesi() perangkat tepercaya per pengguna.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_hash
 * @property string|null $label
 * @property Carbon $diverifikasi_pada
 * @property Carbon|null $dicabut_at
 */
class PerangkatTepercaya extends Model
{
    protected $table = 'perangkat_tepercayas';

    protected $fillable = ['user_id', 'device_hash', 'label', 'diverifikasi_pada', 'dicabut_at'];

    protected $hidden = ['device_hash'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'diverifikasi_pada' => 'datetime',
            'dicabut_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Kepercayaan BERLAKU: belum dicabut dan diverifikasi token bot kurang dari batas absolut sesi
     * (SesiService::batasAbsolutHari, 180 hari) lalu. Lewat batas = tidak tepercaya (login wajib token bot lagi).
     *
     * @param  Builder<PerangkatTepercaya>  $query
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('dicabut_at')
            ->where('diverifikasi_pada', '>', now()->subDays(app(SesiService::class)->batasAbsolutHari()));
    }

    /**
     * Belum dicabut (termasuk yang sudah lewat masa kepercayaan). Dipakai saat MENCABUT.
     *
     * @param  Builder<PerangkatTepercaya>  $query
     */
    public function scopeBelumDicabut(Builder $query): Builder
    {
        return $query->whereNull('dicabut_at');
    }

    /** Akhir masa kepercayaan: diverifikasi_pada + batas absolut sesi (tidak bergeser oleh pemakaian). */
    public function berlakuSampai(): Carbon
    {
        return $this->diverifikasi_pada->copy()->addDays(app(SesiService::class)->batasAbsolutHari());
    }

    public function berlaku(): bool
    {
        return $this->dicabut_at === null && $this->diverifikasi_pada !== null && $this->berlakuSampai()->isFuture();
    }
}

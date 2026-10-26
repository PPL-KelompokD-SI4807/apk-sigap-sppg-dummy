<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perangkat yang sudah lolos token 6 digit untuk satu pengguna (R-03). Milik pengguna, bukan SPPG:
 * selalu diakses lewat relasi User::perangkatTepercaya() atau filter user_id eksplisit.
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

    /** @param Builder<PerangkatTepercaya> $query */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('dicabut_at');
    }
}

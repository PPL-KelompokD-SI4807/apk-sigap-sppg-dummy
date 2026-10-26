<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tantangan token sekali pakai 6 digit (R-03). Lihat App\Services\Auth\TokenSekaliPakaiService.
 * Milik pengguna (bukan SPPG): selalu diakses lewat User::tantanganToken() atau filter user_id.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $personal_access_token_id token pra-login yang meminta tantangan
 * @property string $tujuan
 * @property string $device_hash
 * @property string $kode_hash
 * @property int $percobaan
 * @property int $kirim_ulang
 * @property Carbon $terkirim_pada
 * @property Carbon $kedaluwarsa_pada
 * @property Carbon|null $dipakai_pada
 * @property Carbon|null $hangus_pada
 * @property array<string, mixed>|null $payload
 */
class TantanganToken extends Model
{
    use HasUlids;

    public const TUJUAN_PERANGKAT_BARU = 'perangkat_baru';

    public const TUJUAN_AKTIVASI = 'aktivasi';

    /** Password baru setelah permintaan reset disetujui Admin (PermintaanResetService). */
    public const TUJUAN_RESET_PASSWORD = 'reset_password';

    protected $table = 'tantangan_tokens';

    protected $guarded = ['*'];

    protected $hidden = ['kode_hash', 'device_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'personal_access_token_id' => 'integer',
            'percobaan' => 'integer',
            'kirim_ulang' => 'integer',
            'terkirim_pada' => 'datetime',
            'kedaluwarsa_pada' => 'datetime',
            'dipakai_pada' => 'datetime',
            'hangus_pada' => 'datetime',
            'payload' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Belum dipakai dan belum hangus (bisa saja sudah kedaluwarsa waktu). */
    public function terbuka(): bool
    {
        return $this->dipakai_pada === null && $this->hangus_pada === null;
    }

    /** @param Builder<TantanganToken> $query */
    public function scopeTerbuka(Builder $query): Builder
    {
        return $query->whereNull('dipakai_pada')->whereNull('hangus_pada');
    }
}

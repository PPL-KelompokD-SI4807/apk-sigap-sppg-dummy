<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Browser web yang lolos kode Telegram dengan "Ingat browser ini" (R04-3, ADR 2026-11-09 Keputusan 6). Milik
 * pengguna, bukan SPPG: selalu diakses lewat User::browserTepercaya() atau filter user_id eksplisit. Hanya
 * melewati KODE; sandi tetap wajib. Berlaku tetap 30 hari (berlaku_sampai tidak bergeser), maks 5 per pengguna.
 * Kelola lewat BrowserTepercayaService (bukan mass assignment).
 *
 * @property int $id
 * @property int $user_id
 * @property string $selektor
 * @property string $validator_hash
 * @property string|null $label
 * @property Carbon $diverifikasi_pada
 * @property Carbon $berlaku_sampai
 * @property Carbon|null $terakhir_dipakai_at
 * @property Carbon|null $dicabut_at
 * @property string|null $alasan_cabut
 */
class BrowserTepercaya extends Model
{
    protected $table = 'browser_tepercaya';

    /** Tidak ada kolom fillable: hanya BrowserTepercayaService (forceFill) yang menulis. */
    protected $fillable = [];

    protected $hidden = ['selektor', 'validator_hash'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'diverifikasi_pada' => 'datetime',
            'berlaku_sampai' => 'datetime',
            'terakhir_dipakai_at' => 'datetime',
            'dicabut_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Berlaku: belum dicabut dan belum lewat berlaku_sampai.
     *
     * @param  Builder<BrowserTepercaya>  $query
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('dicabut_at')->where('berlaku_sampai', '>', now());
    }

    /**
     * Belum dicabut (termasuk yang sudah lewat masa). Dipakai saat MENCABUT.
     *
     * @param  Builder<BrowserTepercaya>  $query
     */
    public function scopeBelumDicabut(Builder $query): Builder
    {
        return $query->whereNull('dicabut_at');
    }

    public function berlaku(): bool
    {
        return $this->dicabut_at === null && $this->berlaku_sampai !== null && $this->berlaku_sampai->isFuture();
    }
}

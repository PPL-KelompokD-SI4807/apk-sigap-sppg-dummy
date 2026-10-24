<?php

namespace App\Models;

use App\Enums\StatusKirim;
use App\Enums\StatusPerjalanan;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotifikasiPerjalanan extends Model
{
    use BelongsToSppg, HasFactory;

    protected $table = 'notifikasi_perjalanans';

    /** @var list<string> */
    protected $fillable = [
        'jadwal_distribusi_id',
        'titik_layanan_id',
        'dipicu_oleh',
        'penerima_chat_id',
        'dialihkan_ke_kepala',
        'waktu_kirim',
        'estimasi_tiba',
        'status_kirim',
        'pesan',
        'error',
        'status_perjalanan',
        'waktu_berangkat',
        'waktu_tiba',
        'waktu_dibatalkan',
        'alasan_batal',
        'estimasi_menit',
        'estimasi_sumber',
        'terlambat_dideteksi_pada',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dialihkan_ke_kepala' => 'boolean',
            'penerima_chat_id' => 'integer',
            'waktu_kirim' => 'datetime',
            'estimasi_tiba' => 'datetime',
            'status_kirim' => StatusKirim::class,
            'status_perjalanan' => StatusPerjalanan::class,
            'waktu_berangkat' => 'datetime',
            'waktu_tiba' => 'datetime',
            'waktu_dibatalkan' => 'datetime',
            'estimasi_menit' => 'integer',
            'terlambat_dideteksi_pada' => 'datetime',
        ];
    }

    public function jadwalDistribusi(): BelongsTo
    {
        return $this->belongsTo(JadwalDistribusi::class);
    }

    public function titikLayanan(): BelongsTo
    {
        return $this->belongsTo(TitikLayanan::class);
    }

    public function pemicu(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dipicu_oleh');
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereIn('status_perjalanan', StatusPerjalanan::aktifValues());
    }

    /** Terlambat: sudah ditandai pemindai, atau tiba melewati estimasi. */
    public function isTerlambat(): bool
    {
        if ($this->terlambat_dideteksi_pada !== null) {
            return true;
        }

        if ($this->status_perjalanan === StatusPerjalanan::Tiba && $this->waktu_tiba !== null) {
            return $this->waktu_tiba->gt($this->estimasi_tiba);
        }

        return $this->status_perjalanan?->aktif() === true && now()->gt($this->estimasi_tiba);
    }
}

<?php

namespace App\Models;

use App\Enums\StatusKirim;
use App\Models\Concerns\BelongsToSppg;
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
}

<?php

namespace App\Models;

use App\Enums\JenisTitikLayanan;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TitikLayanan extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'titik_layanans';

    /** @var list<string> */
    protected array $searchable = ['nama', 'alamat', 'pic_nama'];

    /** @var list<string> */
    protected $fillable = [
        'nama',
        'jenis',
        'alamat',
        'jarak_km',
        'jumlah_sasaran',
        'pic_nama',
        'pic_telepon',
        'pic_telegram_chat_id',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'jenis' => JenisTitikLayanan::class,
            'jarak_km' => 'decimal:2',
            'jumlah_sasaran' => 'integer',
            'pic_telegram_chat_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function penerimaManfaats(): HasMany
    {
        return $this->hasMany(PenerimaManfaat::class);
    }

    public function jadwalDistribusis(): HasMany
    {
        return $this->hasMany(JadwalDistribusi::class);
    }

    public function feedbackKeluhans(): HasMany
    {
        return $this->hasMany(FeedbackKeluhan::class);
    }

    public function notifikasiPerjalanans(): HasMany
    {
        return $this->hasMany(NotifikasiPerjalanan::class);
    }

    /** PIC sudah menghubungkan Telegram (menerima Notifikasi Perjalanan Mobil). */
    public function picTerhubung(): bool
    {
        return $this->pic_telegram_chat_id !== null;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Berkas unggahan (polimorfik). Dibuat HANYA lewat App\Services\Storage\SecureUploadService dan
 * disajikan HANYA lewat GET /api/v1/lampiran/{id} (Policy modul induk + SppgScope).
 */
class Lampiran extends Model
{
    use BelongsToSppg, HasFactory;

    protected $table = 'lampirans';

    /** @var list<string> */
    protected $fillable = [
        'lampiranable_type',
        'lampiranable_id',
        'uploaded_by',
        'kategori',
        'path',
        'mime',
        'ukuran',
        'sha256',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ukuran' => 'integer',
        ];
    }

    public function lampiranable(): MorphTo
    {
        return $this->morphTo();
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** URL relatif API (butuh token Bearer); bukan URL publik. */
    public function url(): string
    {
        return '/api/v1/lampiran/'.$this->getKey();
    }
}

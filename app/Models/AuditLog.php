<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    /** Sumber audit sementara (web/bot/ai/sistem) selama withSource() berjalan. */
    protected static ?string $currentSource = null;

    /** @var list<string> */
    protected $fillable = [
        'sppg_id',
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip',
        'user_agent',
        'sumber',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function sppg(): BelongsTo
    {
        return $this->belongsTo(Sppg::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function currentSource(): ?string
    {
        return static::$currentSource;
    }

    /**
     * Jalankan callback dengan sumber audit tertentu (mis. 'ai' saat draf dikonfirmasi).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withSource(string $source, Closure $callback): mixed
    {
        $sebelumnya = static::$currentSource;
        static::$currentSource = $source;

        try {
            return $callback();
        } finally {
            static::$currentSource = $sebelumnya;
        }
    }
}

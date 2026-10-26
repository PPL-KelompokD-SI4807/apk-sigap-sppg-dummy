<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSppg;
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Log audit append-only (kontrak fondasi auth/RBAC). Ditulis oleh trait Auditable.
 *
 * - Terisolasi per SPPG lewat BelongsToSppg (admin lintas SPPG).
 * - Tidak boleh diubah atau dihapus (updating/deleting melempar LogicException).
 * - Jangan pernah membuat AuditLog dari input request.
 *
 * @property int $id
 * @property int|null $sppg_id
 * @property int|null $user_id
 * @property string $event
 * @property string $sumber
 */
class AuditLog extends Model
{
    use BelongsToSppg, HasFactory;

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
            'auditable_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Log audit tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Log audit tidak boleh dihapus.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
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
     * Jalankan callback dengan sumber audit tertentu (mis. 'ai' saat draf dikonfirmasi, 'bot').
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

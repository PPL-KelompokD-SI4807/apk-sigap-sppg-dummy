<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Models\Scopes\SppgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Mencatat created/updated/deleted/restored ke tabel audit_logs.
 *
 * Field sensitif TIDAK pernah dicatat: daftar global AUDIT_NEVER_LOG, atribut $hidden model,
 * dan properti opsional `protected array $auditExclude = [...]` pada model.
 * Sumber (web/bot/ai/sistem) dapat diatur sementara melalui AuditLog::withSource('ai', fn () => ...).
 */
trait Auditable
{
    /** @var list<string> */
    public const AUDIT_NEVER_LOG = [
        'password', 'remember_token', 'token', 'token_hash', 'api_token', 'access_token',
        'refresh_token', 'secret', 'api_key', 'init_data', 'hash', 'two_factor_secret',
    ];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAudit('created', [], $model->auditFilter($model->getAttributes())));

        static::updated(function (Model $model): void {
            $changes = $model->auditFilter($model->getChanges());
            // deleted_at dicatat lewat event deleted/restored, bukan updated.
            unset($changes['updated_at'], $changes['deleted_at']);

            if ($changes === []) {
                return;
            }

            $old = $model->auditFilter(array_intersect_key($model->getOriginal(), $changes));
            $model->writeAudit('updated', $old, $changes);
        });

        static::deleted(fn (Model $model) => $model->writeAudit('deleted', $model->auditFilter($model->getAttributes()), []));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->writeAudit('restored', [], $model->auditFilter($model->getAttributes())));
        }
    }

    /**
     * Bayt UTF-8 tidak sah diganti '?' agar kolom JSON audit tidak pernah menggagalkan penulisan data utama.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditFilter(array $attributes): array
    {
        $exclude = $this->auditKolomTerlarang();

        return array_map(
            fn (mixed $nilai) => is_string($nilai) && ! mb_check_encoding($nilai, 'UTF-8') ? mb_convert_encoding($nilai, 'UTF-8', 'UTF-8') : $nilai,
            array_diff_key($attributes, array_flip($exclude)),
        );
    }

    /**
     * Kolom yang tidak boleh dicatat maupun ditampilkan lagi di log audit (daftar global, $hidden, $auditExclude).
     * Dipakai juga oleh penyaring tampilan GET /audit-log (defense in depth untuk log lama).
     *
     * @return list<string>
     */
    public function auditKolomTerlarang(): array
    {
        return array_values(array_unique(array_merge(
            self::AUDIT_NEVER_LOG,
            $this->getHidden(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        )));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function writeAudit(string $event, array $old, array $new): void
    {
        $user = SppgScope::currentUser();
        $request = App::runningInConsole() && ! App::runningUnitTests() ? null : request();

        $log = new AuditLog;
        $log->forceFill([
            'sppg_id' => $this->auditSppgId(),
            'user_id' => $user?->getKey(),
            'event' => $event,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'sumber' => AuditLog::currentSource() ?? ($request ? 'web' : 'sistem'),
        ]);
        $log->save();
    }

    protected function auditSppgId(): ?int
    {
        $value = $this->getAttribute('sppg_id');

        return $value === null ? null : (int) $value;
    }
}

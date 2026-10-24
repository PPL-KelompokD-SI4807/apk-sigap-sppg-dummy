<?php

namespace App\Models;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Pengguna SiGAP SPPG (login hanya via Telegram initData).
 *
 * @property int $id
 * @property int|null $sppg_id
 * @property int|null $telegram_id
 * @property string|null $telegram_username
 * @property string $name
 * @property Role $role
 * @property RelawanUnit|null $unit
 * @property string|null $phone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    use Auditable, BelongsToSppg, HasApiTokens, Notifiable, SoftDeletes;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * Atribut yang boleh diisi massal. Controller WAJIB hanya meneruskan data hasil
     * Form Request + Policy; role/sppg_id hanya boleh diubah admin.
     */
    protected $fillable = [
        'sppg_id', 'telegram_id', 'telegram_username', 'name', 'role', 'unit', 'phone', 'is_active',
    ];

    /** Kolom yang bila berubah mencabut semua token aktif pengguna. */
    public const TOKEN_SENSITIVE = ['role', 'unit', 'sppg_id', 'is_active', 'telegram_id'];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'role' => Role::class,
            'unit' => RelawanUnit::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Perubahan hak akses berlaku segera: token lama dicabut.
        static::updated(function (User $user): void {
            if ($user->wasChanged(self::TOKEN_SENSITIVE)) {
                $user->tokens()->delete();
            }
        });

        static::deleted(fn (User $user) => $user->tokens()->delete());
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isRelawanUnit(RelawanUnit $unit): bool
    {
        return $this->role === Role::Relawan && $this->unit === $unit;
    }

    /** Pengguna boleh login/memakai token: aktif dan tidak terhapus. */
    public function canAuthenticate(): bool
    {
        return $this->is_active && ! $this->trashed();
    }
}

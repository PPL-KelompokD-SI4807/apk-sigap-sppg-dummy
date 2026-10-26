<?php

namespace App\Models;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use App\Support\SppgAktif;
use App\Support\Telepon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Pengguna SiGAP SPPG. Login dua lapis (R-03): initData Telegram lalu username/no telepon + password
 * (+ token 6 digit dari bot untuk aktivasi, reset password, dan perangkat baru).
 *
 * @property int $id
 * @property int|null $sppg_id
 * @property int|null $telegram_id
 * @property string|null $telegram_username
 * @property string $name
 * @property string|null $username
 * @property Role $role
 * @property RelawanUnit|null $unit
 * @property string|null $phone
 * @property string|null $password
 * @property Carbon|null $password_changed_at
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
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

    /**
     * Kredensial (username, password, penguncian) sengaja tidak fillable: hanya diubah
     * KredensialService lewat forceFill/query.
     */
    protected $hidden = ['password'];

    /** Tidak dicatat di audit: no telepon (UU PDP) dan penghitung login (bukan perubahan data). */
    protected array $auditExclude = ['phone', 'failed_login_attempts', 'locked_until', 'password_changed_at'];

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
            'password_changed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Perubahan hak akses berlaku segera: token, perangkat tepercaya, dan tantangan dicabut.
        // Password diubah/di-reset juga mencabut semua sesi (R-03).
        static::updated(function (User $user): void {
            if ($user->wasChanged([...self::TOKEN_SENSITIVE, 'password'])) {
                $user->cabutSemuaSesi();
            }
        });

        static::deleted(fn (User $user) => $user->cabutSemuaSesi());
    }

    /** No telepon selalu disimpan dalam format normal (E.164) bila dapat dikenali. */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null || trim($value) === '' ? null : (Telepon::normalisasi($value) ?? trim($value)),
        );
    }

    public function perangkatTepercaya(): HasMany
    {
        return $this->hasMany(PerangkatTepercaya::class);
    }

    public function tantanganToken(): HasMany
    {
        return $this->hasMany(TantanganToken::class);
    }

    /**
     * Cabut semua sesi: token (penuh + pra-login), kepercayaan perangkat, dan tantangan terbuka.
     * $kecualiTokenId/$kecualiPerangkatId dipertahankan (mis. sesi yang sedang mengubah password).
     */
    public function cabutSemuaSesi(?int $kecualiTokenId = null, ?int $kecualiPerangkatId = null): void
    {
        $this->tokens()->when($kecualiTokenId !== null, fn ($q) => $q->whereKeyNot($kecualiTokenId))->delete();

        $this->perangkatTepercaya()->aktif()
            ->when($kecualiPerangkatId !== null, fn ($q) => $q->whereKeyNot($kecualiPerangkatId))
            ->update(['dicabut_at' => now()]);

        $this->tantanganToken()->terbuka()->update(['hangus_pada' => now(), 'payload' => null]);
    }

    /**
     * SPPG milik investor (R-06a). Hanya bermakna untuk peran investor; dikelola Admin.
     * Pakai SppgAktif untuk keputusan akses (SPPG terhapus tidak dihitung).
     */
    public function sppgDimiliki(): BelongsToMany
    {
        return $this->belongsToMany(Sppg::class, 'investor_sppg', 'user_id', 'sppg_id')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isInvestor(): bool
    {
        return $this->role === Role::Investor;
    }

    /**
     * SPPG tempat data pengguna dibatasi pada permintaan ini: users.sppg_id untuk peran satu-SPPG,
     * SPPG aktif tervalidasi (header X-SPPG-Id) untuk investor, null untuk admin/tidak ada.
     * Untuk MENULIS data tetap pakai users.sppg_id (investor null -> selalu fail closed).
     */
    public function sppgAktifId(): ?int
    {
        return app(SppgAktif::class)->id($this);
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isRelawanUnit(RelawanUnit $unit): bool
    {
        return $this->role === Role::Relawan && $this->unit === $unit;
    }

    /** Password sudah diatur (akun teraktivasi). Null = wajib aktivasi/atur password dulu. */
    public function punyaPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /** Akun terkunci sementara karena password salah berulang. */
    public function terkunci(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** Pengguna boleh login/memakai token: aktif dan tidak terhapus. */
    public function canAuthenticate(): bool
    {
        return $this->is_active && ! $this->trashed();
    }
}

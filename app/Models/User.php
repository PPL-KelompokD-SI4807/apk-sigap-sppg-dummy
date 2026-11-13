<?php

namespace App\Models;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\BrowserTepercayaService;
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
use Illuminate\Support\Facades\Auth;
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
 * @property int $web_gagal_login penguncian kanal web R04-2 (terpisah dari Mini App)
 * @property Carbon|null $web_terkunci_sampai
 * @property Carbon|null $web_terkunci_terakhir_pada
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
    protected array $auditExclude = ['phone', 'username', 'failed_login_attempts', 'locked_until', 'password_changed_at', 'web_gagal_login', 'web_terkunci_sampai', 'web_terkunci_terakhir_pada'];

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
            'web_gagal_login' => 'integer',
            'web_terkunci_sampai' => 'datetime',
            'web_terkunci_terakhir_pada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Perubahan hak akses berlaku segera: token, perangkat tepercaya, dan tantangan dicabut.
        // Password diubah/di-reset juga mencabut semua sesi (R-03).
        // Tidak bergantung pada sisa masa sesi (90/30 hari): token baru wajib diterbitkan lewat login ulang,
        // sehingga ability peran lama tidak pernah terbawa.
        static::updated(function (User $user): void {
            if ($user->wasChanged([...self::TOKEN_SENSITIVE, 'password'])) {
                // Perubahan password sudah punya event audit sendiri (auth.password_*/reset_*).
                $kolom = array_values(array_filter(self::TOKEN_SENSITIVE, fn (string $k) => $user->wasChanged($k)));
                $jumlah = $user->cabutSemuaSesi(alasan: $kolom === [] ? 'password_berubah' : 'hak_akses_berubah');

                if ($jumlah > 0 && $kolom !== []) {
                    $user->catatPencabutanOtomatis('hak_akses_berubah', $jumlah, ['kolom' => $kolom]);
                }
            }
        });

        // Dihapus lunak atau dipulihkan (daftar ulang lewat undangan): sesi lama dan tautan bot lama
        // tidak pernah berlaku lagi untuk akun ini.
        static::deleted(function (User $user): void {
            $jumlah = $user->cabutSemuaSesi(alasan: 'akun_dihapus');
            $user->cabutTautanBot();

            if ($jumlah > 0) {
                $user->catatPencabutanOtomatis('akun_dihapus', $jumlah);
            }
        });

        static::restored(function (User $user): void {
            $jumlah = $user->cabutSemuaSesi(alasan: 'akun_dipulihkan');
            $user->cabutTautanBot();

            if ($jumlah > 0) {
                $user->catatPencabutanOtomatis('akun_dipulihkan', $jumlah);
            }
        });
    }

    /**
     * Audit pencabutan sesi otomatis (tanpa nilai token). Dipakai hook model dan jalur yang mengubah kolom
     * TOKEN_SENSITIVE tanpa event (mis. PenghapusDataUji).
     *
     * @param  array<string, mixed>  $tambahan
     */
    public function catatPencabutanOtomatis(string $pemicu, int $jumlah, array $tambahan = []): void
    {
        $aktor = Auth::hasUser() ? Auth::user() : null;

        AuditAuth::catat($this, AuditAuth::SESI_DICABUT_OTOMATIS, ['pemicu' => $pemicu, 'jumlah_token' => $jumlah] + $tambahan,
            $aktor instanceof User ? $aktor : null);
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

    /** Browser web tepercaya R04-3 (lewati kode Telegram; sandi tetap wajib). */
    public function browserTepercaya(): HasMany
    {
        return $this->hasMany(BrowserTepercaya::class);
    }

    /**
     * Cabut semua sesi: token (penuh + pra-login), kepercayaan perangkat, browser tepercaya web (R04-3), dan
     * tantangan terbuka. $kecualiTokenId/$kecualiPerangkatId dipertahankan (mis. sesi yang sedang mengubah
     * password). Browser tepercaya TIDAK pernah dikecualikan: bila token yang dipertahankan berasal dari browser
     * tepercaya, tautannya dilepas sehingga sesi itu kembali menjadi sesi web biasa (idle 12 jam).
     * $alasan = pemicu yang dicatat pada pencabutan browser tepercaya. Mengembalikan jumlah token yang dihapus.
     */
    public function cabutSemuaSesi(?int $kecualiTokenId = null, ?int $kecualiPerangkatId = null, string $alasan = BrowserTepercayaService::ALASAN_SESI_DICABUT): int
    {
        if ($kecualiTokenId !== null) {
            $this->tokens()->whereKey($kecualiTokenId)->update(['browser_tepercaya_id' => null]);
        }

        $jumlah = (int) $this->tokens()->when($kecualiTokenId !== null, fn ($q) => $q->whereKeyNot($kecualiTokenId))->delete();

        app(BrowserTepercayaService::class)->cabutSemua($this, $alasan);

        $this->perangkatTepercaya()->belumDicabut()
            ->when($kecualiPerangkatId !== null, fn ($q) => $q->whereKeyNot($kecualiPerangkatId))
            ->update(['dicabut_at' => now()]);

        $this->tantanganToken()->terbuka()->update(['hangus_pada' => now(), 'payload' => null]);

        return $jumlah;
    }

    /**
     * Tombol aksi bot (bot_aksi) yang masih aktif atau menunggu balasan catatan dijadikan kedaluwarsa,
     * sehingga callback/balasan lama tidak dapat dipakai lagi. Baris tetap disimpan (riwayat).
     */
    public function cabutTautanBot(): int
    {
        return BotAksi::query()
            ->where('user_id', $this->getKey())
            ->whereIn('status', [BotAksi::STATUS_AKTIF, BotAksi::STATUS_MENUNGGU_CATATAN])
            ->update(['status' => BotAksi::STATUS_KEDALUWARSA]);
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

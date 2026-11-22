<?php

namespace App\Services\Users;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Enums\StatusPermintaanReset;
use App\Enums\TipeUndangan;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\PermintaanResetPassword;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undangan Telegram sekali pakai (FR-01/FR-02).
 *
 * Keamanan:
 * - Token = 32 byte acak kriptografis (base64url, 43 karakter); hanya hash SHA-256 disimpan.
 * - Berlaku TTL_HOURS jam (72, FR-01), sekali pakai, dapat dicabut (revoked_at).
 * - consume*(): transaksi + lockForUpdate + klaim atomik (UPDATE ... WHERE used_at IS NULL),
 *   sehingga dua pemakaian bersamaan tidak pernah sama-sama berhasil.
 * - telegram_id tidak pernah ganda: dicek (termasuk akun terhapus lunak) dan dijaga indeks unik DB.
 *   ID milik akun AKTIF selalu ditolak (TELEGRAM_SUDAH_TERDAFTAR).
 * - Daftar ulang akun terhapus lunak: bila telegram_id milik baris terhapus, baris LAMA dipulihkan
 *   (id, audit, log, AiDraft tetap pada baris yang sama; telegram_id tidak pernah dilepas).
 *   Hanya undangan pengguna yang masih sah dan diterbitkan akun aktif berhak C modul pengguna
 *   yang dapat memicunya; /start tanpa undangan sah tetap ditolak. Peran/unit/SPPG/status aktif
 *   diambil dari isian Admin (undangan peran, atau baris placeholder pada undangan user_id; placeholder
 *   lalu dihapus lunak dan used_by menunjuk baris yang dipulihkan), BUKAN dari baris lama.
 *   Kredensial lama dikosongkan (wajib aktivasi ulang lewat token bot); token Sanctum, perangkat
 *   tepercaya, tantangan token, tombol bot, draf AI pending, permintaan reset aktif, dan kepemilikan
 *   SPPG investor milik baris lama dicabut. Pemulihan tercatat di audit (updated + restored, sumber bot).
 * - SPPG tujuan wajib ada dan belum dihapus saat undangan dipakai (SPPG_TIDAK_VALID).
 *
 * Bot memanggil consume($rawToken, $telegramUser) untuk undangan pengguna, consumePic() untuk
 * undangan PIC, atau consumeAny() bila jenis belum diketahui. Awalan "inv_" pada parameter
 * /start boleh disertakan. $telegramUser = objek user Telegram: id (wajib), first_name,
 * last_name, username; opsional chat_id (default id; chat pribadi = id pengguna).
 */
class InvitationService
{
    public const TTL_HOURS = 72;

    public const TOKEN_BYTES = 32;

    public const DEEP_LINK_PREFIX = 'inv_';

    /**
     * @param  array<string, mixed>  $attributes  pengguna: user_id ATAU role (+unit, +sppg_id); pic: titik_layanan_id
     */
    public function issue(User $creator, TipeUndangan $tipe, array $attributes = []): IssuedInvitation
    {
        $rawToken = $this->generateToken();

        $invitation = DB::transaction(function () use ($creator, $tipe, $attributes, $rawToken): Invitation {
            $data = $tipe === TipeUndangan::Pengguna
                ? $this->prepareUserInvitation($creator, $attributes)
                : $this->preparePicInvitation($creator, $attributes);

            // Satu undangan aktif per target: yang lama dicabut agar tautan lama tidak berlaku.
            $this->revokeActiveFor($data);

            return Invitation::query()->create($data + [
                'token_hash' => Invitation::hashToken($rawToken),
                'tipe' => $tipe,
                'created_by' => $creator->getKey(),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        });

        $bot = config('telegram.bot_username');
        $deepLink = is_string($bot) && $bot !== ''
            ? 'https://t.me/'.ltrim($bot, '@').'?start='.self::DEEP_LINK_PREFIX.$rawToken
            : null;

        return new IssuedInvitation($invitation, $rawToken, $deepLink);
    }

    /** Cabut undangan yang belum dipakai. Undangan terpakai tidak dapat dicabut. */
    public function revoke(Invitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($locked->used_at !== null) {
                throw ValidationException::withMessages(['undangan' => ['Undangan sudah dipakai dan tidak dapat dicabut.']]);
            }

            if ($locked->revoked_at === null) {
                $locked->forceFill(['revoked_at' => now()])->save();
            }
        });
    }

    /** Undangan pengguna: membuat atau menautkan akun ke telegram_id. */
    public function consume(string $rawToken, array $telegramUser): User
    {
        return $this->redeem($rawToken, $telegramUser, TipeUndangan::Pengguna)->user;
    }

    /** Undangan PIC: menautkan chat Telegram PIC ke titik layanan. */
    public function consumePic(string $rawToken, array $telegramUser): TitikLayanan
    {
        return $this->redeem($rawToken, $telegramUser, TipeUndangan::PicTitikLayanan)->titikLayanan;
    }

    /** Untuk bot yang belum tahu jenis undangan. */
    public function consumeAny(string $rawToken, array $telegramUser): ConsumedInvitation
    {
        return $this->redeem($rawToken, $telegramUser, null);
    }

    public function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------ issue helpers

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepareUserInvitation(User $creator, array $attributes): array
    {
        if (! Rbac::can($creator, 'pengguna', 'C')) {
            throw new AuthorizationException('Tidak berhak menerbitkan undangan pengguna.');
        }

        if (! empty($attributes['user_id'])) {
            $user = User::query()->find($attributes['user_id']);

            if ($user === null) {
                throw ValidationException::withMessages(['user_id' => ['Pengguna tidak ditemukan.']]);
            }

            if ($user->telegram_id !== null) {
                throw ValidationException::withMessages(['user_id' => ['Pengguna ini sudah terhubung dengan Telegram.']]);
            }

            return [
                'user_id' => $user->getKey(),
                'role' => $user->role->value,
                'unit' => $user->unit?->value,
                'sppg_id' => $user->sppg_id,
            ];
        }

        $role = Role::tryFrom((string) ($attributes['role'] ?? ''));

        if ($role === null) {
            throw ValidationException::withMessages(['role' => ['Peran tidak valid.']]);
        }

        $unit = $attributes['unit'] ?? null;

        if ($role === Role::Relawan ? RelawanUnit::tryFrom((string) $unit) === null : $unit !== null) {
            throw ValidationException::withMessages(['unit' => ['Unit wajib untuk relawan dan harus kosong untuk peran lain.']]);
        }

        $sppgId = $attributes['sppg_id'] ?? null;

        if ($role->tanpaSppgId() ? $sppgId !== null : $sppgId === null) {
            throw ValidationException::withMessages(['sppg_id' => ['SPPG wajib untuk peran satu-SPPG dan harus kosong untuk admin/investor.']]);
        }

        if ($sppgId !== null && ! $this->sppgAda((int) $sppgId)) {
            throw ValidationException::withMessages(['sppg_id' => ['SPPG tidak ditemukan.']]);
        }

        return ['user_id' => null, 'role' => $role->value, 'unit' => $unit, 'sppg_id' => $sppgId];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function preparePicInvitation(User $creator, array $attributes): array
    {
        if (! Rbac::can($creator, 'titik-layanan', 'U')) {
            throw new AuthorizationException('Tidak berhak menerbitkan undangan PIC.');
        }

        $titik = TitikLayanan::query()->find($attributes['titik_layanan_id'] ?? 0);

        if ($titik === null || (! $creator->isAdmin() && (int) $titik->sppg_id !== (int) $creator->sppg_id)) {
            throw ValidationException::withMessages(['titik_layanan_id' => ['Titik layanan tidak ditemukan.']]);
        }

        return ['titik_layanan_id' => $titik->getKey(), 'sppg_id' => $titik->sppg_id, 'role' => null, 'unit' => null];
    }

    /** @param array<string, mixed> $data */
    private function revokeActiveFor(array $data): void
    {
        $query = Invitation::query()->aktif();

        if (! empty($data['user_id'])) {
            $query->where('user_id', $data['user_id']);
        } elseif (! empty($data['titik_layanan_id'])) {
            $query->where('titik_layanan_id', $data['titik_layanan_id']);
        } else {
            return;
        }

        $query->update(['revoked_at' => now()]);
    }

    // ---------------------------------------------------------------- consume helpers

    /** @param array<string, mixed> $telegramUser */
    private function redeem(string $rawToken, array $telegramUser, ?TipeUndangan $expected): ConsumedInvitation
    {
        $telegramId = $this->telegramId($telegramUser);
        $hash = Invitation::hashToken($this->normalizeToken($rawToken));

        try {
            return AuditLog::withSource('bot', fn () => DB::transaction(
                fn () => $this->redeemLocked($hash, $telegramId, $telegramUser, $expected),
            ));
        } catch (UniqueConstraintViolationException) {
            // Balapan dua pemakaian dengan telegram_id sama: indeks unik users.telegram_id menang.
            throw new InvalidInvitationException(InvalidInvitationException::TELEGRAM_SUDAH_TERDAFTAR);
        }
    }

    /** @param array<string, mixed> $telegramUser */
    private function redeemLocked(string $hash, int $telegramId, array $telegramUser, ?TipeUndangan $expected): ConsumedInvitation
    {
        $invitation = Invitation::query()->where('token_hash', $hash)->lockForUpdate()->first();

        if ($invitation === null) {
            throw new InvalidInvitationException(InvalidInvitationException::TIDAK_VALID);
        }

        if ($invitation->revoked_at !== null) {
            throw new InvalidInvitationException(InvalidInvitationException::DICABUT);
        }

        if ($invitation->used_at !== null) {
            throw new InvalidInvitationException(InvalidInvitationException::TERPAKAI);
        }

        if (! $invitation->expires_at->isFuture()) {
            throw new InvalidInvitationException(InvalidInvitationException::KEDALUWARSA);
        }

        if ($expected !== null && $invitation->tipe !== $expected) {
            throw new InvalidInvitationException(InvalidInvitationException::TIPE_TIDAK_SESUAI);
        }

        // Klaim atomik: hanya satu pemakai yang mendapat baris ini.
        $claimed = Invitation::query()
            ->whereKey($invitation->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        if ($claimed !== 1) {
            throw new InvalidInvitationException(InvalidInvitationException::TERPAKAI);
        }

        if ($invitation->tipe === TipeUndangan::Pengguna) {
            $user = $this->linkOrCreateUser($invitation, $telegramId, $telegramUser);
            $invitation->forceFill(['used_at' => now(), 'used_by' => $user->getKey()])->save();

            return new ConsumedInvitation($invitation->tipe, $invitation, user: $user);
        }

        $titik = $this->linkPic($invitation, (int) ($telegramUser['chat_id'] ?? $telegramId));
        $invitation->forceFill(['used_at' => now()])->save();

        return new ConsumedInvitation($invitation->tipe, $invitation, titikLayanan: $titik);
    }

    /** @param array<string, mixed> $telegramUser */
    private function linkOrCreateUser(Invitation $invitation, int $telegramId, array $telegramUser): User
    {
        // Termasuk baris terhapus lunak (withoutGlobalScopes): indeks unik telegram_id masih memegang ID-nya.
        // Dikunci agar dua undangan bersamaan untuk ID yang sama berbaris; yang kedua melihat akun sudah aktif.
        $pemilik = User::query()->withoutGlobalScopes()->where('telegram_id', $telegramId)->lockForUpdate()->first();

        if ($pemilik !== null && ! $pemilik->trashed()) {
            throw new InvalidInvitationException(InvalidInvitationException::TELEGRAM_SUDAH_TERDAFTAR);
        }

        $username = $this->username($telegramUser);
        $placeholder = $invitation->user_id !== null ? $this->lockPlaceholder($invitation) : null;

        if ($pemilik !== null) {
            return $this->restoreUser($invitation, $pemilik, $placeholder, $telegramUser, $username);
        }

        if ($placeholder !== null) {
            $this->assertTujuanValid($placeholder->role, $placeholder->sppg_id);
            $placeholder->fill(['telegram_id' => $telegramId, 'telegram_username' => $username])->save();

            return $placeholder;
        }

        $this->assertTujuanValid(Role::tryFrom((string) $invitation->role), $invitation->sppg_id);

        return User::query()->create([
            'sppg_id' => $invitation->sppg_id,
            'telegram_id' => $telegramId,
            'telegram_username' => $username,
            'name' => $this->displayName($telegramUser),
            'role' => $invitation->role,
            'unit' => $invitation->unit,
            'is_active' => true,
        ]);
    }

    /** Akun buatan Admin (undangan user_id): belum terhapus dan belum bertaut Telegram. */
    private function lockPlaceholder(Invitation $invitation): User
    {
        $user = User::query()->withoutGlobalScopes()->lockForUpdate()->find($invitation->user_id);

        if ($user === null || $user->trashed()) {
            throw new InvalidInvitationException(InvalidInvitationException::TIDAK_VALID);
        }

        if ($user->telegram_id !== null) {
            throw new InvalidInvitationException(InvalidInvitationException::AKUN_SUDAH_TERHUBUNG);
        }

        return $user;
    }

    /**
     * Pulihkan baris terhapus lunak pemilik telegram_id (daftar ulang). Atribut akses dari isian Admin,
     * bukan dari baris lama; kredensial lama dikosongkan; semua sesi/tautan lama dicabut (User::restored).
     *
     * @param  array<string, mixed>  $telegramUser
     */
    private function restoreUser(Invitation $invitation, User $lama, ?User $placeholder, array $telegramUser, ?string $username): User
    {
        $this->assertPenerbitBerhak($invitation);

        if ($placeholder !== null) {
            $this->assertTujuanValid($placeholder->role, $placeholder->sppg_id);
            $atribut = [
                'name' => $placeholder->name,
                'role' => $placeholder->role,
                'unit' => $placeholder->unit,
                'sppg_id' => $placeholder->sppg_id,
                'phone' => $placeholder->phone,
                'is_active' => $placeholder->is_active,
            ];
            $kepemilikan = DB::table('investor_sppg')->where('user_id', $placeholder->getKey())->get(['sppg_id', 'created_by']);
            $this->retirePlaceholder($placeholder);
        } else {
            $role = Role::tryFrom((string) $invitation->role);
            $this->assertTujuanValid($role, $invitation->sppg_id);
            $atribut = [
                'name' => $this->displayName($telegramUser),
                'role' => $role,
                'unit' => $invitation->unit,
                'sppg_id' => $invitation->sppg_id,
                'phone' => null,
                'is_active' => true,
            ];
            $kepemilikan = collect();
        }

        $id = $lama->getKey();

        // Hak lama tidak boleh hidup kembali: kepemilikan SPPG investor diganti isian Admin (placeholder).
        DB::table('investor_sppg')->where('user_id', $id)->delete();

        if ($atribut['role'] === Role::Investor && $kepemilikan->isNotEmpty()) {
            $now = now();
            DB::table('investor_sppg')->insert($kepemilikan->map(fn ($r) => [
                'user_id' => $id,
                'sppg_id' => (int) $r->sppg_id,
                'created_by' => $r->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        Invitation::query()->aktif()->where('user_id', $id)->update(['revoked_at' => now()]);
        PermintaanResetPassword::query()->withoutGlobalScopes()->aktif()->where('user_id', $id)
            ->update(['status' => StatusPermintaanReset::Ditolak->value, 'diputuskan_pada' => now()]);
        AiDraft::query()->withoutGlobalScopes()->pending()->where('user_id', $id)
            ->update(['status' => StatusAiDraft::Expired->value]);

        $lama->forceFill($atribut + [
            'telegram_username' => $username,
            'username' => null,
            'password' => null,
            'password_changed_at' => null,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        // restore(): audit "updated" (atribut baru) + "restored"; hook User::restored mencabut sesi & bot_aksi.
        $lama->restore();

        return $lama;
    }

    /** Placeholder undangan user_id digantikan baris yang dipulihkan: nilai unik dilepas lalu dihapus lunak. */
    private function retirePlaceholder(User $placeholder): void
    {
        $placeholder->forceFill(['phone' => null])->save();
        DB::table('investor_sppg')->where('user_id', $placeholder->getKey())->delete();
        Invitation::query()->aktif()->where('user_id', $placeholder->getKey())->update(['revoked_at' => now()]);
        $placeholder->delete();
    }

    /** Pemulihan hanya lewat undangan terbitan akun yang masih aktif dan berhak C modul pengguna. */
    private function assertPenerbitBerhak(Invitation $invitation): void
    {
        $penerbit = $invitation->created_by === null
            ? null
            : User::query()->withoutGlobalScopes()->find($invitation->created_by);

        if ($penerbit === null || ! $penerbit->canAuthenticate() || ! Rbac::can($penerbit, 'pengguna', 'C')) {
            throw new InvalidInvitationException(InvalidInvitationException::TIDAK_VALID);
        }
    }

    /** Peran sah dan SPPG tujuan sesuai peran: wajib ada dan belum dihapus (peran satu-SPPG), kosong untuk admin/investor. */
    private function assertTujuanValid(?Role $role, mixed $sppgId): void
    {
        if ($role === null) {
            throw new InvalidInvitationException(InvalidInvitationException::TIDAK_VALID);
        }

        $valid = $role->tanpaSppgId()
            ? $sppgId === null
            : $sppgId !== null && $this->sppgAda((int) $sppgId, kunci: true);

        if (! $valid) {
            throw new InvalidInvitationException(InvalidInvitationException::SPPG_TIDAK_VALID);
        }
    }

    /** SPPG ada dan belum dihapus lunak. $kunci: kunci bersama sampai transaksi selesai (tidak dihapus di tengah jalan). */
    private function sppgAda(int $sppgId, bool $kunci = false): bool
    {
        return Sppg::query()->withoutGlobalScopes()->whereKey($sppgId)->whereNull('deleted_at')
            ->when($kunci, fn ($q) => $q->sharedLock())
            ->value('id') !== null;
    }

    private function linkPic(Invitation $invitation, int $chatId): TitikLayanan
    {
        $titik = TitikLayanan::query()->withoutGlobalScope(SppgScope::class)->lockForUpdate()->find($invitation->titik_layanan_id);

        if ($titik === null) {
            throw new InvalidInvitationException(InvalidInvitationException::TIDAK_VALID);
        }

        $titik->forceFill(['pic_telegram_chat_id' => $chatId])->save();

        return $titik;
    }

    // ---------------------------------------------------------------- input helpers

    private function normalizeToken(string $rawToken): string
    {
        $token = trim($rawToken);

        if (str_starts_with($token, self::DEEP_LINK_PREFIX)) {
            $token = substr($token, strlen(self::DEEP_LINK_PREFIX));
        }

        if (preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token) !== 1) {
            throw new InvalidInvitationException(InvalidInvitationException::TIDAK_VALID);
        }

        return $token;
    }

    /** @param array<string, mixed> $telegramUser */
    private function telegramId(array $telegramUser): int
    {
        $id = $telegramUser['id'] ?? null;

        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }

        if (! is_int($id) || $id <= 0) {
            throw new InvalidInvitationException(InvalidInvitationException::DATA_TELEGRAM_TIDAK_VALID);
        }

        return $id;
    }

    /** @param array<string, mixed> $telegramUser */
    private function username(array $telegramUser): ?string
    {
        $username = $telegramUser['username'] ?? null;

        if (! is_string($username)) {
            return null;
        }

        $username = mb_substr(ltrim(trim($username), '@'), 0, 64);

        return $username === '' ? null : $username;
    }

    /** @param array<string, mixed> $telegramUser */
    private function displayName(array $telegramUser): string
    {
        $parts = array_filter([$telegramUser['first_name'] ?? null, $telegramUser['last_name'] ?? null], 'is_string');
        $name = trim(implode(' ', $parts));

        if ($name === '') {
            $name = $this->username($telegramUser) ?? 'Pengguna Telegram';
        }

        return mb_substr($name, 0, 120);
    }
}

<?php

namespace App\Services\Auth;

use App\Models\PerangkatTepercaya;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sesi & token Sanctum R-03.
 *
 * - Token pra-login: ability `auth:pra-login` saja, kedaluwarsa config('kredensial.pra_login_menit'),
 *   hanya untuk rute masuk/aktivasi/reset/token. Satu per pengguna per perangkat. Tidak pernah diperpanjang.
 * - Token login penuh: ability `auth:penuh` + ability peran (Rbac). Terikat ke satu PerangkatTepercaya
 *   (personal_access_tokens.perangkat_tepercaya_id); satu sesi per perangkat, maks maksSesi() sesi per
 *   pengguna (login baru di atas batas mencabut sesi yang paling lama tidak dipakai).
 * - Masa sesi BERGESER: expires_at = pemakaian terakhir + masaHari() (90 hari; Admin 30), digeser maksimal
 *   sekali per config('kredensial.sesi.perpanjang_tiap_menit') per token (catatPemakaian) dan tidak pernah
 *   melewati BATAS ABSOLUT created_at + batasAbsolutHari() (180 hari). Token login penuh tidak pernah
 *   dirotasi, sehingga created_at = waktu login awal (tanpa kolom baru).
 * - Aturan kedaluwarsa (kedaluwarsaEfektif) berlaku untuk SEMUA token milik User, termasuk token lama
 *   tanpa expires_at (dihitung dari last_used_at/created_at), dan diperiksa pada setiap request (validasiToken).
 * - Kepercayaan perangkat (lewati token bot saat login) berlaku batasAbsolutHari() sejak diverifikasi_pada
 *   (token bot terakhir), TIDAK bergeser oleh pemakaian; maks maksSesi() perangkat tepercaya per pengguna. expires_at
 *   token login penuh tidak pernah melewati akhir kepercayaan perangkatnya (perangkatSampai).
 * - Pencabutan tetap seketika: logout, keluar semua, atau pencabutan otomatis (User::cabutSemuaSesi).
 * - device_id klien hanya disimpan sebagai HMAC-SHA256 dengan app key.
 */
class SesiService
{
    public const ABILITY_PRA_LOGIN = 'auth:pra-login';

    public const ABILITY_PENUH = 'auth:penuh';

    /** Penanda token pra-login dari bypass dev: tidak pernah boleh ditukar menjadi login penuh. */
    public const ABILITY_DEV = 'auth:dev';

    public const PREFIX_PRA_LOGIN = 'pra-login:';

    public const PREFIX_SESI = 'sesi:';

    /*
     * Batas keras di kode. Config/env hanya boleh MENGETATKAN (nilai lebih besar dijepit ke sini, nilai
     * < 1 menjadi 1): sesi tidak bisa dibuat lebih panjang/lebih banyak lewat konfigurasi server.
     */
    public const MAKS_ABSOLUT_HARI_KERAS = 180;

    public const MAKS_MASA_HARI_KERAS = 90;

    public const MAKS_MASA_HARI_ADMIN_KERAS = 30;

    public const MAKS_SESI_KERAS = 5;

    public static function hashPerangkat(string $deviceId): string
    {
        return hash_hmac('sha256', 'perangkat|'.$deviceId, (string) config('app.key'));
    }

    public static function tokenPenuh(PersonalAccessToken $token): bool
    {
        return in_array(self::ABILITY_PENUH, (array) $token->abilities, true);
    }

    // ------------------------------------------------------------------ masa sesi

    public function batasAbsolutHari(): int
    {
        return self::jepit(config('kredensial.sesi.batas_absolut_hari'), self::MAKS_ABSOLUT_HARI_KERAS);
    }

    /**
     * Masa sesi bergeser untuk peran $user (hari). Peran biasa <= 90 dan <= batas absolut; Admin <= 30 dan
     * tidak pernah lebih panjang dari masa peran biasa.
     */
    public function masaHari(User $user): int
    {
        $biasa = min(
            self::jepit(config('kredensial.sesi.masa_hari'), self::MAKS_MASA_HARI_KERAS),
            $this->batasAbsolutHari(),
        );

        if (! $user->isAdmin()) {
            return $biasa;
        }

        return min(self::jepit(config('kredensial.sesi.masa_hari_admin'), self::MAKS_MASA_HARI_ADMIN_KERAS), $biasa);
    }

    public function maksSesi(): int
    {
        return self::jepit(config('kredensial.sesi.maks_per_pengguna'), self::MAKS_SESI_KERAS);
    }

    /** Nilai config kosong -> batas keras; selain itu dijepit ke [1, batas keras] (hanya bisa mengetatkan). */
    private static function jepit(mixed $nilai, int $kerasMaks): int
    {
        return $nilai === null || $nilai === '' ? $kerasMaks : min($kerasMaks, max(1, (int) $nilai));
    }

    private function jedaPerpanjangMenit(): int
    {
        return max(1, (int) config('kredensial.sesi.perpanjang_tiap_menit', 60));
    }

    /** Batas absolut sesi: waktu login (created_at) + batasAbsolutHari(). */
    public function batasAbsolut(PersonalAccessToken $token): Carbon
    {
        // created_at kosong (baris tidak wajar): anggap sudah lewat (fail closed).
        return $token->created_at === null
            ? now()->subSecond()
            : $token->created_at->copy()->addDays($this->batasAbsolutHari());
    }

    /**
     * Saat token efektif berakhir = min(expires_at, pemakaian terakhir + masaHari, batas absolut).
     * Token lama tanpa expires_at mendapat batas dari last_used_at/created_at (tidak abadi), dan penurunan
     * config langsung berlaku untuk token yang sudah beredar.
     */
    public function kedaluwarsaEfektif(PersonalAccessToken $token, User $user): Carbon
    {
        $batas = $this->batasAbsolut($token);
        $dasar = $token->last_used_at ?? $token->created_at;

        if ($dasar !== null) {
            $batas = $batas->min($dasar->copy()->addDays($this->masaHari($user)));
        }

        return $token->expires_at === null ? $batas : $batas->min($token->expires_at);
    }

    /**
     * Callback Sanctum::authenticateAccessTokensUsing (setiap request ber-Bearer). Token yang dikenali tetapi
     * kedaluwarsa ditolak dan request ditandai agar 401 membawa `alasan: sesi_kedaluwarsa`.
     */
    public function validasiToken(PersonalAccessToken $token, bool $sah): bool
    {
        $user = $token->tokenable;

        if (! $user instanceof User) {
            return $sah;
        }

        $kedaluwarsa = ($token->expires_at !== null && $token->expires_at->isPast())
            || $this->kedaluwarsaEfektif($token, $user)->isPast()
            // Token lama tanpa expires_at belum pernah dijepit ke kepercayaan perangkat: periksa langsung
            // (satu kueri, hanya sampai pemakaian pertamanya mengisi expires_at).
            || ($token->expires_at === null && self::tokenPenuh($token) && ($this->perangkatSampai($token, $user)?->isPast() ?? false));

        if ($kedaluwarsa) {
            request()->attributes->set(GalatAuth::ATRIBUT_SESI_KEDALUWARSA, true);

            return false;
        }

        return $sah;
    }

    /**
     * Listener TokenAuthenticated (dan pertukaran initData `sesi_aktif`): tulis last_used_at dan, untuk token
     * login penuh, geser expires_at ke min(sekarang + masaHari, batas absolut). Maksimal SATU UPDATE per token
     * per jeda (bukan setiap request). UPDATE bersyarat: request paralel tidak menulis/mengaudit ganda.
     * Perpanjangan pertama pada hari kalender baru dicatat di audit (tanpa nilai token).
     */
    public function catatPemakaian(PersonalAccessToken $token): void
    {
        $user = $token->tokenable;

        if (! $user instanceof User || ! $user->canAuthenticate() || ! $token->exists) {
            return;
        }

        $sekarang = now();
        $ambang = $sekarang->copy()->subMinutes($this->jedaPerpanjangMenit());
        $penuh = self::tokenPenuh($token);
        $tanpaKedaluwarsa = $penuh && $token->expires_at === null;

        if (! $tanpaKedaluwarsa && $token->last_used_at !== null && $token->last_used_at->gt($ambang)) {
            return;
        }

        $nilai = ['last_used_at' => $sekarang];

        if ($penuh) {
            $sampai = $sekarang->copy()->addDays($this->masaHari($user))->min($this->batasAbsolut($token));
            $perangkat = $this->perangkatSampai($token, $user);
            $nilai['expires_at'] = $perangkat === null ? $sampai : $sampai->min($perangkat);
        }

        $sebelumnya = $token->last_used_at ?? $token->created_at;

        $diubah = PersonalAccessToken::query()->whereKey($token->getKey())
            ->where(function ($q) use ($ambang, $tanpaKedaluwarsa): void {
                $q->whereNull('last_used_at')->orWhere('last_used_at', '<=', $ambang);

                if ($tanpaKedaluwarsa) {
                    $q->orWhereNull('expires_at');
                }
            })
            ->update($nilai);

        if ($diubah !== 1) {
            return;
        }

        $token->forceFill($nilai)->syncOriginal();

        if ($penuh && ($sebelumnya === null || ! $sebelumnya->isSameDay($sekarang))) {
            AuditAuth::catat($user, AuditAuth::SESI_DIPERPANJANG, [
                'sesi_id' => (int) $token->getKey(),
                'perangkat_id' => $token->getAttribute('perangkat_tepercaya_id'),
                'berlaku_sampai' => $nilai['expires_at']->toIso8601String(),
            ]);
        }
    }

    /** Batas yang tidak dapat diperpanjang: min(login + batas absolut, akhir kepercayaan perangkat). */
    public function batasAkhir(PersonalAccessToken $token, User $user): Carbon
    {
        $perangkat = $this->perangkatSampai($token, $user);

        return $perangkat === null ? $this->batasAbsolut($token) : $this->batasAbsolut($token)->min($perangkat);
    }

    /**
     * Akhir kepercayaan perangkat tempat token terikat; null bila token tidak terikat perangkat. Perangkat hilang
     * atau sudah dicabut: sekarang (fail closed, token berakhir).
     */
    public function perangkatSampai(PersonalAccessToken $token, User $user): ?Carbon
    {
        $id = $token->getAttribute('perangkat_tepercaya_id');

        if ($id === null) {
            return null;
        }

        $perangkat = PerangkatTepercaya::query()->where('user_id', $user->getKey())->whereKey($id)->first();

        return $perangkat === null || $perangkat->dicabut_at !== null ? now() : $perangkat->berlakuSampai();
    }

    /**
     * Batasi kueri token pada yang masih berlaku menurut kedaluwarsaEfektif() (versi SQL).
     *
     * @template T of Builder|Relation
     *
     * @param  T  $query
     * @return T
     */
    public function hanyaBerlaku(Builder|Relation $query, User $user): Builder|Relation
    {
        $sekarang = now();

        return $query
            ->where('created_at', '>', $sekarang->copy()->subDays($this->batasAbsolutHari()))
            ->whereRaw('COALESCE(last_used_at, created_at) > ?', [$sekarang->copy()->subDays($this->masaHari($user))])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $sekarang))
            // Token terikat perangkat hanya berlaku selama kepercayaan perangkatnya berlaku (fail closed).
            ->where(fn ($q) => $q->whereNull('perangkat_tepercaya_id')->orWhereIn(
                'perangkat_tepercaya_id',
                PerangkatTepercaya::query()->select('id')->where('user_id', $user->getKey())->aktif(),
            ));
    }

    // ------------------------------------------------------------------ penerbitan

    /**
     * @return array{token: string, expires_at: Carbon}
     */
    public function terbitkanPraLogin(User $user, ?string $deviceHash, bool $dev = false): array
    {
        $nama = self::PREFIX_PRA_LOGIN.($deviceHash === null ? '-' : substr($deviceHash, 0, 16));
        $expiresAt = now()->addMinutes(max(1, (int) config('kredensial.pra_login_menit', 15)));
        $abilities = $dev ? [self::ABILITY_PRA_LOGIN, self::ABILITY_DEV] : [self::ABILITY_PRA_LOGIN];

        return DB::transaction(function () use ($user, $nama, $expiresAt, $abilities): array {
            // Satu token pra-login per perangkat; token pra-login kedaluwarsa ikut dibersihkan.
            $user->tokens()
                ->where('name', 'like', self::PREFIX_PRA_LOGIN.'%')
                ->where(fn ($q) => $q->where('name', $nama)->orWhere('expires_at', '<', now()))
                ->delete();

            $token = $user->createToken($nama, $abilities, $expiresAt);

            return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Terbitkan token login penuh untuk perangkat.
     * Pemanggil WAJIB sudah memverifikasi password. $lewatTokenBot = true HANYA setelah token 6 digit benar: perangkat
     * (baru) dipercaya dan masa kepercayaan 180 hari dimulai ulang. $lewatTokenBot = false (perangkat tepercaya,
     * tanpa token bot): baris perangkat dikunci dan WAJIB masih berlaku, selain itu PerangkatTidakTepercayaException
     * (transaksi dibatalkan, tidak ada token/kepercayaan baru).
     * expires_at = sekarang + masaHari(); login baru di atas maksSesi() mencabut sesi yang paling lama tidak
     * dipakai (rapikanSesi).
     *
     * @return array{token: string, user: User, expires_at: Carbon}
     */
    public function terbitkanPenuh(User $user, string $deviceHash, ?string $label, ?PersonalAccessToken $praLogin, bool $lewatTokenBot): array
    {
        return DB::transaction(function () use ($user, $deviceHash, $label, $praLogin, $lewatTokenBot): array {
            // Kunci baris pengguna: login paralel diserialkan sehingga rapikanSesi tidak melampaui maksSesi()
            // (MySQL InnoDB: SELECT ... FOR UPDATE; SQLite tes: tanpa efek, kunci tingkat basis data).
            User::withoutGlobalScopes()->withTrashed()->whereKey($user->getKey())->lockForUpdate()->first();

            $perangkat = $this->percayai($user, $deviceHash, $label, $lewatTokenBot);

            // Satu sesi per perangkat: sesi lama perangkat ini diganti.
            $user->tokens()->where('perangkat_tepercaya_id', $perangkat->getKey())->delete();

            // Tidak pernah melewati akhir kepercayaan perangkat (180 hari sejak token bot terakhir).
            $expiresAt = now()->addDays($this->masaHari($user))->min($perangkat->berlakuSampai());

            /** @var NewAccessToken $baru */
            $baru = $user->createToken(
                self::PREFIX_SESI.$perangkat->getKey(),
                [self::ABILITY_PENUH, ...Rbac::abilities($user)],
                $expiresAt,
            );
            $baru->accessToken->forceFill(['perangkat_tepercaya_id' => $perangkat->getKey()])->save();

            $praLogin?->delete();
            $this->rapikanSesi($user, (int) $baru->accessToken->getKey());
            $this->rapikanPerangkat($user, (int) $perangkat->getKey());

            $now = now();
            User::withoutGlobalScopes()->whereKey($user->getKey())->update([
                'last_login_at' => $now, 'failed_login_attempts' => 0, 'locked_until' => null,
            ]);
            $user->setRawAttributes(array_merge($user->getAttributes(), [
                'last_login_at' => $now, 'failed_login_attempts' => 0, 'locked_until' => null,
            ]), true);

            return ['token' => $baru->plainTextToken, 'user' => $user, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Setelah login penuh: token login penuh lain yang sudah kedaluwarsa dihapus (kepercayaan perangkat tetap),
     * lalu bila jumlah sesi berlaku melebihi maksSesi(), sesi yang paling lama tidak dipakai (last_used_at, lalu
     * created_at, lalu id) dicabut seperti logout dan dicatat di audit.
     */
    private function rapikanSesi(User $user, int $tokenBaruId): void
    {
        $lain = $user->tokens()->where('name', 'like', self::PREFIX_SESI.'%')->whereKeyNot($tokenBaruId)->get();
        [$kedaluwarsa, $berlaku] = $lain->partition(fn (PersonalAccessToken $t) => $this->kedaluwarsaEfektif($t, $user)->isPast());

        if ($kedaluwarsa->isNotEmpty()) {
            $user->tokens()->whereKey($kedaluwarsa->modelKeys())->delete();
        }

        $maks = $this->maksSesi();
        $lebih = $berlaku->count() + 1 - $maks;

        if ($lebih <= 0) {
            return;
        }

        $berlaku
            ->sortBy(fn (PersonalAccessToken $t) => sprintf('%015d.%015d', ($t->last_used_at ?? $t->created_at)?->getTimestamp() ?? 0, $t->getKey()))
            ->take($lebih)
            ->each(function (PersonalAccessToken $t) use ($user, $maks): void {
                $this->logout($user, $t);
                AuditAuth::catat($user, AuditAuth::SESI_DICABUT_BATAS, [
                    'sesi_id' => (int) $t->getKey(),
                    'perangkat_id' => $t->getAttribute('perangkat_tepercaya_id'),
                    'maks_sesi' => $maks,
                ]);
            });
    }

    /**
     * Perangkat tepercaya berlaku tidak boleh melebihi maksSesi(). Kelebihan dicabut (beserta sesinya bila ada):
     * perangkat tanpa sesi lebih dulu, lalu yang paling lama diverifikasi. Perangkat login saat ini tidak pernah.
     */
    private function rapikanPerangkat(User $user, int $perangkatIniId): void
    {
        $lain = $user->perangkatTepercaya()->aktif()->whereKeyNot($perangkatIniId)->get();
        $maks = $this->maksSesi();
        $lebih = $lain->count() + 1 - $maks;

        if ($lebih <= 0) {
            return;
        }

        $bersesi = $user->tokens()->whereIn('perangkat_tepercaya_id', $lain->modelKeys())
            ->pluck('perangkat_tepercaya_id')->map(fn ($id) => (int) $id)->flip();

        $lain
            ->sortBy(fn (PerangkatTepercaya $p) => sprintf('%d.%015d.%015d', $bersesi->has($p->getKey()) ? 1 : 0, $p->diverifikasi_pada->getTimestamp(), $p->getKey()))
            ->take($lebih)
            ->each(function (PerangkatTepercaya $p) use ($user, $maks): void {
                $jumlah = (int) $user->tokens()->where('perangkat_tepercaya_id', $p->getKey())->delete();
                $p->forceFill(['dicabut_at' => now()])->save();
                AuditAuth::catat($user, AuditAuth::PERANGKAT_DICABUT_BATAS, [
                    'perangkat_id' => (int) $p->getKey(),
                    'jumlah_sesi' => $jumlah,
                    'maks_perangkat' => $maks,
                ]);
            });
    }

    /**
     * Tandai dicabut kepercayaan perangkat yang sudah lewat masa (pembersihan harian; baris perangkat tidak dihapus)
     * dan hapus token sesi yang terikat padanya (termasuk token lama tanpa expires_at). Idempoten.
     *
     * @return int jumlah perangkat yang ditandai dicabut
     */
    public function bersihkanPerangkatKedaluwarsa(): int
    {
        return DB::transaction(function (): int {
            $ids = PerangkatTepercaya::query()->belumDicabut()
                ->where('diverifikasi_pada', '<=', now()->subDays($this->batasAbsolutHari()))
                ->lockForUpdate()->pluck('id')->all();

            if ($ids === []) {
                return 0;
            }

            PersonalAccessToken::query()->whereIn('perangkat_tepercaya_id', $ids)->delete();

            return PerangkatTepercaya::query()->whereKey($ids)->update(['dicabut_at' => now()]);
        });
    }

    // ------------------------------------------------------------------ pemeriksaan

    /** Perangkat tepercaya aktif milik pengguna untuk device_hash, atau null. */
    public function perangkatAktif(User $user, string $deviceHash): ?PerangkatTepercaya
    {
        return $user->perangkatTepercaya()->aktif()->where('device_hash', $deviceHash)->first();
    }

    /**
     * Token Bearer milik $user yang merupakan sesi login penuh BERLAKU (kedaluwarsaEfektif) di perangkat ini,
     * atau null (dipakai saat Mini App dibuka ulang: initData valid + sesi tersimpan).
     */
    public function sesiAktifUntuk(User $user, ?string $bearer, ?string $deviceHash): ?PersonalAccessToken
    {
        if ($bearer === null || $bearer === '' || $deviceHash === null) {
            return null;
        }

        $token = PersonalAccessToken::findToken($bearer);

        if (! $token instanceof PersonalAccessToken
            || $token->tokenable_type !== $user->getMorphClass()
            || (int) $token->tokenable_id !== (int) $user->getKey()
            || ! self::tokenPenuh($token)
            || $this->kedaluwarsaEfektif($token, $user)->isPast()
            || $token->getAttribute('perangkat_tepercaya_id') === null) {
            return null;
        }

        $cocok = $user->perangkatTepercaya()->aktif()
            ->whereKey($token->getAttribute('perangkat_tepercaya_id'))
            ->where('device_hash', $deviceHash)
            ->exists();

        return $cocok ? $token : null;
    }

    /**
     * Token login penuh yang dikirim klien bersama initData milik akun LAIN (mis. HP dipakai akun
     * Telegram berbeda) dicabut beserta kepercayaan perangkatnya, karena tidak bisa lagi dipastikan
     * dipegang pemiliknya. Token tidak diikat ke initData di setiap request (akses website R-04).
     */
    public function cabutBilaMilikAkunLain(User $user, ?string $bearer): bool
    {
        if ($bearer === null || $bearer === '') {
            return false;
        }

        $token = PersonalAccessToken::findToken($bearer);
        $pemilik = $token?->tokenable;

        if (! $token instanceof PersonalAccessToken
            || ! $pemilik instanceof User
            || $pemilik->is($user)
            || ! self::tokenPenuh($token)) {
            return false;
        }

        $this->logout($pemilik, $token);
        AuditAuth::catat($pemilik, AuditAuth::SESI_DICABUT_AKUN_BERBEDA, [
            'perangkat_id' => $token->getAttribute('perangkat_tepercaya_id'),
        ]);

        return true;
    }

    /**
     * Pemeriksaan sesi untuk Bot (R-02): pengguna aktif dengan minimal satu sesi login penuh BERLAKU.
     * Menu bot yang membuka data wajib memanggil ini sebelum melayani.
     */
    public function punyaSesiPenuh(User $user): bool
    {
        if (! $user->canAuthenticate()) {
            return false;
        }

        return $this->hanyaBerlaku($user->tokens(), $user)
            ->where('name', 'like', self::PREFIX_SESI.'%')
            ->whereNotNull('perangkat_tepercaya_id')
            ->exists();
    }

    /**
     * Sesi login penuh berlaku terbaru milik pengguna (kriteria sama dengan punyaSesiPenuh), atau null. Dipakai
     * eksekusi aksi Agen AI dari Bot sebagai identitas sub-request internal (PermintaanInternal): tanpa sesi
     * penuh tidak ada aksi yang dijalankan.
     */
    public function tokenSesiPenuh(User $user): ?PersonalAccessToken
    {
        if (! $user->canAuthenticate()) {
            return null;
        }

        $token = $this->hanyaBerlaku($user->tokens(), $user)
            ->where('name', 'like', self::PREFIX_SESI.'%')
            ->whereNotNull('perangkat_tepercaya_id')
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->first();

        return $token instanceof PersonalAccessToken && self::tokenPenuh($token) ? $token : null;
    }

    /**
     * Daftar sesi login penuh BERLAKU milik pengguna (terbaru dulu). `berlaku_sampai` = kedaluwarsaEfektif.
     *
     * @return Collection<int, array{id: int, perangkat: string|null, dibuat_pada: string|null, terakhir_dipakai_pada: string|null, berlaku_sampai: string, diverifikasi_pada: string|null, sesi_ini: bool}>
     */
    public function daftarSesi(User $user, ?int $tokenSaatIniId): Collection
    {
        $tokens = $this->hanyaBerlaku($user->tokens(), $user)
            ->where('name', 'like', self::PREFIX_SESI.'%')
            ->whereNotNull('perangkat_tepercaya_id')
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get();

        $perangkat = PerangkatTepercaya::query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $tokens->pluck('perangkat_tepercaya_id')->filter()->all())
            ->get()->keyBy('id');

        return $tokens->map(function (PersonalAccessToken $token) use ($perangkat, $tokenSaatIniId, $user): array {
            $p = $perangkat->get($token->getAttribute('perangkat_tepercaya_id'));

            return [
                'id' => (int) $token->getKey(),
                'perangkat' => $p?->label,
                'dibuat_pada' => $token->created_at?->toIso8601String(),
                'terakhir_dipakai_pada' => $token->last_used_at?->toIso8601String(),
                'berlaku_sampai' => $this->kedaluwarsaEfektif($token, $user)->toIso8601String(),
                'diverifikasi_pada' => $p?->diverifikasi_pada?->toIso8601String(),
                'sesi_ini' => $tokenSaatIniId !== null && (int) $token->getKey() === $tokenSaatIniId,
            ];
        })->values();
    }

    // ------------------------------------------------------------------ pencabutan

    /** Logout perangkat ini: token dihapus dan kepercayaan perangkat dicabut. */
    public function logout(User $user, PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($user, $token): void {
            $perangkatId = $token->getAttribute('perangkat_tepercaya_id');
            $token->delete();

            if ($perangkatId !== null) {
                $user->perangkatTepercaya()->belumDicabut()->whereKey($perangkatId)->update(['dicabut_at' => now()]);
            }
        });
    }

    /**
     * Cabut satu sesi login penuh MILIK $pemilik (token + kepercayaan perangkatnya). Id sesi milik
     * akun lain diperlakukan sama dengan tidak ada (null -> 404 di controller, cegah IDOR).
     */
    public function cabutSatu(User $pemilik, int $sesiId): ?PersonalAccessToken
    {
        $token = $pemilik->tokens()
            ->where('name', 'like', self::PREFIX_SESI.'%')
            ->whereKey($sesiId)
            ->first();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $this->logout($pemilik, $token);

        return $token;
    }

    /** Keluar dari semua perangkat (termasuk perangkat ini). Mengembalikan jumlah sesi penuh yang dicabut. */
    public function keluarSemua(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $jumlah = $user->tokens()->where('name', 'like', self::PREFIX_SESI.'%')->count();
            $user->cabutSemuaSesi();

            return $jumlah;
        });
    }

    /**
     * Dipanggil di dalam transaksi terbitkanPenuh. Hanya jalur token bot yang boleh mengisi diverifikasi_pada = now()
     * dan menghapus dicabut_at; jalur tepercaya hanya memperbarui label pada baris yang dikunci dan masih berlaku.
     */
    private function percayai(User $user, string $deviceHash, ?string $label, bool $lewatTokenBot): PerangkatTepercaya
    {
        $label = $label === null ? null : (mb_substr(trim(strip_tags($label)), 0, 120) ?: null);

        /** @var PerangkatTepercaya|null $perangkat */
        $perangkat = $user->perangkatTepercaya()->where('device_hash', $deviceHash)->lockForUpdate()->first();

        if (! $lewatTokenBot) {
            if ($perangkat === null || ! $perangkat->berlaku()) {
                throw new PerangkatTidakTepercayaException('Perangkat tidak lagi tepercaya.');
            }

            $perangkat->forceFill(['label' => $label ?? $perangkat->label])->save();

            return $perangkat;
        }

        $perangkat ??= $user->perangkatTepercaya()->make(['device_hash' => $deviceHash]);
        $perangkat->forceFill([
            'label' => $label ?? $perangkat->label,
            // Token bot baru saja benar: masa kepercayaan 180 hari selalu dimulai dari sekarang.
            'diverifikasi_pada' => now(),
            'dicabut_at' => null,
        ])->save();

        return $perangkat;
    }
}

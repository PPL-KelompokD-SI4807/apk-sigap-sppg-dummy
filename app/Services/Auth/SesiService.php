<?php

namespace App\Services\Auth;

use App\Models\BrowserTepercaya;
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
use Symfony\Component\HttpFoundation\Cookie;

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
 *
 * Sesi WEB (R-04, ADR 2026-11-09): token login penuh yang sama jenisnya + ability `klien:web`, nama
 * `web:<label>`, tanpa perangkat_tepercaya_id, HANYA dibawa cookie (SesiWeb/SesiWebCookie). Aturan tambahan:
 * - Kuota TERPISAH (Q2): maks maksSesi() sesi Mini App (`sesi:*`) + maks maksSesiWeb() sesi web (`web:*`);
 *   login satu jenis hanya mencabut sesi jenis yang sama yang paling lama tidak dipakai; sesi baru tidak pernah.
 * - Idle (Q3): sesi web biasa berakhir idleWebJam() jam setelah terakhir dipakai (expires_at ikut dijepit), jeda
 *   tulis last_used_at token web <= 15 menit. Pengecualian browser tepercaya (R04-3): sesi web ber-
 *   browser_tepercaya_id tidak terkena idle, mengikuti 90 hari sliding, dan tidak pernah melewati berlaku_sampai
 *   browser itu (browserSampai; browser dicabut/hilang = berakhir sekarang, fail closed).
 * - Penolakan silang (validasiToken): token web lewat Bearer -> 401; token non-web lewat cookie -> 401.
 * - Jenis sesi (issue #79): jenis ditentukan ability `klien:web` (tokenWeb/jenis). Nama `web:` dipakai kueri SQL;
 *   invarian "nama `web:` <=> ability `klien:web`" ditegakkan saat validasi (jenisKonsisten, fail closed).
 */
class SesiService
{
    public const ABILITY_PRA_LOGIN = 'auth:pra-login';

    public const ABILITY_PENUH = 'auth:penuh';

    /** Penanda token sesi web (R-04): hanya sah bila dibawa cookie `__Host-sigap_sesi`. */
    public const ABILITY_WEB = 'klien:web';

    public const PREFIX_WEB = 'web:';

    public const JENIS_MINIAPP = 'miniapp';

    public const JENIS_WEB = 'web';

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

    /** Kuota sesi web terpisah (Q2). */
    public const MAKS_SESI_WEB_KERAS = 3;

    /** Idle sesi web biasa (Q3). */
    public const IDLE_WEB_JAM_KERAS = 12;

    /** Jeda tulis last_used_at token web agar idle akurat. */
    public const JEDA_TULIS_WEB_MENIT_KERAS = 15;

    public static function hashPerangkat(string $deviceId): string
    {
        return hash_hmac('sha256', 'perangkat|'.$deviceId, (string) config('app.key'));
    }

    public static function tokenPenuh(PersonalAccessToken $token): bool
    {
        return in_array(self::ABILITY_PENUH, (array) $token->abilities, true);
    }

    /** Token sesi web (ability `klien:web`, R-04). */
    public static function tokenWeb(PersonalAccessToken $token): bool
    {
        return in_array(self::ABILITY_WEB, (array) $token->abilities, true);
    }

    public static function jenis(PersonalAccessToken $token): string
    {
        return self::tokenWeb($token) ? self::JENIS_WEB : self::JENIS_MINIAPP;
    }

    /**
     * Invarian jenis sesi (issue #79): nama berprefiks `web:` jika dan hanya jika ability `klien:web`. Kueri SQL
     * (hanyaBerlaku, rapikanSesi, daftarSesi) memakai prefiks nama, sedangkan kenaIdle/validasiToken memakai
     * ability; token yang melanggar invarian ditolak (tidak pernah dibuat oleh kode ini).
     */
    public static function jenisKonsisten(PersonalAccessToken $token): bool
    {
        return str_starts_with((string) $token->name, self::PREFIX_WEB) === self::tokenWeb($token);
    }

    /**
     * Batasi kueri token pada sesi login penuh kedua jenis: Mini App (`sesi:*`) dan web (`web:*`).
     *
     * @template T of Builder|Relation
     *
     * @param  T  $query
     * @return T
     */
    public static function hanyaSesiLogin(Builder|Relation $query): Builder|Relation
    {
        return $query->where(fn ($q) => $q
            ->where('name', 'like', self::PREFIX_SESI.'%')
            ->orWhere('name', 'like', self::PREFIX_WEB.'%'));
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

    /** Maks sesi web per pengguna (Q2), terpisah dari maksSesi() Mini App. */
    public function maksSesiWeb(): int
    {
        return self::jepit(config('kredensial.sesi.maks_web_per_pengguna'), self::MAKS_SESI_WEB_KERAS);
    }

    /** Batas idle sesi web biasa (jam, Q3). */
    public function idleWebJam(): int
    {
        return self::jepit(config('kredensial.sesi.idle_web_jam'), self::IDLE_WEB_JAM_KERAS);
    }

    /** Token terkena batas idle: token web yang tidak dikecualikan. Sesi Mini App tidak pernah. */
    public function kenaIdle(PersonalAccessToken $token): bool
    {
        return self::tokenWeb($token) && ! $this->dikecualikanDariIdle($token);
    }

    /**
     * R04-3: sesi web yang terbit lewat browser tepercaya (personal_access_tokens.browser_tepercaya_id terisi)
     * dikecualikan dari idle, mengikuti 90 hari sliding dan tidak melewati `berlaku_sampai` browser itu (jepit lewat
     * terbitkanWeb/catatPemakaian/batasAkhir, diperiksa ulang di validasiToken). Padanan SQL: hanyaBerlaku().
     */
    protected function dikecualikanDariIdle(PersonalAccessToken $token): bool
    {
        return self::tokenWeb($token) && $token->getAttribute('browser_tepercaya_id') !== null;
    }

    /** Jeda tulis last_used_at/expires_at per token: token web <= 15 menit; Mini App tetap config. */
    private function jedaTulisMenit(PersonalAccessToken $token): int
    {
        if (! self::tokenWeb($token)) {
            return $this->jedaPerpanjangMenit();
        }

        return min(
            $this->jedaPerpanjangMenit(),
            self::jepit(config('kredensial.sesi.jeda_tulis_web_menit'), self::JEDA_TULIS_WEB_MENIT_KERAS),
        );
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

            if ($this->kenaIdle($token)) {
                $batas = $batas->min($dasar->copy()->addHours($this->idleWebJam()));
            }
        }

        return $token->expires_at === null ? $batas : $batas->min($token->expires_at);
    }

    /**
     * Callback Sanctum::authenticateAccessTokensUsing (setiap request ber-Bearer). Token yang dikenali tetapi
     * kedaluwarsa ditolak dan request ditandai agar 401 membawa `alasan: sesi_kedaluwarsa`.
     */
    public function validasiToken(PersonalAccessToken $token, bool $sah): bool
    {
        // Penolakan silang R-04 (sebelum pemeriksaan masa, tanpa `alasan`): token web hanya sah lewat cookie,
        // token lain hanya sah lewat Bearer. Jalur dibaca dari request() global; jalur tidak dikenal = Bearer, jadi
        // pemeriksaan ini fail closed HANYA untuk token web. Bila request() berbeda dari request guard, token
        // Mini App lewat cookie bisa lolos di sini; itu ditutup oleh PastikanJalurSesi (pemeriksaan kedua pada
        // request pipeline, sesudah auth:sanctum dan sebelum controller).
        if (self::tokenWeb($token) !== SesiWeb::jalurCookie(request()) || ! self::jenisKonsisten($token)) {
            return false;
        }

        $user = $token->tokenable;

        if (! $user instanceof User) {
            return $sah;
        }

        $kedaluwarsa = ($token->expires_at !== null && $token->expires_at->isPast())
            || $this->kedaluwarsaEfektif($token, $user)->isPast()
            // Token lama tanpa expires_at belum pernah dijepit ke kepercayaan perangkat: periksa langsung
            // (satu kueri, hanya sampai pemakaian pertamanya mengisi expires_at).
            || ($token->expires_at === null && self::tokenPenuh($token) && ($this->perangkatSampai($token, $user)?->isPast() ?? false))
            // Sesi turunan browser tepercaya (R04-3): browser dicabut/lewat 30 hari -> sesi berakhir (fail closed,
            // melengkapi penghapusan berantai saat pencabutan).
            || ($this->browserSampai($token, $user)?->isPast() ?? false);

        if ($kedaluwarsa) {
            request()->attributes->set(GalatAuth::ATRIBUT_SESI_KEDALUWARSA, true);

            // Sesi web yang berakhir (idle 12 jam/bergeser/absolut) dicabut di server (S-22); sesi Mini App
            // tetap seperti PR #55 (dibersihkan saat login berikutnya).
            if (self::tokenWeb($token)) {
                $token->delete();
            }

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
        $ambang = $sekarang->copy()->subMinutes($this->jedaTulisMenit($token));
        $penuh = self::tokenPenuh($token);
        $tanpaKedaluwarsa = $penuh && $token->expires_at === null;

        if (! $tanpaKedaluwarsa && $token->last_used_at !== null && $token->last_used_at->gt($ambang)) {
            return;
        }

        $nilai = ['last_used_at' => $sekarang];

        if ($penuh) {
            $sampai = $sekarang->copy()->addDays($this->masaHari($user))->min($this->batasAbsolut($token));

            if ($this->kenaIdle($token)) {
                $sampai = $sampai->min($sekarang->copy()->addHours($this->idleWebJam()));
            }

            $perangkat = $this->perangkatSampai($token, $user);
            $sampai = $perangkat === null ? $sampai : $sampai->min($perangkat);
            $browser = $this->browserSampai($token, $user);
            $nilai['expires_at'] = $browser === null ? $sampai : $sampai->min($browser);
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

        // Jalur cookie: SesiWebCookie menerbitkan ulang cookie dengan Max-Age = expires_at baru.
        if ($penuh && self::tokenWeb($token)) {
            request()->attributes->set(SesiWeb::ATRIBUT_SAMPAI, $nilai['expires_at']->copy());
        }

        if ($penuh && ($sebelumnya === null || ! $sebelumnya->isSameDay($sekarang))) {
            AuditAuth::catat($user, AuditAuth::SESI_DIPERPANJANG, [
                'sesi_id' => (int) $token->getKey(),
                'perangkat_id' => $token->getAttribute('perangkat_tepercaya_id'),
                'berlaku_sampai' => $nilai['expires_at']->toIso8601String(),
                'jenis' => self::jenis($token),
            ]);
        }
    }

    /** Batas yang tidak dapat diperpanjang: min(login + batas absolut, akhir kepercayaan perangkat/browser). */
    public function batasAkhir(PersonalAccessToken $token, User $user): Carbon
    {
        $batas = $this->batasAbsolut($token);
        $perangkat = $this->perangkatSampai($token, $user);
        $browser = $this->browserSampai($token, $user);

        $batas = $perangkat === null ? $batas : $batas->min($perangkat);

        return $browser === null ? $batas : $batas->min($browser);
    }

    /**
     * Akhir browser tepercaya (R04-3) tempat sesi web terbit; null bila token tidak terikat browser. Browser hilang,
     * milik akun lain, atau sudah dicabut: sekarang (fail closed, sesi berakhir).
     */
    public function browserSampai(PersonalAccessToken $token, User $user): ?Carbon
    {
        $id = $token->getAttribute('browser_tepercaya_id');

        if ($id === null) {
            return null;
        }

        $browser = BrowserTepercaya::query()->where('user_id', $user->getKey())->whereKey($id)->first();

        // Dicabut/hilang: satu detik lalu, sehingga isPast() benar pada detik yang sama (fail closed).
        return $browser === null || $browser->dicabut_at !== null ? now()->subSecond() : $browser->berlaku_sampai->copy();
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
            // Idle sesi web (Q3); sesi turunan browser tepercaya dikecualikan (dikecualikanDariIdle).
            ->where(fn ($q) => $q->where('name', 'not like', self::PREFIX_WEB.'%')
                ->orWhereNotNull('browser_tepercaya_id')
                ->orWhereRaw('COALESCE(last_used_at, created_at) > ?', [$sekarang->copy()->subHours($this->idleWebJam())]))
            // Sesi turunan browser tepercaya hanya berlaku selama browser itu berlaku (fail closed, R04-3).
            ->where(fn ($q) => $q->whereNull('browser_tepercaya_id')->orWhereIn(
                'browser_tepercaya_id',
                BrowserTepercaya::query()->select('id')->where('user_id', $user->getKey())->aktif(),
            ))
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
     * Terbitkan sesi WEB (R-04): token login penuh + `klien:web`, dibawa HANYA lewat cookie (SesiWeb::cookieSesi
     * dengan expires_at hasil ini). Pemanggil (alur login web R04-2) WAJIB sudah memverifikasi sandi + kode bot,
     * ATAU sandi + browser tepercaya $browser yang berlaku milik $user (R04-3). Sesi ber-$browser tidak terkena idle
     * 12 jam dan expires_at-nya tidak pernah melewati berlaku_sampai browser itu.
     * Tidak membuat/memakai perangkat_tepercayas. Kuota web terpisah: login di atas maksSesiWeb() mencabut sesi web
     * yang paling lama tidak dipakai; sesi Mini App dan sesi yang baru terbit tidak pernah ikut dicabut. Baris
     * pengguna dikunci (lockForUpdate) sehingga login web/Mini App paralel diserialkan.
     *
     * Issue #76: token mentah TIDAK dikembalikan sebagai string; ia hanya keluar sebagai objek Cookie
     * `__Host-sigap_sesi` siap pasang (Max-Age = expires_at) beserta token CSRF terikat sesi, sehingga pemanggil
     * tidak dapat menaruhnya di badan JSON/log secara tidak sengaja.
     *
     * @return array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int}
     */
    public function terbitkanWeb(User $user, ?string $label, ?BrowserTepercaya $browser = null): array
    {
        if (! $user->canAuthenticate()) {
            throw new \DomainException('Akun tidak dapat masuk.');
        }

        if ($browser !== null && ((int) $browser->user_id !== (int) $user->getKey() || ! $browser->berlaku() || $user->isAdmin())) {
            throw new \DomainException('Browser tidak tepercaya untuk akun ini.');
        }

        return DB::transaction(function () use ($user, $label, $browser): array {
            User::withoutGlobalScopes()->withTrashed()->whereKey($user->getKey())->lockForUpdate()->first();

            $sekarang = now();
            $expiresAt = $sekarang->copy()->addDays($this->masaHari($user))
                ->min($sekarang->copy()->addDays($this->batasAbsolutHari()));

            // Sesi web biasa terkena idle 12 jam (Q3); sesi browser tepercaya dijepit ke berlaku_sampai browser.
            $expiresAt = $browser === null
                ? $expiresAt->min($sekarang->copy()->addHours($this->idleWebJam()))
                : $expiresAt->min($browser->berlaku_sampai);

            /** @var NewAccessToken $baru */
            $baru = $user->createToken(
                self::PREFIX_WEB.self::rapikanLabel($label),
                [self::ABILITY_PENUH, self::ABILITY_WEB, ...Rbac::abilities($user)],
                $expiresAt,
            );
            $id = (int) $baru->accessToken->getKey();

            if ($browser !== null) {
                $baru->accessToken->forceFill(['browser_tepercaya_id' => $browser->getKey()])->save();
            }

            $this->rapikanSesi($user, $id, self::PREFIX_WEB, $this->maksSesiWeb());

            User::withoutGlobalScopes()->whereKey($user->getKey())->update(['last_login_at' => $sekarang]);
            $user->setRawAttributes(array_merge($user->getAttributes(), ['last_login_at' => $sekarang]), true);

            return [
                'cookie' => SesiWeb::cookieSesi($baru->plainTextToken, $expiresAt),
                'csrf' => SesiWeb::csrfSesi($baru->plainTextToken),
                'user' => $user,
                'expires_at' => $expiresAt,
                'sesi_id' => $id,
            ];
        });
    }

    /** Label browser ringkas (tanpa tag, maks 120 karakter); kosong -> "Browser". */
    private static function rapikanLabel(?string $label): string
    {
        $label = $label === null ? '' : mb_substr(trim(strip_tags($label)), 0, 120);

        return $label === '' ? 'Browser' : $label;
    }

    /**
     * Setelah login penuh: token sesi lain SEJENIS ($prefix: `sesi:` Mini App atau `web:` web) yang sudah kedaluwarsa
     * dihapus (kepercayaan perangkat tetap), lalu bila jumlah sesi berlaku sejenis melebihi $maks (default
     * maksSesi()), sesi yang paling lama tidak dipakai (last_used_at, lalu created_at, lalu id) dicabut seperti
     * logout dan dicatat di audit. Sesi jenis lain dan token baru tidak pernah tersentuh (Q2).
     */
    private function rapikanSesi(User $user, int $tokenBaruId, string $prefix = self::PREFIX_SESI, ?int $maks = null): void
    {
        $lain = $user->tokens()->where('name', 'like', $prefix.'%')->whereKeyNot($tokenBaruId)->get();
        [$kedaluwarsa, $berlaku] = $lain->partition(fn (PersonalAccessToken $t) => $this->kedaluwarsaEfektif($t, $user)->isPast());

        if ($kedaluwarsa->isNotEmpty()) {
            $user->tokens()->whereKey($kedaluwarsa->modelKeys())->delete();
        }

        $maks ??= $this->maksSesi();
        $lebih = $berlaku->count() + 1 - $maks;

        if ($lebih <= 0) {
            return;
        }

        $jenis = $prefix === self::PREFIX_WEB ? self::JENIS_WEB : self::JENIS_MINIAPP;

        $berlaku
            ->sortBy(fn (PersonalAccessToken $t) => sprintf('%015d.%015d', ($t->last_used_at ?? $t->created_at)?->getTimestamp() ?? 0, $t->getKey()))
            ->take($lebih)
            ->each(function (PersonalAccessToken $t) use ($user, $maks, $jenis): void {
                $this->logout($user, $t);
                AuditAuth::catat($user, AuditAuth::SESI_DICABUT_BATAS, [
                    'sesi_id' => (int) $t->getKey(),
                    'perangkat_id' => $t->getAttribute('perangkat_tepercaya_id'),
                    'maks_sesi' => $maks,
                    'jenis' => $jenis,
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
     * Sesi Mini App (`jenis: miniapp`, terikat perangkat) dan sesi web (`jenis: web`, label browser) R-04.
     *
     * @return Collection<int, array{id: int, jenis: string, perangkat: string|null, dibuat_pada: string|null, terakhir_dipakai_pada: string|null, berlaku_sampai: string, diverifikasi_pada: string|null, browser_tepercaya_id: int|null, sesi_ini: bool}>
     */
    public function daftarSesi(User $user, ?int $tokenSaatIniId): Collection
    {
        $tokens = $this->hanyaBerlaku($user->tokens(), $user)
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->where('name', 'like', self::PREFIX_SESI.'%')->whereNotNull('perangkat_tepercaya_id'))
                ->orWhere('name', 'like', self::PREFIX_WEB.'%'))
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get()
            // Sesi web: pemeriksaan PHP (idle) melengkapi SQL. Daftar Mini App tidak berubah.
            ->reject(fn (PersonalAccessToken $t) => self::tokenWeb($t) && $this->kedaluwarsaEfektif($t, $user)->isPast());

        $perangkat = PerangkatTepercaya::query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $tokens->pluck('perangkat_tepercaya_id')->filter()->all())
            ->get()->keyBy('id');

        return $tokens->map(function (PersonalAccessToken $token) use ($perangkat, $tokenSaatIniId, $user): array {
            $p = $perangkat->get($token->getAttribute('perangkat_tepercaya_id'));
            $web = self::tokenWeb($token);

            return [
                'id' => (int) $token->getKey(),
                'jenis' => $web ? self::JENIS_WEB : self::JENIS_MINIAPP,
                'perangkat' => $web ? (mb_substr((string) $token->name, strlen(self::PREFIX_WEB)) ?: null) : $p?->label,
                'dibuat_pada' => $token->created_at?->toIso8601String(),
                'terakhir_dipakai_pada' => $token->last_used_at?->toIso8601String(),
                'berlaku_sampai' => $this->kedaluwarsaEfektif($token, $user)->toIso8601String(),
                'diverifikasi_pada' => $p?->diverifikasi_pada?->toIso8601String(),
                'browser_tepercaya_id' => $web && $token->getAttribute('browser_tepercaya_id') !== null ? (int) $token->getAttribute('browser_tepercaya_id') : null,
                'sesi_ini' => $tokenSaatIniId !== null && (int) $token->getKey() === $tokenSaatIniId,
            ];
        })->values();
    }

    // ------------------------------------------------------------------ rotasi token web (R04-4, #82)

    /**
     * Rotasi rahasia token sesi web INI di baris yang sama (id, masa berlaku, browser tepercaya, dan kuota tetap).
     * CSRF sesi = HMAC(nilai cookie), sehingga CSRF ikut berganti; nilai cookie dan CSRF lama langsung tidak sah.
     * Klaim atomik atas hash lama (rotasi paralel: hanya satu yang menang). Nilai cookie baru diserahkan ke
     * SesiWebCookie lewat atribut request (tidak pernah masuk badan JSON/log). Token Mini App (Bearer) -> null.
     *
     * @return array{csrf: string, expires_at: Carbon}|null
     */
    public function rotasiWeb(PersonalAccessToken $token): ?array
    {
        $user = $token->tokenable;

        // Token web selalu ber-expires_at (terbitkanWeb); tanpa itu cookie baru tidak dapat diterbitkan -> tidak dirotasi.
        if (! $user instanceof User || ! $token->exists || ! self::tokenWeb($token) || ! self::jenisKonsisten($token) || $token->expires_at === null) {
            return null;
        }

        $rahasia = $user->generateTokenString();
        $hashLama = (string) $token->getRawOriginal('token');

        $diubah = PersonalAccessToken::query()->whereKey($token->getKey())->where('token', $hashLama)
            ->update(['token' => hash('sha256', $rahasia)]);

        if ($diubah !== 1) {
            return null;
        }

        $nilai = $token->getKey().'|'.$rahasia;
        $sampai = (PersonalAccessToken::query()->find($token->getKey())?->expires_at ?? $token->expires_at)->copy();
        $token->forceFill(['token' => hash('sha256', $rahasia)])->syncOriginal();

        request()->attributes->set(SesiWeb::ATRIBUT_COOKIE_ROTASI, $nilai);
        request()->attributes->set(SesiWeb::ATRIBUT_SAMPAI, $sampai->copy());

        return ['csrf' => SesiWeb::csrfSesi($nilai), 'expires_at' => $sampai];
    }

    // ------------------------------------------------------------------ pencabutan

    /**
     * Logout perangkat ini: token dihapus dan kepercayaan perangkat (Mini App) dicabut. Browser tepercaya web
     * (R04-3) TIDAK dicabut oleh logout/pencabutan satu sesi/batas kuota sesi: ia punya kuota dan pencabutan
     * sendiri (BrowserTepercayaService::cabutSatu, keluar semua, pencabutan otomatis).
     */
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
        $token = self::hanyaSesiLogin($pemilik->tokens())
            ->whereKey($sesiId)
            ->first();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $this->logout($pemilik, $token);

        return $token;
    }

    /** Keluar dari semua perangkat (termasuk perangkat ini). Mengembalikan jumlah sesi penuh yang dicabut. */
    public function keluarSemua(User $user, string $alasan = 'keluar_semua'): int
    {
        return DB::transaction(function () use ($user, $alasan): int {
            $jumlah = self::hanyaSesiLogin($user->tokens())->count();
            $user->cabutSemuaSesi(alasan: $alasan);

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

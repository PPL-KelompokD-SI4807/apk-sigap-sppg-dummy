<?php

namespace App\Services\Auth;

use App\Models\BrowserTepercaya;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Browser tepercaya web R04-3 ("Ingat browser ini 30 hari"; ADR 2026-11-09 Keputusan 6, A2, Q1).
 *
 * - Hanya melewati KODE Telegram; sandi tetap wajib (MasukWebService). Sukarela, bawaan mati.
 * - Cookie `__Host-sigap_tb` = `selektor.validator`: selektor 128-bit (pencarian, unik) + validator 256-bit acak.
 *   Server hanya menyimpan SHA-256 validator; pencocokan hash_equals. Nilai cookie tidak pernah dicatat di audit/log.
 * - Berlaku TETAP 30 hari sejak kode terakhir (berlaku_sampai tidak bergeser saat dipakai). Maks 5 per pengguna,
 *   terpisah dari kuota sesi (Q2); kelebihan mencabut yang paling lama diverifikasi.
 * - Admin tidak pernah (Q1): buat() menolak Admin, dan browser milik pengguna yang kini Admin tidak berlaku
 *   (perubahan peran juga mencabutnya lewat User::cabutSemuaSesi).
 * - Pencabutan selalu berantai: sesi web turunan (personal_access_tokens.browser_tepercaya_id) ikut dihapus.
 */
class BrowserTepercayaService
{
    public const MAKS_KERAS = 5;

    public const MASA_HARI_KERAS = 30;

    public const ALASAN_DIGANTI = 'diganti';

    public const ALASAN_BATAS = 'batas';

    public const ALASAN_DICABUT_PENGGUNA = 'dicabut_pengguna';

    public const ALASAN_KEDALUWARSA = 'kedaluwarsa';

    public const ALASAN_ADMIN = 'pemilik_admin';

    public const ALASAN_SESI_DICABUT = 'semua_sesi_dicabut';

    private const POLA_COOKIE = '/^([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})$/D';

    public function maks(): int
    {
        return self::MAKS_KERAS;
    }

    public function masaHari(): int
    {
        return self::MASA_HARI_KERAS;
    }

    // ------------------------------------------------------------------ cookie

    public static function cookie(string $nilai, Carbon $sampai): Cookie
    {
        return SesiWeb::buatCookie(SesiWeb::COOKIE_TB, $nilai, $sampai);
    }

    public static function lupakanCookie(): Cookie
    {
        return SesiWeb::lupakanCookie(SesiWeb::COOKIE_TB);
    }

    public static function namaCookie(): string
    {
        return SesiWeb::namaCookie(SesiWeb::COOKIE_TB);
    }

    private static function hashValidator(string $validator): string
    {
        return hash('sha256', $validator);
    }

    private static function acak(int $byte): string
    {
        return rtrim(strtr(base64_encode(random_bytes($byte)), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------ pemeriksaan

    /**
     * Baris browser tepercaya BERLAKU untuk nilai cookie ini (tanpa melihat identitas yang diketik), atau null bila
     * format salah, selektor tidak dikenal, validator tidak cocok, dicabut, atau lewat 30 hari.
     */
    public function dariCookie(mixed $nilai): ?BrowserTepercaya
    {
        if (! is_string($nilai) || strlen($nilai) > 80 || preg_match(self::POLA_COOKIE, $nilai, $m) !== 1) {
            return null;
        }

        $browser = BrowserTepercaya::query()->where('selektor', $m[1])->first();

        if ($browser === null || ! hash_equals($browser->validator_hash, self::hashValidator($m[2]))) {
            return null;
        }

        return $browser->berlaku() ? $browser : null;
    }

    /**
     * Browser boleh melewati kode untuk $user: milik akun yang sama, akun bukan Admin (Q1). Browser milik akun yang
     * kini Admin dicabut di sini juga (fail closed; perubahan peran biasanya sudah mencabutnya).
     */
    public function bolehUntuk(BrowserTepercaya $browser, User $user): bool
    {
        if ((int) $browser->user_id !== (int) $user->getKey()) {
            return false;
        }

        if ($user->isAdmin()) {
            $this->cabut($browser, self::ALASAN_ADMIN, $user);

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------ penerbitan

    /**
     * Buat browser tepercaya baru (pemanggil WAJIB sudah memverifikasi sandi + kode Telegram, di dalam transaksi
     * login). $lama = browser tepercaya yang dibawa browser ini (dicabut bila milik akun yang sama). Kelebihan di
     * atas maks() mencabut yang paling lama diverifikasi; yang baru tidak pernah.
     *
     * @return array{browser: BrowserTepercaya, cookie: Cookie}
     */
    public function buat(User $user, ?string $label, ?BrowserTepercaya $lama = null): array
    {
        if ($user->isAdmin() || ! $user->canAuthenticate()) {
            throw new \DomainException('Browser tepercaya tidak tersedia untuk akun ini.');
        }

        return DB::transaction(function () use ($user, $label, $lama): array {
            if ($lama !== null && (int) $lama->user_id === (int) $user->getKey()) {
                $this->cabut($lama, self::ALASAN_DIGANTI, $user);
            }

            $selektor = self::acak(16);
            $validator = self::acak(32);
            $sekarang = now();
            $sampai = $sekarang->copy()->addDays($this->masaHari());

            $browser = new BrowserTepercaya;
            $browser->forceFill([
                'user_id' => $user->getKey(),
                'selektor' => $selektor,
                'validator_hash' => self::hashValidator($validator),
                'label' => $label === null ? null : (mb_substr(trim(strip_tags($label)), 0, 120) ?: null),
                'diverifikasi_pada' => $sekarang,
                'berlaku_sampai' => $sampai,
                'terakhir_dipakai_at' => $sekarang,
            ])->save();

            $this->rapikan($user, (int) $browser->getKey());

            AuditAuth::catat($user, AuditAuth::WEB_BROWSER_DIBUAT, [
                'browser_id' => (int) $browser->getKey(),
                'berlaku_sampai' => $sampai->toIso8601String(),
            ]);

            return ['browser' => $browser, 'cookie' => self::cookie($selektor.'.'.$validator, $sampai)];
        });
    }

    /** Tandai dipakai (login lewat browser ini). berlaku_sampai TIDAK berubah. */
    public function tandaiDipakai(BrowserTepercaya $browser): void
    {
        BrowserTepercaya::query()->whereKey($browser->getKey())->update(['terakhir_dipakai_at' => now()]);
    }

    /** Maks browser tepercaya berlaku per pengguna; kelebihan = yang paling lama diverifikasi dicabut. */
    private function rapikan(User $user, int $baruId): void
    {
        $lain = $user->browserTepercaya()->aktif()->whereKeyNot($baruId)
            ->orderBy('diverifikasi_pada')->orderBy('id')->get();
        $lebih = $lain->count() + 1 - $this->maks();

        $lain->take(max(0, $lebih))->each(fn (BrowserTepercaya $b) => $this->cabut($b, self::ALASAN_BATAS, $user));
    }

    // ------------------------------------------------------------------ pencabutan

    /** Cabut satu browser + sesi web turunannya. Idempoten (yang sudah dicabut tidak diaudit ulang). */
    public function cabut(BrowserTepercaya $browser, string $alasan, ?User $pemilik = null): bool
    {
        $diubah = DB::transaction(function () use ($browser, $alasan): int {
            $n = BrowserTepercaya::query()->whereKey($browser->getKey())->whereNull('dicabut_at')
                ->update(['dicabut_at' => now(), 'alasan_cabut' => $alasan, 'updated_at' => now()]);
            PersonalAccessToken::query()->where('browser_tepercaya_id', $browser->getKey())->delete();

            return $n;
        });

        if ($diubah === 1) {
            $browser->forceFill(['dicabut_at' => now(), 'alasan_cabut' => $alasan])->syncOriginal();
            $pemilik ??= User::query()->withoutGlobalScopes()->withTrashed()->find($browser->user_id);

            if ($pemilik instanceof User) {
                AuditAuth::catat($pemilik, AuditAuth::WEB_BROWSER_DICABUT, ['browser_id' => (int) $browser->getKey(), 'pemicu' => $alasan]);
            }
        }

        return $diubah === 1;
    }

    /**
     * Cabut semua browser tepercaya pengguna (belum dicabut, termasuk yang lewat masa) beserta sesi turunannya.
     * Dipanggil User::cabutSemuaSesi (sandi diubah/direset, hak akses berubah, akun dihapus/dipulihkan, keluar
     * semua, Admin mencabut). Satu audit ringkas bila ada yang dicabut.
     */
    public function cabutSemua(User $user, string $alasan): int
    {
        $ids = $user->browserTepercaya()->belumDicabut()->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        $jumlah = BrowserTepercaya::query()->whereKey($ids)->whereNull('dicabut_at')
            ->update(['dicabut_at' => now(), 'alasan_cabut' => $alasan, 'updated_at' => now()]);
        PersonalAccessToken::query()->whereIn('browser_tepercaya_id', $ids)->delete();

        if ($jumlah > 0) {
            AuditAuth::catat($user, AuditAuth::WEB_BROWSER_DICABUT, ['pemicu' => $alasan, 'jumlah' => $jumlah]);
        }

        return $jumlah;
    }

    /** Cabut satu browser MILIK $pemilik (id milik akun lain/tidak ada/sudah dicabut -> null, cegah IDOR). */
    public function cabutSatu(User $pemilik, int $id): ?BrowserTepercaya
    {
        $browser = $pemilik->browserTepercaya()->belumDicabut()->whereKey($id)->first();

        if (! $browser instanceof BrowserTepercaya) {
            return null;
        }

        $this->cabut($browser, self::ALASAN_DICABUT_PENGGUNA, $pemilik);

        return $browser;
    }

    /**
     * Pembersihan harian: browser yang lewat berlaku_sampai ditandai dicabut (baris tetap) dan sesi turunannya
     * dihapus. Idempoten.
     */
    public function bersihkanKedaluwarsa(): int
    {
        return DB::transaction(function (): int {
            $ids = BrowserTepercaya::query()->belumDicabut()->where('berlaku_sampai', '<=', now())
                ->lockForUpdate()->pluck('id')->all();

            if ($ids === []) {
                return 0;
            }

            PersonalAccessToken::query()->whereIn('browser_tepercaya_id', $ids)->delete();

            return BrowserTepercaya::query()->whereKey($ids)
                ->update(['dicabut_at' => now(), 'alasan_cabut' => self::ALASAN_KEDALUWARSA, 'updated_at' => now()]);
        });
    }

    // ------------------------------------------------------------------ daftar

    /**
     * Browser tepercaya BERLAKU milik pengguna (terbaru dipakai dulu). Tanpa selektor/validator.
     *
     * @return Collection<int, array{id: int, label: string|null, diverifikasi_pada: string, berlaku_sampai: string, terakhir_dipakai_pada: string|null, browser_ini: bool}>
     */
    public function daftar(User $user, ?int $browserIniId): Collection
    {
        return $user->browserTepercaya()->aktif()
            ->orderByDesc('terakhir_dipakai_at')->orderByDesc('id')->get()
            ->map(fn (BrowserTepercaya $b): array => [
                'id' => (int) $b->getKey(),
                'label' => $b->label,
                'diverifikasi_pada' => $b->diverifikasi_pada->toIso8601String(),
                'berlaku_sampai' => $b->berlaku_sampai->toIso8601String(),
                'terakhir_dipakai_pada' => $b->terakhir_dipakai_at?->toIso8601String(),
                'browser_ini' => $browserIniId !== null && (int) $b->getKey() === $browserIniId,
            ])->values();
    }
}

<?php

namespace App\Services\Ai\Eksekusi;

use App\Models\User;
use App\Support\SppgAktif;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sub-request INTERNAL ke rute REST modul (bukan HTTP keluar) atas nama pengguna penekan tombol Bot, agar aksi dari
 * chat melewati stack yang IDENTIK dengan Mini App: middleware global, auth:sanctum, `active` (EnsureUserIsActive),
 * EnsureTokenAbility (auth:penuh), ResolveSppgAktif, throttle:api, IdempotencyKey (bila terpasang), route model
 * binding ber-SppgScope, Form Request (authorize + rules + after), Policy, Service, audit, dan format galat API.
 *
 * Identitas:
 * - Pengguna = baris SEGAR dari basis data (bukan dari isi draf atau callback).
 * - Token = salah satu sesi login penuh MILIK pengguna itu (SesiService::tokenSesiPenuh). Tidak ada token baru yang
 *   diterbitkan dan tidak ada token mentah yang pernah ada di memori: guard Sanctum diisi langsung dengan pengguna +
 *   token tersimpannya, sehingga EnsureTokenAbility memeriksa ability token sesi sungguhan (auth:penuh, bukan "*").
 *
 * Isolasi state (WAJIB, worker antrean berjalan lama): sebelum kembali, semua state yang diubah sub-request
 * dipulihkan di finally: pengguna guard Sanctum, guard bawaan + user resolver (auth:sanctum memanggil shouldUse),
 * instance `request` di kontainer (+ facade, URL generator lewat rebinding), dan cache SppgAktif. Sumber audit
 * (AuditLog::withSource) dikelola pemanggil dan juga dipulihkan di finally. Diuji AiEksekusiSubRequestTest.
 */
class PermintaanInternal
{
    public const USER_AGENT = 'SiGAP-Bot-AI/1';

    public function __construct(private readonly Application $app) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $header
     */
    public function kirim(User $pengguna, PersonalAccessToken $sesi, string $metode, string $uri, array $body = [], array $header = []): ResponsInternal
    {
        $auth = $this->app->make('auth');
        $guardAsal = (string) config('auth.defaults.guard');
        $permintaanAsal = $this->app->bound('request') ? $this->app->make('request') : null;
        $sanctum = $auth->guard('sanctum');
        $penggunaSanctumAsal = $sanctum->hasUser() ? $sanctum->user() : null;

        // Instance terpisah: token sesi tidak "menempel" pada objek pengguna milik pemanggil.
        $aktor = User::query()->withoutGlobalScopes()->find($pengguna->getKey());

        if (! $aktor instanceof User || (int) $sesi->tokenable_id !== (int) $aktor->getKey() || $sesi->tokenable_type !== $aktor->getMorphClass()) {
            return new ResponsInternal(401, ['message' => 'Tidak terautentikasi.']);
        }

        $aktor->withAccessToken($sesi);

        $request = Request::create($uri, strtoupper($metode), [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => self::USER_AGENT,
        ], (string) json_encode($body, JSON_UNESCAPED_UNICODE));

        foreach ($header as $nama => $nilai) {
            $request->headers->set($nama, $nilai);
        }

        $sanctum->setUser($aktor);

        try {
            $respons = $this->app->make(HttpKernel::class)->handle($request);
            $isi = json_decode((string) $respons->getContent(), true);

            return new ResponsInternal($respons->getStatusCode(), is_array($isi) ? $isi : null);
        } finally {
            $sanctum->forgetUser();

            if ($penggunaSanctumAsal !== null) {
                $sanctum->setUser($penggunaSanctumAsal);
            }

            $auth->shouldUse($guardAsal);

            if ($permintaanAsal !== null) {
                $this->app->instance('request', $permintaanAsal);
            } else {
                $this->app->forgetInstance('request');
            }

            Facade::clearResolvedInstance('request');
            $this->app->make(SppgAktif::class)->reset();
        }
    }
}

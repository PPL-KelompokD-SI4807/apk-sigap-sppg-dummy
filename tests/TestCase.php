<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\SesiService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Bertindak sebagai pengguna dengan sesi LOGIN PENUH (ability auth:penuh, R-03). Semua rute
     * selain proses masuk mewajibkan ability ini; middleware tidak dilonggarkan untuk tes.
     *
     * @param  list<string>  $abilities  ability tambahan
     */
    protected function masukPenuh(User $user, array $abilities = []): User
    {
        return Sanctum::actingAs($user, [SesiService::ABILITY_PENUH, ...$abilities]);
    }

    /**
     * Terbitkan sesi login penuh SUNGGUHAN (token tersimpan + perangkat tepercaya) tanpa alur
     * initData/password, mis. agar SesiService::punyaSesiPenuh() benar untuk tes bot.
     */
    protected function beriSesiPenuh(User $user, string $deviceId = 'perangkat-uji-aaaaaaaaaaaaaaaaaa'): string
    {
        return app(SesiService::class)->terbitkanPenuh($user, SesiService::hashPerangkat($deviceId), 'Perangkat uji', null)['token'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Bahasa produk = Indonesia; dipaksa agar tes tidak bergantung pada APP_LOCALE lokal (.env).
        $this->app->setLocale('id');
    }
}

<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class PemisahanHostTest extends TestCase
{
    private const WEB = 'https://sigapsppg.app';

    private const MINIAPP = 'https://miniapp.sigapsppg.app';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::WEB,
            'telegram.miniapp_url' => self::MINIAPP.'/miniapp/',
        ]);
    }

    public function test_host_miniapp_tidak_menerbitkan_cookie_pra_login_web(): void
    {
        $path = '/api/v1/web/auth/csrf';
        $header = ['X-Sigap-Klien' => 'web', 'Origin' => self::WEB];

        $this->getJson(self::WEB.$path, $header)->assertSuccessful()->assertHeader('Set-Cookie');
        $this->getJson(self::MINIAPP.$path, $header)->assertNotFound()->assertHeaderMissing('Set-Cookie');
        $this->postJson(self::MINIAPP.'/api/v1/web/auth/masuk', [], $header)->assertNotFound();
    }

    public function test_fallback_laravel_miniapp_terbatas_ke_host_miniapp(): void
    {
        $this->get(self::WEB.'/miniapp/')->assertNotFound();
        $this->get(self::WEB.'/miniapp/formulir')->assertNotFound();
        $this->get(self::WEB.'/miniapp/assets/index.js')->assertNotFound();

        $this->get(self::MINIAPP.'/miniapp/formulir')
            ->assertStatus(is_file(public_path('miniapp/index.html')) ? 200 : 503)
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_webhook_hanya_pada_host_web(): void
    {
        $path = '/api/v1/telegram/webhook';

        $this->postJson(self::MINIAPP.$path)->assertNotFound();
        $this->postJson(self::WEB.$path)->assertForbidden(); // Secret tetap wajib.
    }

    public function test_cors_tidak_mengizinkan_akses_api_lintas_kedua_origin(): void
    {
        foreach ([self::WEB => self::MINIAPP, self::MINIAPP => self::WEB] as $host => $origin) {
            $this->options($host.'/api/v1/auth/me', [], [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'X-Sigap-Klien, Authorization',
            ])->assertHeaderMissing('Access-Control-Allow-Origin')
                ->assertHeaderMissing('Access-Control-Allow-Credentials');
        }
    }
}

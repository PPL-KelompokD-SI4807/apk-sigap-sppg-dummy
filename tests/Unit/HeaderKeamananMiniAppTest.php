<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Pagar regresi untuk header keamanan Mini App di nginx (token login ada di localStorage, jadi eksekusi skrip
 * tidak boleh melonggar). Hanya membaca berkas konfigurasi; verifikasi respons nyata: docs/ops/uji-staging.md SEC-05.
 */
class HeaderKeamananMiniAppTest extends TestCase
{
    private function header(string $nama): array
    {
        $isi = file_get_contents(base_path('docker/nginx/snippets/headers-miniapp.conf'));
        preg_match_all('/^add_header\s+'.preg_quote($nama, '/').'\s+"([^"]*)"/mi', $isi, $m);

        return $m[1];
    }

    public function test_csp_tegak_membatasi_skrip_ke_asal_sendiri_dan_telegram(): void
    {
        $csp = $this->header('Content-Security-Policy');

        $this->assertCount(1, $csp, 'CSP ditegakkan harus tepat satu (tidak ganda).');
        $this->assertStringContainsString("script-src 'self' https://telegram.org/js/telegram-web-app.js;", $csp[0]);
        $this->assertStringNotContainsString('https://telegram.org;', $csp[0], 'Jangan membuka seluruh host telegram.org.');
        $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $csp[0])[0]);
        $this->assertStringNotContainsString('unsafe-eval', $csp[0]);
        $this->assertStringContainsString("object-src 'none'", $csp[0]);
        $this->assertStringContainsString("base-uri 'self'", $csp[0]);
        $this->assertStringContainsString("form-action 'self'", $csp[0]);
        $this->assertStringContainsString('frame-ancestors', $csp[0]);
        $this->assertStringContainsString('https://web.telegram.org', $csp[0]);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $csp[0]);
    }

    public function test_csp_report_only_lengkap_dan_skrip_tanpa_inline(): void
    {
        $ro = $this->header('Content-Security-Policy-Report-Only');

        $this->assertCount(1, $ro);
        $this->assertStringContainsString("default-src 'self'", $ro[0]);
        $this->assertStringContainsString("connect-src 'self'", $ro[0]);
        $this->assertStringContainsString('img-src \'self\' data: blob:', $ro[0]);
        preg_match('/script-src ([^;]*)/', $ro[0], $m);
        $this->assertStringNotContainsString('unsafe', $m[1]);
    }

    public function test_header_dasar_tidak_ganda_dan_tanpa_x_frame_options(): void
    {
        $this->assertSame(['nosniff'], $this->header('X-Content-Type-Options'));
        $this->assertSame(['no-referrer'], $this->header('Referrer-Policy'));
        $this->assertSame([], $this->header('X-Frame-Options'), 'Telegram Web membingkai Mini App.');

        $pp = $this->header('Permissions-Policy');
        $this->assertCount(1, $pp);
        $this->assertStringContainsString('camera=(self)', $pp[0]);
        $this->assertStringContainsString('geolocation=(self)', $pp[0]);
    }

    public function test_sumber_skrip_telegram_sama_dengan_index_html(): void
    {
        $html = file_get_contents(base_path('resources/miniapp/index.html'));
        preg_match_all('/<script[^>]+src="(https?:\/\/[^"]+)"/i', $html, $m);

        $this->assertSame(['https://telegram.org/js/telegram-web-app.js'], $m[1], 'Skrip eksternal harus tepat satu dan terdaftar di CSP.');
        $this->assertStringContainsString($m[1][0], $this->header('Content-Security-Policy')[0]);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, 'Tidak boleh ada skrip inline.');
    }

    public function test_location_miniapp_meng_include_snippet_header(): void
    {
        $app = file_get_contents(base_path('docker/nginx/snippets/sigap-app.conf'));

        // Ambil isi blok location ^~ /miniapp/ (bukan /miniapp/assets/).
        $this->assertSame(1, preg_match('/location\s+\^~\s+\/miniapp\/\s*\{(.*?)
\}/s', $app, $blok));
        $this->assertStringContainsString('include /etc/nginx/snippets/headers-miniapp.conf;', $blok[1]);
        $this->assertStringNotContainsString('headers-api.conf', $blok[1]);
        $this->assertStringNotContainsString('headers-web.conf', $blok[1]);
        $this->assertDoesNotMatchRegularExpression('/add_header\s+(Content-Security-Policy|Referrer-Policy|Permissions-Policy|X-Frame-Options)/i', $blok[1], 'Header keamanan hanya dari snippet (add_header di location menimpa tingkat atas).');

        // Snippet itu benar-benar disalin ke image dan sigap-app.conf dipakai server produksi dan dev.
        $this->assertFileExists(base_path('docker/nginx/snippets/headers-miniapp.conf'));
        foreach (['443.conf.template', 'dev.conf.template'] as $tpl) {
            $this->assertStringContainsString('snippets/sigap-app.conf', file_get_contents(base_path('docker/nginx/templates/'.$tpl)), $tpl);
        }
        $this->assertStringContainsString('COPY docker/nginx/snippets/', file_get_contents(base_path('Dockerfile')));
    }
}

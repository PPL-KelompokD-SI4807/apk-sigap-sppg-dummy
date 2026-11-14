<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Pagar regresi nginx untuk klien web ("/" dan /web/): CSP tegak, tanpa telegram.org, cache, dan include snippet.
 * Hanya membaca berkas konfigurasi; verifikasi respons nyata: docs/ops/uji-staging.md SEC-05.
 */
class HeaderKeamananWebTest extends TestCase
{
    private function header(string $nama): array
    {
        $isi = file_get_contents(base_path('docker/nginx/snippets/headers-webapp.conf'));
        preg_match_all('/^add_header\s+'.preg_quote($nama, '/').'\s+"([^"]*)"/mi', $isi, $m);

        return $m[1];
    }

    private function blok(string $pola): string
    {
        $app = file_get_contents(base_path('docker/nginx/snippets/sigap-app.conf'));
        $this->assertSame(1, preg_match('/'.$pola.'\s*\{(.*?)\n\}/s', $app, $m), $pola);

        return $m[1];
    }

    public function test_csp_web_tegak_tanpa_telegram_dan_tanpa_pelonggaran_skrip(): void
    {
        $csp = $this->header('Content-Security-Policy');

        $this->assertCount(1, $csp);
        $this->assertStringContainsString("default-src 'self'; script-src 'self';", $csp[0]);
        $this->assertStringNotContainsString('telegram', $csp[0]);
        $this->assertStringNotContainsString('unsafe', $csp[0]);
        foreach (["style-src 'self';", "connect-src 'self';", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'none'", "form-action 'self'", 'upgrade-insecure-requests'] as $d) {
            $this->assertStringContainsString($d, $csp[0]);
        }
    }

    public function test_header_dasar_web(): void
    {
        $this->assertSame(['DENY'], $this->header('X-Frame-Options'));
        $this->assertSame(['nosniff'], $this->header('X-Content-Type-Options'));
        $this->assertSame(['no-referrer'], $this->header('Referrer-Policy'));
        $this->assertSame(['same-origin'], $this->header('Cross-Origin-Opener-Policy'));
        $this->assertSame(['same-origin'], $this->header('Cross-Origin-Resource-Policy'));
        $this->assertStringContainsString('add_header Strict-Transport-Security $sigap_hsts always;', file_get_contents(base_path('docker/nginx/snippets/headers-webapp.conf')));
        $this->assertSame(["require-trusted-types-for 'script'"], $this->header('Content-Security-Policy-Report-Only'));
    }

    public function test_location_web_meng_include_snippet_dan_cache(): void
    {
        $akar = $this->blok('location\s+=\s+\/');
        $this->assertStringContainsString('rewrite ^ /web/index.html last;', $akar);

        $aset = $this->blok('location\s+\^~\s+\/web\/assets\/');
        $this->assertStringContainsString('public, max-age=31536000, immutable', $aset);
        $this->assertStringContainsString('headers-webapp.conf;', $aset);

        $web = $this->blok('location\s+\^~\s+\/web\/');
        $this->assertStringContainsString('Cache-Control "no-cache" always', $web);
        $this->assertStringContainsString('headers-webapp.conf;', $web);
        $this->assertDoesNotMatchRegularExpression('/add_header\s+(Content-Security-Policy|X-Frame-Options)/i', $web.$aset);
    }

    public function test_snippet_miniapp_tidak_berubah_dan_tidak_ada_keepalive_ganda(): void
    {
        $this->assertStringNotContainsString('keepalive_timeout', file_get_contents(base_path('docker/nginx/snippets/sigap-app.conf')));
        $miniapp = file_get_contents(base_path('docker/nginx/snippets/headers-miniapp.conf'));
        $this->assertStringContainsString('https://telegram.org/js/telegram-web-app.js', $miniapp);
        $this->assertStringNotContainsString('Cross-Origin-Opener-Policy', $miniapp);
    }
}

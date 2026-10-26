<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MiniAppServeTest extends TestCase
{
    private string $publik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publik = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sigap-public-'.uniqid();
        File::ensureDirectoryExists($this->publik.'/miniapp');
        $this->app->usePublicPath($this->publik);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publik);
        parent::tearDown();
    }

    public function test_menyajikan_index_hasil_build_untuk_root_dan_jalur_spa(): void
    {
        File::put($this->publik.'/miniapp/index.html', '<!doctype html><title>SiGAP SPPG</title>');

        foreach (['/miniapp', '/miniapp/', '/miniapp/index.html', '/miniapp/jadwal/12'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('SiGAP SPPG', false)
                ->assertHeader('Cache-Control', 'no-cache, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_path_permintaan_tidak_pernah_dipakai_membaca_berkas(): void
    {
        File::put($this->publik.'/miniapp/index.html', '<title>indeks</title>');
        File::put($this->publik.'/rahasia.txt', 'RAHASIA');

        $this->get('/miniapp/..%2Frahasia.txt')->assertDontSee('RAHASIA');
        $this->get('/miniapp/%2e%2e/rahasia.txt')->assertDontSee('RAHASIA');
    }

    public function test_belum_dibangun_503_dan_assets_hilang_404(): void
    {
        $this->get('/miniapp/')->assertStatus(503);

        File::put($this->publik.'/miniapp/index.html', '<title>indeks</title>');
        $this->get('/miniapp/assets/tidak-ada.js')->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_uji')->group(function () {
            Route::get('meledak', fn () => throw new RuntimeException('SQLSTATE[42S02] tabel rahasia_internal'));
            Route::get('hanya-get', fn () => ['ok' => true]);
        });
    }

    public function test_500_tidak_membocorkan_detail_internal(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_uji/meledak');

        $response->assertStatus(500)->assertExactJson(['message' => 'Terjadi kesalahan pada server.']);
        $this->assertStringNotContainsString('rahasia_internal', $response->getContent());
    }

    public function test_500_di_production_tanpa_debug_meski_app_debug_true(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/v1/_uji/meledak');

        $response->assertStatus(500)->assertExactJson(['message' => 'Terjadi kesalahan pada server.']);
    }

    public function test_rute_tidak_ada_404_json_dengan_header_keamanan(): void
    {
        $this->getJson('/api/v1/tidak-ada')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.'])
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_metode_salah_405_json(): void
    {
        $this->postJson('/api/v1/_uji/hanya-get')
            ->assertStatus(405)
            ->assertExactJson(['message' => 'Metode HTTP tidak didukung untuk endpoint ini.']);
    }

    public function test_rute_web_tidak_terpengaruh(): void
    {
        $this->get('/')->assertOk()->assertHeaderMissing('X-Frame-Options');
    }
}

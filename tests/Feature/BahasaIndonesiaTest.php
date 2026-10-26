<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Sppg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pesan baku validator berbahasa Indonesia + nama field domain (lang/id). */
class BahasaIndonesiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_terpasang_id_dengan_fallback_en_dan_zona_waktu_wib(): void
    {
        $this->assertSame('id', app()->getLocale());
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    public function test_berkas_bahasa_lengkap_dibanding_en(): void
    {
        foreach (['validation', 'auth', 'passwords', 'pagination'] as $berkas) {
            $this->assertFileExists(lang_path("id/{$berkas}.php"));
        }

        $en = require lang_path('en/validation.php');
        $id = require lang_path('id/validation.php');

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($id))), 'Kunci validation id kurang dari en.');
    }

    public function test_pesan_baku_dan_nama_atribut_domain(): void
    {
        $v = Validator::make(
            ['target_porsi' => 'banyak', 'tanggal_kedaluwarsa' => 'kemarin sore', 'jumlah' => -1],
            ['target_porsi' => 'required|integer', 'tanggal_kedaluwarsa' => 'date', 'jumlah' => 'numeric|min:0', 'kategori' => 'required'],
        );

        $pesan = $v->errors()->toArray();
        $this->assertSame(['Target porsi harus berupa bilangan bulat.'], $pesan['target_porsi']);
        $this->assertSame(['Tanggal kedaluwarsa bukan tanggal yang valid.'], $pesan['tanggal_kedaluwarsa']);
        $this->assertSame(['Jumlah minimal bernilai 0.'], $pesan['jumlah']);
        $this->assertSame(['Kategori wajib diisi.'], $pesan['kategori']);
    }

    public function test_pesan_ukuran_dan_unik_berbahasa_indonesia(): void
    {
        $v = Validator::make(['nama' => str_repeat('a', 10), 'kode' => 'x'], ['nama' => 'max:5', 'kode' => 'size:3']);

        $this->assertSame(['Nama tidak boleh lebih dari 5 karakter.'], $v->errors()->get('nama'));
        $this->assertSame(['Kode harus terdiri dari 3 karakter.'], $v->errors()->get('kode'));
        $this->assertSame('Terlalu banyak percobaan masuk. Silakan coba lagi dalam 30 detik.', Lang::get('auth.throttle', ['seconds' => 30]));
    }

    public function test_respons_api_422_memakai_pesan_indonesia(): void
    {
        $sppg = Sppg::factory()->create();
        $kepala = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $sppg->id]);
        Sanctum::actingAs($kepala);

        $res = $this->postJson('/api/v1/jadwal', [])->assertUnprocessable();

        $this->assertSame('Target porsi wajib diisi.', $res->json('errors.target_porsi.0'));
        $this->assertSame('Tanggal produksi wajib diisi.', $res->json('errors.tanggal_produksi.0'));
    }
}

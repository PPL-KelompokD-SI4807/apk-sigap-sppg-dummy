<?php

namespace Tests\Feature\Investor;

use App\Models\BahanBaku;
use App\Models\FeedbackKeluhan;
use App\Models\PemeriksaanMutu;
use App\Models\PenerimaManfaat;
use App\Models\Pengeluaran;
use App\Models\RekapHarian;
use App\Support\SppgAktif;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * R-06a: SPPG aktif (header X-SPPG-Id), isolasi antar-investor/antar-SPPG, baca saja, dan
 * regresi peran lain.
 */
class InvestorAksesTest extends InvestorTestCase
{
    public function test_investor_dua_sppg_tanpa_header_fail_closed_kecuali_profil_dan_daftar_sppg(): void
    {
        $this->sebagai($this->investorAB);

        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 409, SppgAktif::BELUM_DIPILIH)
            ->assertJsonPath('langkah', 'pilih_sppg')
            ->assertJsonPath('header', 'X-SPPG-Id');
        $this->assertAlasan($this->getJson("/api/v1/sppg/{$this->sppgA}"), 409, SppgAktif::BELUM_DIPILIH);

        $this->getJson('/api/v1/sppg-saya')->assertOk()
            ->assertJsonPath('sppg_aktif_id', null)
            ->assertJsonPath('wajib_pilih', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['id' => $this->sppgC]);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.role', 'investor')
            ->assertJsonPath('user.sppg_id', null)
            ->assertJsonPath('user.sppg_aktif_id', null)
            ->assertJsonPath('user.multi_sppg', true)
            ->assertJsonPath('user.baca_saja', true)
            ->assertJsonPath('user.agregat', ['penerima-manfaat']);
    }

    public function test_investor_satu_sppg_tanpa_header_memakai_sppg_itu(): void
    {
        $this->sebagai($this->investorC);

        $this->getJson('/api/v1/sppg')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->sppgC);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.sppg_aktif_id', $this->sppgC)->assertJsonPath('user.sppg_name', 'SPPG C');
        $this->getJson('/api/v1/sppg-saya')->assertOk()->assertJsonPath('wajib_pilih', false)->assertJsonPath('sppg_aktif_id', $this->sppgC);
    }

    public function test_investor_tanpa_kepemilikan_ditolak(): void
    {
        $kosong = $this->investor([]);
        $this->sebagai($kosong);

        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::TANPA_SPPG);
        $this->getJson('/api/v1/sppg-saya')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_header_sppg_milik_investor_lain_atau_tidak_sah_ditolak_403(): void
    {
        foreach ([$this->sppgC, 999999, 'abc', '1 OR 1=1', '-1', '0'] as $header) {
            $this->sebagai($this->investorAB, $header);
            $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);
            // Juga pada rute pengecualian: header palsu tidak pernah diterima.
            $this->assertAlasan($this->getJson('/api/v1/sppg-saya'), 403, SppgAktif::DITOLAK);
        }

        // Investor lain tidak bisa memakai SPPG milik investor AB.
        $this->sebagai($this->investorC, $this->sppgA);
        $this->assertAlasan($this->getJson("/api/v1/sppg/{$this->sppgA}"), 403, SppgAktif::DITOLAK);
    }

    public function test_sppg_yang_dihapus_atau_dicabut_kepemilikannya_tidak_dapat_dipakai(): void
    {
        DB::table('sppgs')->where('id', $this->sppgB)->update(['deleted_at' => now()]);
        $this->sebagai($this->investorAB, $this->sppgB);
        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);

        // Tinggal satu SPPG aktif -> tanpa header memakai SPPG A.
        $this->sebagai($this->investorAB);
        $this->getJson('/api/v1/sppg')->assertOk()->assertJsonPath('data.0.id', $this->sppgA);

        DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgA)->delete();
        $this->sebagai($this->investorAB, $this->sppgA);
        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);
    }

    public function test_data_sppg_a_tidak_muncul_saat_sppg_aktif_b(): void
    {
        $keluhanA = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Keluhan fiktif A']);
        $keluhanB = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgB, 'deskripsi' => 'Keluhan fiktif B']);
        $keluhanC = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgC]);
        $bahanA = BahanBaku::factory()->create(['sppg_id' => $this->sppgA]);
        BahanBaku::factory()->create(['sppg_id' => $this->sppgB]);

        $this->sebagai($this->investorAB, $this->sppgB);
        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $keluhanB->id);
        $this->getJson("/api/v1/keluhan/{$keluhanA->id}")->assertNotFound();
        $this->getJson("/api/v1/keluhan/{$keluhanC->id}")->assertNotFound();
        $this->getJson("/api/v1/bahan-baku/{$bahanA->id}")->assertNotFound();
        $this->getJson('/api/v1/bahan-baku')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sppg')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->sppgB);
        // SPPG A milik investor ini tetapi bukan SPPG aktif -> ditolak (tidak ada data tercampur).
        $this->getJson("/api/v1/sppg/{$this->sppgA}")->assertForbidden();
        $this->getJson("/api/v1/sppg/{$this->sppgC}")->assertForbidden();

        $this->sebagai($this->investorAB, $this->sppgA);
        $this->getJson("/api/v1/keluhan/{$keluhanA->id}")->assertOk()->assertJsonPath('data.deskripsi', 'Keluhan fiktif A');
        $this->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk();
    }

    public function test_investor_membaca_modul_yang_diizinkan_dan_ditolak_modul_lain(): void
    {
        $penerima = PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA]);
        PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgA]);
        Pengeluaran::factory()->create(['sppg_id' => $this->sppgA]);
        RekapHarian::factory()->create(['sppg_id' => $this->sppgA]);
        $this->sebagai($this->investorAB, $this->sppgA);

        foreach ([
            '/api/v1/sppg', '/api/v1/bahan-baku', '/api/v1/bahan-baku/stok-menipis', '/api/v1/mutu',
            '/api/v1/keuangan/ringkasan', '/api/v1/keuangan/pencairan', '/api/v1/keuangan/pengeluaran',
            '/api/v1/keluhan', '/api/v1/keluhan/ringkasan', '/api/v1/rekap-harian', '/api/v1/rekap-harian/ringkasan',
            '/api/v1/penerima-manfaat/agregat',
        ] as $url) {
            $this->getJson($url)->assertOk();
        }

        foreach ([
            '/api/v1/penerima-manfaat', "/api/v1/penerima-manfaat/{$penerima->id}", '/api/v1/titik-layanan',
            '/api/v1/pemasok', '/api/v1/menu-gizi', '/api/v1/jadwal', '/api/v1/log-produksi', '/api/v1/armada',
            '/api/v1/pengemudi', '/api/v1/bukti-distribusi', '/api/v1/notifikasi-perjalanan',
            '/api/v1/pengguna/undangan', "/api/v1/pengguna/{$this->kepalaA->id}", "/api/v1/pengguna/{$this->investorC->id}/kepemilikan-sppg",
        ] as $url) {
            $this->assertContains($this->getJson($url)->status(), [403, 404], $url);
        }

        // Profil sendiri (R*) tetap dapat dibaca; daftar pengguna hanya berisi dirinya.
        $this->getJson("/api/v1/pengguna/{$this->investorAB->id}")->assertOk()->assertJsonPath('data.role', 'investor');
        $this->getJson('/api/v1/pengguna')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->investorAB->id);
    }

    public function test_investor_tidak_dapat_menulis_di_endpoint_mana_pun(): void
    {
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA]);
        $pengeluaran = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA]);
        $mutu = PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgA]);
        $rekap = RekapHarian::factory()->create(['sppg_id' => $this->sppgA]);
        $penerima = PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA]);
        $this->sebagai($this->investorAB, $this->sppgA);

        // Hak tulis yang paling mungkin disalahgunakan, pada record SPPG aktif miliknya sendiri.
        $this->patchJson("/api/v1/keluhan/{$keluhan->id}", ['status' => 'selesai', 'tindak_lanjut' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/keluhan/{$keluhan->id}")->assertForbidden();
        $this->postJson('/api/v1/keluhan', ['kategori' => 'rasa', 'sumber' => 'sekolah', 'deskripsi' => 'Uji'])->assertForbidden();
        $this->patchJson("/api/v1/bahan-baku/{$bahan->id}", ['nama' => 'Ubah'])->assertForbidden();
        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'masuk', 'jumlah' => 1])->assertForbidden();
        $this->patchJson("/api/v1/keuangan/pengeluaran/{$pengeluaran->id}", ['uraian' => 'Ubah'])->assertForbidden();
        $this->deleteJson("/api/v1/mutu/{$mutu->id}")->assertForbidden();
        $this->patchJson("/api/v1/rekap-harian/{$rekap->id}", ['catatan' => 'Ubah'])->assertForbidden();
        $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertForbidden();
        $this->postJson('/api/v1/rekap-harian', [])->assertForbidden();
        $this->patchJson("/api/v1/sppg/{$this->sppgA}", ['nama' => 'Diambil alih'])->assertForbidden();
        $this->patchJson("/api/v1/penerima-manfaat/{$penerima->id}", ['nama' => 'Ubah'])->assertForbidden();
        $this->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", ['sppg_ids' => [$this->sppgA, $this->sppgB, $this->sppgC]])->assertForbidden();
        $this->patchJson("/api/v1/pengguna/{$this->investorAB->id}", ['role' => 'admin'])->assertForbidden();
        $this->postJson('/api/v1/pengguna/undangan', ['role' => 'investor'])->assertForbidden();

        // Sapu seluruh rute tulis: tidak pernah 2xx/422 (otorisasi selalu lebih dulu). Batas laju
        // dimatikan agar sapuan tidak terpotong 429.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $methods = array_diff($route->methods(), ['GET', 'HEAD']);

            if ($methods === [] || ! str_starts_with($route->uri(), 'api/v1/') || $this->dikecualikan($route)) {
                continue;
            }

            $uri = (string) preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $status = $this->json(reset($methods), '/'.$uri, [])->status();
            $this->assertContains($status, [403, 404], reset($methods).' '.$uri);
        }

        $this->assertSame(0, FeedbackKeluhan::query()->withoutGlobalScopes()->where('deskripsi', 'Uji')->count());
        $this->assertSame(1, DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgA)->count());
        $this->assertSame(0, DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgC)->count());
    }

    /** Rute publik, proses masuk, dan sesi milik sendiri (logout/ubah password) bukan "data operasional". */
    private function dikecualikan(Route $route): bool
    {
        $name = (string) $route->getName();

        return str_starts_with($name, 'api.v1.auth.') || $name === 'api.v1.telegram.webhook' || str_contains($route->uri(), 'telegram');
    }

    public function test_peran_lain_header_sppg_lain_ditolak_dan_perilaku_lama_tetap(): void
    {
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgB]);

        $this->sebagai($this->kepalaA, $this->sppgB);
        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);

        $this->sebagai($this->kepalaA, $this->sppgA);
        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonCount(1, 'data');

        $this->sebagai($this->kepalaA);
        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sppg-saya')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->sppgA)->assertJsonPath('wajib_pilih', false);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.sppg_aktif_id', $this->sppgA)->assertJsonPath('user.multi_sppg', false)->assertJsonPath('user.baca_saja', false);

        // Admin: header diabaikan (lintas SPPG seperti sebelumnya).
        $this->sebagai($this->admin, $this->sppgC);
        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/sppg-saya')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_investor_tidak_dapat_melihat_pengguna_sppg(): void
    {
        $this->sebagai($this->investorAB, $this->sppgA);

        $ids = collect($this->getJson('/api/v1/pengguna?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->investorAB->id], $ids);
        $this->getJson("/api/v1/pengguna/{$this->investorC->id}")->assertNotFound();
        $this->getJson("/api/v1/pengguna/{$this->kepalaA->id}")->assertForbidden();
    }
}

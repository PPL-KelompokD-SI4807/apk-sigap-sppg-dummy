<?php

namespace Tests\Feature\PetaHak;

use App\Enums\Role;
use App\Models\FeedbackKeluhan;
use App\Models\Menu;
use App\Models\RekapHarian;
use App\Models\TitikLayanan;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Parity peta `aksi` vs endpoint sungguhan: aksi true -> endpoint TIDAK 403 (lolos otorisasi; boleh 200/201/422 karena
 * badan permintaan sengaja kosong); aksi false -> endpoint 403. Server tetap menegakkan hak (peta hanya cermin).
 */
class PetaHakSubRequestParityTest extends PetaHakTestCase
{
    private Menu $menu;

    private TitikLayanan $titik;

    private RekapHarian $rekap;

    private FeedbackKeluhan $keluhan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu = Menu::factory()->create(['sppg_id' => $this->sppgA]);
        $this->titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $this->rekap = RekapHarian::factory()->create(['sppg_id' => $this->sppgA]);
        $this->keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        $this->forgetActor();
    }

    /** @return array<string, callable(): TestResponse> kunci aksi => pemanggil endpoint terkait */
    private function endpoint(): array
    {
        return [
            'undang_pengguna' => fn () => $this->postJson('/api/v1/pengguna/undangan', []),
            // Dipanggil lewat panggilResetPassword (target baru per panggilan: reset password mengubah target).
            'reset_password_pengguna' => fn () => $this->postJson('/api/v1/pengguna/'.$this->relawanA->id.'/reset-password'),
            // Target terlihat oleh peran SPPG (relawan SPPG A) agar penolakan murni dari Policy.
            'kelola_kepemilikan_sppg' => fn () => $this->getJson('/api/v1/pengguna/'.$this->relawanA->id.'/kepemilikan-sppg'),
            'undang_pic' => fn () => $this->postJson("/api/v1/titik-layanan/{$this->titik->id}/undangan-pic", []),
            'ajukan_menu' => fn () => $this->postJson("/api/v1/menu-gizi/{$this->menu->id}/ajukan", []),
            'lihat_rincian_biaya' => fn () => $this->getJson("/api/v1/menu-gizi/{$this->menu->id}/rincian-biaya"),
            'picu_perjalanan' => fn () => $this->postJson('/api/v1/notifikasi-perjalanan/pratinjau', []),
            'susun_rekap' => fn () => $this->postJson('/api/v1/rekap-harian', []),
            'kirim_ulang_rekap' => fn () => $this->postJson("/api/v1/rekap-harian/{$this->rekap->id}/kirim-ulang"),
            'tindak_lanjut_keluhan' => fn () => $this->patchJson("/api/v1/keluhan/{$this->keluhan->id}", []),
            'catat_mutasi_stok' => fn () => $this->postJson("/api/v1/bahan-baku/{$this->berasA->id}/mutasi", []),
            'catat_harga_pemasok' => fn () => $this->postJson("/api/v1/pemasok/{$this->pemasokA->id}/harga", []),
            'lihat_ringkasan_jadwal_produksi' => fn () => $this->getJson('/api/v1/jadwal/produksi-ringkas'),
            'lihat_mutasi_stok' => fn () => $this->getJson("/api/v1/bahan-baku/{$this->berasA->id}/mutasi"),
        ];
    }

    private function targetBaru(): User
    {
        $this->forgetActor();

        return User::factory()->role(Role::Relawan)->create(['sppg_id' => $this->sppgA]);
    }

    public function test_aksi_true_tidak_403_dan_aksi_false_403_untuk_semua_profil(): void
    {
        $diperiksa = 0;

        foreach ($this->profil() as $nama => $user) {
            $aksi = $this->masuk($user)->getJson('/api/v1/auth/me')->assertOk()->json('user.aksi');

            foreach ($this->endpoint() as $kunci => $panggil) {
                if ($kunci === 'reset_password_pengguna') {
                    $respons = $this->panggilResetPassword($user);
                } else {
                    $this->masuk($user);
                    $respons = $panggil();
                }

                $status = $respons->getStatusCode();

                if ($aksi[$kunci] === true) {
                    $this->assertNotSame(403, $status, "{$nama}: aksi.{$kunci}=true tetapi endpoint 403");
                    $this->assertLessThan(500, $status, "{$nama}: {$kunci}");
                } else {
                    $this->assertSame(403, $status, "{$nama}: aksi.{$kunci}=false tetapi endpoint {$status}");
                }

                $diperiksa++;
            }
        }

        $this->assertSame(9 * 14, $diperiksa);
    }

    private function panggilResetPassword(User $user): TestResponse
    {
        $target = $this->targetBaru();

        return $this->masuk($user)->postJson("/api/v1/pengguna/{$target->id}/reset-password");
    }

    public function test_dashboard_tersedia_true_200_dan_relawan_tanpa_unit_false(): void
    {
        foreach ($this->profil() as $nama => $user) {
            $aksi = $this->masuk($user)->getJson('/api/v1/auth/me')->assertOk()->json('user.aksi.dashboard');
            $respons = $this->masuk($user)->getJson('/api/v1/dashboard');

            if ($aksi['tersedia']) {
                $respons->assertOk();
            } else {
                // Relawan tanpa unit: peta menyatakan tidak tersedia; endpoint tidak memberi widget (200 kosong atau ditolak),
                // tidak pernah berisi data.
                $this->assertSame('relawan.tanpa_unit', $nama);
                $this->assertContains($respons->getStatusCode(), [200, 403]);

                if ($respons->getStatusCode() === 200) {
                    $widgets = array_filter((array) $respons->json('data.widgets'), fn ($w) => $w !== null);
                    $this->assertSame([], $widgets, 'dashboard relawan tanpa unit tidak boleh berisi widget');
                }
            }
        }
    }

    public function test_pengguna_tanpa_hak_modul_tidak_dapat_menembus_lewat_peta_sppg_lain(): void
    {
        // Lintas SPPG: kepala SPPG B tidak mendapat 200 atas menu/keluhan SPPG A (peta tidak mengubah isolasi).
        $this->masuk($this->kepalaB);
        $this->getJson("/api/v1/menu-gizi/{$this->menu->id}/rincian-biaya")->assertStatus(404);
        $this->patchJson("/api/v1/keluhan/{$this->keluhan->id}", [])->assertStatus(404);
        $this->postJson("/api/v1/rekap-harian/{$this->rekap->id}/kirim-ulang")->assertStatus(404);
    }
}

<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use Illuminate\Support\Carbon;

/** GET /jadwal/produksi-ringkas: pilihan jadwal produksi untuk form distribusi (hak R jadwal). */
class JadwalRingkasTest extends GrupBTestCase
{
    private const URL = '/api/v1/jadwal/produksi-ringkas';

    private function jadwal(array $attrs = [], ?int $sppgId = null): JadwalProduksi
    {
        $sppgId ??= $this->sppgA->id;

        return JadwalProduksi::factory()->create(array_merge([
            'sppg_id' => $sppgId,
            'menu_id' => Menu::factory()->create(['sppg_id' => $sppgId, 'nama_menu' => 'Menu Uji']),
            'tanggal_produksi' => now()->toDateString(),
            'target_porsi' => 800,
        ], $attrs));
    }

    public function test_relawan_distribusi_bisa_melihat_ringkasan_padahal_tidak_boleh_baca_jadwal_produksi_penuh(): void
    {
        $j = $this->jadwal();
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);

        $this->getJson('/api/v1/jadwal')->assertForbidden();
        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $j->id)
            ->assertJsonPath('data.0.nama_menu', 'Menu Uji')
            ->assertJsonPath('data.0.target_porsi', 800)
            ->assertJsonPath('data.0.status', 'direncanakan')
            ->assertJsonPath('data.0.tanggal_produksi', now()->toDateString());
    }

    public function test_hanya_kolom_ringkas_tanpa_data_sensitif(): void
    {
        $this->jadwal(['catatan' => 'rahasia internal']);
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);

        $row = $this->getJson(self::URL)->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'tanggal_produksi', 'nama_menu', 'target_porsi', 'status', 'status_label'], array_keys($row));
    }

    public function test_peran_dengan_hak_baca_jadwal_boleh_dan_tanpa_hak_ditolak(): void
    {
        $this->jadwal();

        foreach ([[Role::KepalaSppg, null], [Role::AhliGizi, null], [Role::StafGudang, null], [Role::Relawan, RelawanUnit::Dapur]] as [$role, $unit]) {
            $this->login($role, null, $unit);
            $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        }

        $this->login(Role::Admin);
        $this->getJson(self::URL.'?sppg_id='.$this->sppgA->id)->assertOk()->assertJsonCount(1, 'data');

        $this->login(Role::Akuntan);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_relawan_tanpa_unit_ditolak(): void
    {
        $user = $this->user(Role::Relawan);
        $user->forceFill(['unit' => null])->save();
        \Laravel\Sanctum\Sanctum::actingAs($user->fresh());
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_tanpa_login_401(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_dibatasi_sppg_dan_tanggal(): void
    {
        $this->jadwal();
        $this->jadwal(['tanggal_produksi' => now()->addDays(2)->toDateString()]);
        $lain = $this->jadwal([], $this->sppgB->id);
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);

        $ids = collect($this->getJson(self::URL)->assertOk()->json('data'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertNotContains($lain->id, $ids->all());

        $hari = Carbon::now()->addDays(2)->toDateString();
        $this->getJson(self::URL.'?tanggal='.$hari)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::URL.'?tanggal='.Carbon::now()->addDays(9)->toDateString())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sppg_lain_tidak_bocor_dan_param_sppg_id_diabaikan_non_admin(): void
    {
        $this->jadwal();
        $lain = $this->jadwal([], $this->sppgB->id);
        $this->login(Role::KepalaSppg, $this->sppgA);

        $ids = collect($this->getJson(self::URL.'?sppg_id='.$this->sppgB->id)->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($lain->id, $ids->all());
        $this->assertCount(1, $ids);
    }

    public function test_format_tanggal_salah_422(): void
    {
        $this->login(Role::KepalaSppg);
        $this->getJson(self::URL.'?tanggal=besok')->assertUnprocessable()->assertJsonValidationErrors(['tanggal']);
    }
}

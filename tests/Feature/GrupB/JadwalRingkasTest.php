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
            ->assertJsonPath('data.0.status', 'direncanakan')
            ->assertJsonPath('data.0.tanggal_produksi', now()->toDateString());
    }

    public function test_hanya_kolom_ringkas_tanpa_data_sensitif(): void
    {
        $this->jadwal(['catatan' => 'rahasia internal']);
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);

        $respons = $this->getJson(self::URL)->assertOk();
        $row = $respons->json('data.0');
        // Kontrak minimal (peta hak): hanya 4 kolom; tanpa target porsi, catatan, anggaran, atau data pengguna.
        $this->assertEqualsCanonicalizing(['id', 'tanggal_produksi', 'nama_menu', 'status'], array_keys($row));
        $this->assertStringNotContainsString('rahasia internal', (string) $respons->getContent());
        $this->assertStringNotContainsString('800', (string) $respons->getContent());
    }

    public function test_rentang_from_to_dipakai_untuk_pilihan_batch_keluhan_dan_dibatasi(): void
    {
        $lama = $this->jadwal(['tanggal_produksi' => now()->subDays(10)->toDateString()]);
        $this->jadwal(['tanggal_produksi' => now()->subDays(40)->toDateString()]);
        $hariIni = $this->jadwal();
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);

        $ids = collect($this->getJson(self::URL.'?from='.now()->subDays(14)->toDateString().'&to='.now()->toDateString())->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$lama->id, $hariIni->id], $ids);

        $this->getJson(self::URL.'?from='.now()->subDays(100)->toDateString().'&to='.now()->toDateString())
            ->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->getJson(self::URL.'?from='.now()->toDateString().'&to='.now()->subDay()->toDateString())
            ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_batas_rentang_tepat_62_hari_lolos_dan_63_hari_ditolak(): void
    {
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);
        $to = now()->toDateString();

        $this->getJson(self::URL.'?from='.now()->subDays(62)->toDateString().'&to='.$to)->assertOk();
        $this->getJson(self::URL.'?from='.now()->subDays(63)->toDateString().'&to='.$to)
            ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_jalur_from_to_tidak_membocorkan_sppg_lain_dan_param_sppg_id_diabaikan_non_admin(): void
    {
        $milikA = $this->jadwal(['tanggal_produksi' => now()->subDays(3)->toDateString()]);
        $milikB = $this->jadwal(['tanggal_produksi' => now()->subDays(3)->toDateString()], $this->sppgB->id);
        $rentang = '?from='.now()->subDays(14)->toDateString().'&to='.now()->toDateString();

        foreach ([[Role::Relawan, RelawanUnit::Distribusi], [Role::KepalaSppg, null]] as [$role, $unit]) {
            $this->login($role, $this->sppgA, $unit);
            $ids = collect($this->getJson(self::URL.$rentang.'&sppg_id='.$this->sppgB->id)->assertOk()->json('data'))->pluck('id')->all();

            $this->assertSame([$milikA->id], $ids, $role->value);
            $this->assertNotContains($milikB->id, $ids);
        }

        // Admin boleh memilih SPPG lewat sppg_id pada jalur rentang.
        $this->login(Role::Admin);
        $ids = collect($this->getJson(self::URL.$rentang.'&sppg_id='.$this->sppgB->id)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$milikB->id], $ids);
    }

    public function test_peran_di_luar_hak_baca_jadwal_ditolak_403_termasuk_investor(): void
    {
        $this->jadwal();

        foreach ([Role::Akuntan, Role::Investor] as $role) {
            $user = $this->user($role);
            $this->masukPenuh($user);
            $this->getJson(self::URL)->assertForbidden();
        }
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
        $this->masukPenuh($user->fresh());
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

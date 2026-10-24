<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\Armada;
use App\Models\Pengemudi;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class ArmadaTest extends MasterApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge(['plat_nomor' => 'B 1234 XY', 'jenis' => 'Mobil Boks', 'kapasitas_box' => 600, 'status' => 'tersedia'], $override);
    }

    private function armada(int $sppgId, array $attrs = []): Armada
    {
        $this->forgetActor();

        return Armada::factory()->create(['sppg_id' => $sppgId] + $attrs);
    }

    public function test_kepala_membuat_armada_dan_plat_dinormalkan(): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/armada', $this->payload(['plat_nomor' => '  b   1234  xy ', 'sppg_id' => $this->sppgB]))
            ->assertCreated()
            ->assertJsonPath('data.plat_nomor', 'B 1234 XY')
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.status', 'tersedia');
    }

    public function test_validasi_gagal(): void
    {
        $this->as($this->kepalaA);

        $this->postJson('/api/v1/armada', [])->assertUnprocessable()->assertJsonValidationErrors(['plat_nomor', 'jenis']);
        $this->postJson('/api/v1/armada', $this->payload(['plat_nomor' => 'PLAT-NGASAL']))->assertUnprocessable()->assertJsonValidationErrors(['plat_nomor']);
        $this->postJson('/api/v1/armada', $this->payload(['status' => 'hilang']))->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->postJson('/api/v1/armada', $this->payload(['kapasitas_box' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['kapasitas_box']);
        $this->postJson('/api/v1/armada', $this->payload(['jenis' => str_repeat('a', 51)]))->assertUnprocessable()->assertJsonValidationErrors(['jenis']);
    }

    public function test_plat_nomor_unik_per_sppg_bukan_global(): void
    {
        $this->as($this->kepalaA);
        $this->postJson('/api/v1/armada', $this->payload())->assertCreated();
        $this->postJson('/api/v1/armada', $this->payload(['plat_nomor' => 'b 1234 xy']))->assertUnprocessable()->assertJsonValidationErrors(['plat_nomor']);

        // SPPG lain boleh memakai plat yang sama.
        $this->as($this->kepalaB)->postJson('/api/v1/armada', $this->payload())->assertCreated();
        // Admin: unik dihitung pada SPPG tujuan.
        $this->as($this->admin);
        $this->postJson('/api/v1/armada', $this->payload(['sppg_id' => $this->sppgA]))->assertUnprocessable()->assertJsonValidationErrors(['plat_nomor']);
        $this->postJson('/api/v1/armada', $this->payload(['sppg_id' => $this->createSppg('SPPG C')]))->assertCreated();
    }

    public function test_plat_milik_armada_terhapus_lunak_tetap_dianggap_terpakai(): void
    {
        $armada = $this->armada($this->sppgA, ['plat_nomor' => 'D 4321 AB']);
        $armada->delete();

        $this->as($this->kepalaA)->postJson('/api/v1/armada', $this->payload(['plat_nomor' => 'D 4321 AB']))
            ->assertUnprocessable()->assertJsonValidationErrors(['plat_nomor']);
    }

    public function test_ubah_armada_dengan_plat_sendiri_tidak_bentrok(): void
    {
        $a = $this->armada($this->sppgA, ['plat_nomor' => 'B 1111 AA']);
        $b = $this->armada($this->sppgA, ['plat_nomor' => 'B 2222 BB']);

        $this->as($this->kepalaA);
        $this->patchJson("/api/v1/armada/{$a->id}", ['plat_nomor' => 'B 1111 AA', 'status' => 'servis'])->assertOk()->assertJsonPath('data.status', 'servis');
        $this->patchJson("/api/v1/armada/{$a->id}", ['plat_nomor' => 'B 2222 BB'])->assertUnprocessable()->assertJsonValidationErrors(['plat_nomor']);
        $this->assertNotNull($b);
    }

    public function test_admin_wajib_sppg_id(): void
    {
        $this->as($this->admin)->postJson('/api/v1/armada', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
    }

    /** @return array<string, array{Role}> */
    public static function perananRead(): array
    {
        return ['akuntan' => [Role::Akuntan], 'relawan' => [Role::Relawan]];
    }

    /** @return array<string, array{Role}> */
    public static function perananTanpaAkses(): array
    {
        return ['ahli_gizi' => [Role::AhliGizi], 'staf_gudang' => [Role::StafGudang]];
    }

    #[DataProvider('perananRead')]
    public function test_akuntan_dan_relawan_hanya_membaca(Role $role): void
    {
        $armada = $this->armada($this->sppgA);
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->firstOrFail();

        $this->as($actor);
        $this->getJson('/api/v1/armada')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/armada/{$armada->id}")->assertOk();
        $this->postJson('/api/v1/armada', [])->assertForbidden();
        $this->patchJson("/api/v1/armada/{$armada->id}", ['status' => 'servis'])->assertForbidden();
        $this->deleteJson("/api/v1/armada/{$armada->id}")->assertForbidden();
    }

    #[DataProvider('perananTanpaAkses')]
    public function test_peran_tanpa_hak_ditolak_total(Role $role): void
    {
        $armada = $this->armada($this->sppgA);
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->firstOrFail();

        $this->as($actor);
        $this->getJson('/api/v1/armada')->assertForbidden();
        $this->getJson("/api/v1/armada/{$armada->id}")->assertForbidden();
        $this->postJson('/api/v1/armada', [])->assertForbidden();
        $this->getJson('/api/v1/pengemudi')->assertForbidden();
        $this->postJson('/api/v1/pengemudi', [])->assertForbidden();
    }

    public function test_lintas_sppg_404_dan_admin_lintas_sppg(): void
    {
        $milikB = $this->armada($this->sppgB);

        $this->as($this->kepalaA);
        $this->getJson("/api/v1/armada/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/armada/{$milikB->id}", ['status' => 'servis'])->assertNotFound();
        $this->deleteJson("/api/v1/armada/{$milikB->id}")->assertNotFound();
        $this->getJson('/api/v1/armada')->assertOk()->assertJsonPath('meta.total', 0);

        $this->as($this->admin);
        $this->getJson("/api/v1/armada/{$milikB->id}")->assertOk();
        $this->getJson('/api/v1/armada?sppg_id='.$this->sppgB)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_hapus_lunak_dan_armada_bertugas_tidak_dapat_dihapus(): void
    {
        $bebas = $this->armada($this->sppgA);
        $sibuk = $this->armada($this->sppgA, ['status' => 'bertugas']);

        $this->as($this->kepalaA);
        $this->deleteJson("/api/v1/armada/{$sibuk->id}")->assertUnprocessable()->assertJsonValidationErrors(['armada']);
        $this->assertDatabaseHas('armadas', ['id' => $sibuk->id, 'deleted_at' => null]);

        $this->deleteJson("/api/v1/armada/{$bebas->id}")->assertNoContent();
        $this->assertSoftDeleted('armadas', ['id' => $bebas->id]);
        $this->getJson("/api/v1/armada/{$bebas->id}")->assertNotFound();
    }

    public function test_pencarian_filter_status_dan_paginasi(): void
    {
        $this->armada($this->sppgA, ['plat_nomor' => 'B 1000 AA', 'jenis' => 'Pickup', 'status' => 'servis']);
        $this->armada($this->sppgA, ['plat_nomor' => 'B 2000 BB', 'jenis' => 'Mobil Boks']);
        $this->armada($this->sppgA, ['plat_nomor' => 'B 3000 CC', 'jenis' => 'Van']);

        $this->as($this->kepalaA);
        $this->getJson('/api/v1/armada?status=servis')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/armada?q=2000')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/armada?q=Van')->assertOk()->assertJsonPath('data.0.plat_nomor', 'B 3000 CC');
        $this->getJson('/api/v1/armada?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/armada')->assertUnauthorized();
        $this->getJson('/api/v1/pengemudi')->assertUnauthorized();
    }

    public function test_ai_draft_id(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create(['user_id' => $this->kepalaA->id, 'sppg_id' => $this->sppgA, 'module' => 'armada']);

        $this->as($this->kepalaA)->postJson('/api/v1/armada', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();

        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'confirmed']);
    }

    // ------------------------------------------------------------------ pengemudi

    private function pengemudi(int $sppgId, array $attrs = []): Pengemudi
    {
        $this->forgetActor();

        return Pengemudi::factory()->create(['sppg_id' => $sppgId] + $attrs);
    }

    /** @return array<string, mixed> */
    private function driverPayload(array $override = []): array
    {
        return array_merge([
            'nama' => 'Pak Udin',
            'no_hp' => '0812-0000-7777',
            'no_sim' => '123456789012',
            'sim_berlaku_hingga' => now()->addYear()->toDateString(),
        ], $override);
    }

    public function test_kepala_mengelola_pengemudi(): void
    {
        $res = $this->as($this->kepalaA)->postJson('/api/v1/pengemudi', $this->driverPayload(['sppg_id' => $this->sppgB]))
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Pak Udin')
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.no_sim', '123456789012')
            ->assertJsonPath('data.sim_status', 'berlaku');

        $id = $res->json('data.id');
        $this->patchJson("/api/v1/pengemudi/{$id}", ['nama' => 'Pak Udin S.', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson("/api/v1/pengemudi/{$id}")->assertNoContent();
        $this->assertSoftDeleted('pengemudis', ['id' => $id]);
    }

    public function test_pengemudi_tanpa_sim_boleh(): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/pengemudi', ['nama' => 'Mas Joko'])
            ->assertCreated()->assertJsonPath('data.no_sim', null)->assertJsonPath('data.sim_status', null);
    }

    public function test_validasi_masa_berlaku_sim(): void
    {
        $this->as($this->kepalaA);

        $this->postJson('/api/v1/pengemudi', [])->assertUnprocessable()->assertJsonValidationErrors(['nama']);
        // no_sim tanpa masa berlaku.
        $this->postJson('/api/v1/pengemudi', ['nama' => 'X', 'no_sim' => '123456789012'])->assertUnprocessable()->assertJsonValidationErrors(['sim_berlaku_hingga']);
        // SIM kedaluwarsa.
        $this->postJson('/api/v1/pengemudi', $this->driverPayload(['sim_berlaku_hingga' => now()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['sim_berlaku_hingga']);
        // Hari ini masih berlaku.
        $this->postJson('/api/v1/pengemudi', $this->driverPayload(['sim_berlaku_hingga' => today()->toDateString()]))->assertCreated();
        // Format salah.
        $this->postJson('/api/v1/pengemudi', $this->driverPayload(['sim_berlaku_hingga' => '31/12/2027']))->assertUnprocessable()->assertJsonValidationErrors(['sim_berlaku_hingga']);
        $this->postJson('/api/v1/pengemudi', $this->driverPayload(['no_sim' => '12']))->assertUnprocessable()->assertJsonValidationErrors(['no_sim']);
        $this->postJson('/api/v1/pengemudi', $this->driverPayload(['no_hp' => 'xx']))->assertUnprocessable()->assertJsonValidationErrors(['no_hp']);
    }

    public function test_sim_kedaluwarsa_lama_tidak_memblokir_ubah_data_lain_tetapi_pembaruan_harus_berlaku(): void
    {
        $p = $this->pengemudi($this->sppgA, ['no_sim' => '123456789012', 'sim_berlaku_hingga' => now()->subMonth()->toDateString()]);

        $this->as($this->kepalaA);
        $this->patchJson("/api/v1/pengemudi/{$p->id}", ['no_hp' => '0813-0000-1111'])->assertOk()->assertJsonPath('data.sim_status', 'kedaluwarsa');
        // Mengirim ulang tanggal lama yang tidak berubah tetap diterima.
        $this->putJson("/api/v1/pengemudi/{$p->id}", ['nama' => 'Baru', 'sim_berlaku_hingga' => now()->subMonth()->toDateString()])->assertOk();
        // Tanggal baru wajib belum lewat.
        $this->patchJson("/api/v1/pengemudi/{$p->id}", ['sim_berlaku_hingga' => now()->subDay()->toDateString()])->assertUnprocessable();
        $this->patchJson("/api/v1/pengemudi/{$p->id}", ['sim_berlaku_hingga' => now()->addDays(10)->toDateString()])->assertOk()->assertJsonPath('data.sim_status', 'segera_berakhir');
        // Menghapus tanggal saat no_sim masih ada ditolak.
        $this->patchJson("/api/v1/pengemudi/{$p->id}", ['sim_berlaku_hingga' => null])->assertUnprocessable()->assertJsonValidationErrors(['sim_berlaku_hingga']);
    }

    public function test_no_sim_disembunyikan_dari_peran_read_only(): void
    {
        $p = $this->pengemudi($this->sppgA, ['no_sim' => '123456789012', 'sim_berlaku_hingga' => now()->addYear()->toDateString()]);

        foreach ([$this->akuntanA, $this->relawanA] as $reader) {
            $this->as($reader);
            $this->getJson("/api/v1/pengemudi/{$p->id}")->assertOk()
                ->assertJsonMissingPath('data.no_sim')
                ->assertJsonMissingPath('data.sim_berlaku_hingga')
                ->assertJsonPath('data.sim_status', 'berlaku')
                ->assertJsonPath('data.nama', $p->nama);
            $this->getJson('/api/v1/pengemudi')->assertOk()->assertJsonMissingPath('data.0.no_sim');
            $this->assertStringNotContainsString('123456789012', (string) $this->getJson('/api/v1/pengemudi')->getContent());
            $this->postJson('/api/v1/pengemudi', $this->driverPayload())->assertForbidden();
            $this->patchJson("/api/v1/pengemudi/{$p->id}", ['nama' => 'X'])->assertForbidden();
            $this->deleteJson("/api/v1/pengemudi/{$p->id}")->assertForbidden();
        }

        foreach ([$this->kepalaA, $this->admin] as $writer) {
            $this->as($writer)->getJson("/api/v1/pengemudi/{$p->id}")->assertOk()->assertJsonPath('data.no_sim', '123456789012');
        }
    }

    public function test_pengemudi_lintas_sppg_dan_filter(): void
    {
        $this->pengemudi($this->sppgA, ['nama' => 'Aktif A']);
        $this->pengemudi($this->sppgA, ['nama' => 'Libur A', 'is_active' => false]);
        $milikB = $this->pengemudi($this->sppgB, ['nama' => 'Milik B']);

        $this->as($this->kepalaA);
        $this->getJson('/api/v1/pengemudi')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/pengemudi?is_active=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nama', 'Libur A');
        $this->getJson('/api/v1/pengemudi?q=Aktif')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/pengemudi/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/pengemudi/{$milikB->id}", ['nama' => 'X'])->assertNotFound();

        $this->as($this->admin)->getJson('/api/v1/pengemudi')->assertOk()->assertJsonPath('meta.total', 3);
        $this->postJson('/api/v1/pengemudi', $this->driverPayload())->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/pengemudi', $this->driverPayload(['sppg_id' => $this->sppgB]))->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB);
    }

    public function test_pengemudi_ai_draft_id(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create(['user_id' => $this->kepalaA->id, 'sppg_id' => $this->sppgA, 'module' => 'armada']);

        $this->as($this->kepalaA)->postJson('/api/v1/pengemudi', $this->driverPayload(['ai_draft_id' => $draf->uuid]))->assertCreated();

        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'confirmed']);
    }
}

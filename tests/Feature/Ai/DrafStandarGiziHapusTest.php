<?php

namespace Tests\Feature\Ai;

use App\Enums\AksiAiDraft;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\Armada;
use App\Models\BahanBaku;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\Pengemudi;
use App\Models\StandarGizi;
use App\Models\User;
use App\Services\Ai\AiDraftConfirmer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Feature\GrupB\GrupBTestCase;

/**
 * Issue #64 (draf standar gizi ditandai terpakai saat simpan) dan #65 (draf hapus ditandai terpakai saat
 * DELETE ?ai_draft_id=<uuid>; opsional, kompatibel mundur).
 */
class DrafStandarGiziHapusTest extends GrupBTestCase
{
    private const URL = '/api/v1/menu-gizi/standar-gizi';

    private function body(array $override = []): array
    {
        return array_merge(['kelompok_sasaran' => 'balita', 'energi_min' => 300, 'energi_max' => 450, 'protein_min' => 8, 'protein_max' => 15,
            'lemak_min' => 8, 'lemak_max' => 15, 'karbohidrat_min' => 40, 'karbohidrat_max' => 60, 'serat_min' => 3, 'serat_max' => 6], $override);
    }

    /** @param array<string, mixed> $atribut */
    private function draf(User $user, string $module, AksiAiDraft $aksi, ?int $target = null, ?string $resource = null, array $atribut = []): AiDraft
    {
        return AiDraft::factory()->create(array_merge([
            'sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => $module, 'action' => $aksi, 'target_id' => $target,
            'payload' => $resource === null ? [] : ['_resource' => $resource],
        ], $atribut));
    }

    public function test_draf_standar_gizi_confirmed_setelah_store_dan_update(): void
    {
        $user = $this->user(Role::AhliGizi);
        $buat = $this->draf($user, 'menu-gizi', AksiAiDraft::Create, null, 'standar_gizi');
        $this->masukPenuh($user);

        $id = $this->postJson(self::URL, $this->body(['ai_draft_id' => $buat->uuid]))->assertCreated()->json('data.id');
        $this->assertSame(StatusAiDraft::Confirmed, $buat->fresh()->status);

        $ubah = $this->draf($user, 'menu-gizi', AksiAiDraft::Update, $id, 'standar_gizi');
        $this->patchJson(self::URL."/{$id}", ['energi_max' => 500, 'ai_draft_id' => $ubah->uuid])->assertOk()->assertJsonPath('data.energi_max', 500);
        $this->assertSame(StatusAiDraft::Confirmed, $ubah->fresh()->status);
    }

    public function test_draf_standar_gizi_store_untuk_record_terhapus_tetap_create(): void
    {
        $user = $this->user(Role::AhliGizi);
        $lama = StandarGizi::factory()->create(['sppg_id' => $this->sppgA->id, 'kelompok_sasaran' => 'balita']);
        $lama->delete();
        $buat = $this->draf($user, 'menu-gizi', AksiAiDraft::Create, null, 'standar_gizi');
        $this->masukPenuh($user);

        $this->postJson(self::URL, $this->body(['ai_draft_id' => $buat->uuid]))->assertCreated()->assertJsonPath('data.id', $lama->id);
        $this->assertSame(StatusAiDraft::Confirmed, $buat->fresh()->status);
    }

    public function test_draf_standar_gizi_tidak_cocok_422_dan_data_tidak_berubah(): void
    {
        $user = $this->user(Role::AhliGizi);
        $lain = User::factory()->role(Role::AhliGizi)->create(['sppg_id' => $this->sppgA->id]);
        $standar = StandarGizi::factory()->create(['sppg_id' => $this->sppgA->id, 'kelompok_sasaran' => 'siswa', 'energi_max' => 700]);
        $salah = [
            $this->draf($lain, 'menu-gizi', AksiAiDraft::Create, null, 'standar_gizi'),            // pengguna lain
            $this->draf($user, 'bahan-baku', AksiAiDraft::Create, null, 'standar_gizi'),           // modul lain
            $this->draf($user, 'menu-gizi', AksiAiDraft::Create),                                  // _resource menu, bukan standar gizi
            $this->draf($user, 'menu-gizi', AksiAiDraft::Update, $standar->id, 'standar_gizi'),    // aksi salah untuk store
            $this->draf($user, 'menu-gizi', AksiAiDraft::Create, null, 'standar_gizi', ['expires_at' => now()->subMinute()]),
        ];
        $this->masukPenuh($user);

        foreach ($salah as $draf) {
            $this->postJson(self::URL, $this->body(['ai_draft_id' => $draf->uuid]))->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        }
        $this->assertSame(1, StandarGizi::query()->count(), 'store dibatalkan');

        // Update: draf untuk target lain dan draf tanpa target ditolak.
        $drafTargetLain = $this->draf($user, 'menu-gizi', AksiAiDraft::Update, $standar->id + 99, 'standar_gizi');
        $drafCreate = $this->draf($user, 'menu-gizi', AksiAiDraft::Create, null, 'standar_gizi');

        foreach ([$drafTargetLain, $drafCreate] as $draf) {
            $this->patchJson(self::URL."/{$standar->id}", ['energi_max' => 900, 'ai_draft_id' => $draf->uuid])->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        }
        $this->assertEquals(700, $standar->fresh()->energi_max);
        $this->assertSame(StatusAiDraft::Pending, $drafCreate->fresh()->status);
    }

    public function test_draf_hapus_standar_gizi_dan_bahan_baku_confirmed_setelah_delete(): void
    {
        $user = $this->user(Role::AhliGizi);
        $standar = StandarGizi::factory()->create(['sppg_id' => $this->sppgA->id]);
        $drafStandar = $this->draf($user, 'menu-gizi', AksiAiDraft::Delete, $standar->id, 'standar_gizi');
        $this->masukPenuh($user);

        $this->deleteJson(self::URL."/{$standar->id}?ai_draft_id={$drafStandar->uuid}")->assertNoContent();
        $this->assertSoftDeleted('standar_gizis', ['id' => $standar->id]);
        $this->assertSame(StatusAiDraft::Confirmed, $drafStandar->fresh()->status);

        $gudang = $this->user(Role::StafGudang);
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id]);
        $drafBahan = $this->draf($gudang, 'bahan-baku', AksiAiDraft::Delete, $bahan->id);
        $this->masukPenuh($gudang);

        $this->deleteJson("/api/v1/bahan-baku/{$bahan->id}?ai_draft_id={$drafBahan->uuid}")->assertNoContent();
        $this->assertSame(StatusAiDraft::Confirmed, $drafBahan->fresh()->status);

        // Draf yang sudah terpakai tidak dapat dipakai ulang.
        $bahan2 = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id]);
        $this->deleteJson("/api/v1/bahan-baku/{$bahan2->id}?ai_draft_id={$drafBahan->uuid}")->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->assertNotSoftDeleted('bahan_bakus', ['id' => $bahan2->id]);
    }

    public function test_draf_hapus_menu_dan_sub_modul_pengemudi(): void
    {
        $ahli = $this->user(Role::AhliGizi);
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA->id]);
        $drafMenu = $this->draf($ahli, 'menu-gizi', AksiAiDraft::Delete, $menu->id);
        $this->masukPenuh($ahli);

        $this->deleteJson("/api/v1/menu-gizi/{$menu->id}?ai_draft_id={$drafMenu->uuid}")->assertNoContent();
        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
        $this->assertSame(StatusAiDraft::Confirmed, $drafMenu->fresh()->status);

        $kepala = $this->user(Role::KepalaSppg);
        $pengemudi = Pengemudi::factory()->create(['sppg_id' => $this->sppgA->id]);
        $armada = Armada::factory()->create(['sppg_id' => $this->sppgA->id]);
        $drafPengemudi = $this->draf($kepala, 'armada', AksiAiDraft::Delete, $pengemudi->id, 'pengemudi');
        $this->masukPenuh($kepala);

        // Draf pengemudi (_resource) tidak dapat dipakai untuk menghapus armada.
        $this->deleteJson("/api/v1/armada/{$armada->id}?ai_draft_id={$drafPengemudi->uuid}")->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->assertNotSoftDeleted('armadas', ['id' => $armada->id]);

        $this->deleteJson("/api/v1/pengemudi/{$pengemudi->id}?ai_draft_id={$drafPengemudi->uuid}")->assertNoContent();
        $this->assertSame(StatusAiDraft::Confirmed, $drafPengemudi->fresh()->status);
    }

    public function test_draf_hapus_tidak_sah_422_dan_data_tidak_berubah(): void
    {
        $user = $this->user(Role::AhliGizi);
        $lain = User::factory()->role(Role::AhliGizi)->create(['sppg_id' => $this->sppgA->id]);
        $standar = StandarGizi::factory()->create(['sppg_id' => $this->sppgA->id]);
        $salah = [
            $this->draf($lain, 'menu-gizi', AksiAiDraft::Delete, $standar->id, 'standar_gizi'),
            $this->draf($user, 'bahan-baku', AksiAiDraft::Delete, $standar->id, 'standar_gizi'),
            $this->draf($user, 'menu-gizi', AksiAiDraft::Update, $standar->id, 'standar_gizi'),
            $this->draf($user, 'menu-gizi', AksiAiDraft::Delete, $standar->id + 1, 'standar_gizi'),
            $this->draf($user, 'menu-gizi', AksiAiDraft::Delete, $standar->id, 'standar_gizi', ['expires_at' => now()->subMinute()]),
        ];
        $this->masukPenuh($user);

        foreach ($salah as $draf) {
            $this->deleteJson(self::URL."/{$standar->id}?ai_draft_id={$draf->uuid}")->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        }
        $this->assertNotSoftDeleted('standar_gizis', ['id' => $standar->id]);

        // Format bukan uuid = 422; tanpa parameter = perilaku lama (204).
        $this->deleteJson(self::URL."/{$standar->id}?ai_draft_id=bukan-uuid")->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->assertNotSoftDeleted('standar_gizis', ['id' => $standar->id]);
        $this->deleteJson(self::URL."/{$standar->id}")->assertNoContent();
    }

    public function test_guard_bisnis_lebih_dulu_dan_tanpa_hak_403_draf_tetap_pending(): void
    {
        $ahli = $this->user(Role::AhliGizi);
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA->id]);
        JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id]);
        $draf = $this->draf($ahli, 'menu-gizi', AksiAiDraft::Delete, $menu->id);
        $this->masukPenuh($ahli);

        $this->deleteJson("/api/v1/menu-gizi/{$menu->id}?ai_draft_id={$draf->uuid}")->assertUnprocessable()->assertJsonValidationErrors(['menu']);
        $this->assertSame(StatusAiDraft::Pending, $draf->fresh()->status);
        $this->assertNotSoftDeleted('menus', ['id' => $menu->id]);

        $gudang = $this->user(Role::StafGudang);
        $standar = StandarGizi::factory()->create(['sppg_id' => $this->sppgA->id]);
        $drafGudang = $this->draf($gudang, 'menu-gizi', AksiAiDraft::Delete, $standar->id, 'standar_gizi');
        $this->masukPenuh($gudang);

        $this->deleteJson(self::URL."/{$standar->id}?ai_draft_id={$drafGudang->uuid}")->assertForbidden();
        $this->assertSame(StatusAiDraft::Pending, $drafGudang->fresh()->status);
        $this->assertNotSoftDeleted('standar_gizis', ['id' => $standar->id]);
    }

    public function test_helper_uuid_dari_query(): void
    {
        $confirmer = new AiDraftConfirmer;

        $this->assertNull($confirmer->uuidDariQuery(Request::create('/x', 'DELETE')));
        $uuid = '7b1f0f0e-5a53-4d9c-8d6f-0c1b2a3d4e5f';
        $this->assertSame($uuid, $confirmer->uuidDariQuery(Request::create('/x?ai_draft_id='.$uuid, 'DELETE')));

        $this->expectException(ValidationException::class);
        $confirmer->uuidDariQuery(Request::create('/x?ai_draft_id=xyz', 'DELETE'));
    }
}

<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\Invitation;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Users\InvitationService;
use PHPUnit\Framework\Attributes\DataProvider;

class TitikLayananTest extends MasterApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'nama' => 'SDN 01 Fiktif',
            'jenis' => 'sekolah',
            'alamat' => 'Jl. Fiktif No. 5',
            'jarak_km' => 3.5,
            'jumlah_sasaran' => 240,
            'pic_nama' => 'Bu Ani',
            'pic_telepon' => '0812-0000-5555',
        ], $override);
    }

    private function titik(int $sppgId, array $attrs = []): TitikLayanan
    {
        $this->forgetActor();

        return TitikLayanan::factory()->create(['sppg_id' => $sppgId] + $attrs);
    }

    public function test_kepala_membuat_titik_dan_sppg_id_dari_body_diabaikan(): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/titik-layanan', $this->payload(['sppg_id' => $this->sppgB, 'pic_telegram_chat_id' => 123456]))
            ->assertCreated()
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.jarak_km', '3.50')
            ->assertJsonPath('data.pic_terhubung', false)
            ->assertJsonMissingPath('data.pic_telegram_chat_id');

        $this->assertDatabaseHas('titik_layanans', ['nama' => 'SDN 01 Fiktif', 'sppg_id' => $this->sppgA, 'pic_telegram_chat_id' => null]);
    }

    public function test_admin_wajib_menyebut_sppg_id(): void
    {
        $this->as($this->admin);

        $this->postJson('/api/v1/titik-layanan', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/titik-layanan', $this->payload(['sppg_id' => 99999]))->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/titik-layanan', $this->payload(['sppg_id' => $this->sppgB]))->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB);
    }

    public function test_validasi_gagal(): void
    {
        $this->as($this->kepalaA);

        $this->postJson('/api/v1/titik-layanan', [])->assertUnprocessable()->assertJsonValidationErrors(['nama', 'jenis', 'alamat', 'pic_nama']);
        $this->postJson('/api/v1/titik-layanan', $this->payload(['jenis' => 'pasar']))->assertUnprocessable()->assertJsonValidationErrors(['jenis']);
        $this->postJson('/api/v1/titik-layanan', $this->payload(['jarak_km' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['jarak_km']);
        $this->postJson('/api/v1/titik-layanan', $this->payload(['jumlah_sasaran' => -5]))->assertUnprocessable()->assertJsonValidationErrors(['jumlah_sasaran']);
        $this->postJson('/api/v1/titik-layanan', $this->payload(['pic_telepon' => '12']))->assertUnprocessable()->assertJsonValidationErrors(['pic_telepon']);
    }

    /** @return array<string, array{Role}> */
    public static function perananRead(): array
    {
        return [
            'ahli_gizi' => [Role::AhliGizi],
            'akuntan' => [Role::Akuntan],
            'staf_gudang' => [Role::StafGudang],
            'relawan' => [Role::Relawan],
        ];
    }

    #[DataProvider('perananRead')]
    public function test_peran_read_only_boleh_baca_tidak_boleh_tulis(Role $role): void
    {
        $titik = $this->titik($this->sppgA);
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->firstOrFail();

        $this->as($actor);
        $this->getJson('/api/v1/titik-layanan')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/titik-layanan/{$titik->id}")->assertOk();
        $this->postJson('/api/v1/titik-layanan', [])->assertForbidden();
        $this->patchJson("/api/v1/titik-layanan/{$titik->id}", ['nama' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/titik-layanan/{$titik->id}")->assertForbidden();
        $this->postJson("/api/v1/titik-layanan/{$titik->id}/undangan-pic")->assertForbidden();
    }

    public function test_lintas_sppg_tidak_terlihat_dan_tidak_dapat_diubah(): void
    {
        $milikB = $this->titik($this->sppgB);

        $this->as($this->kepalaA);
        $this->getJson('/api/v1/titik-layanan')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/titik-layanan/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/titik-layanan/{$milikB->id}", ['nama' => 'X'])->assertNotFound();
        $this->deleteJson("/api/v1/titik-layanan/{$milikB->id}")->assertNotFound();
        $this->postJson("/api/v1/titik-layanan/{$milikB->id}/undangan-pic")->assertNotFound();
        $this->assertDatabaseHas('titik_layanans', ['id' => $milikB->id, 'deleted_at' => null]);
    }

    public function test_admin_lintas_sppg_dan_filter(): void
    {
        $this->titik($this->sppgA, ['nama' => 'Posyandu Kenanga', 'jenis' => 'posyandu']);
        $this->titik($this->sppgB, ['nama' => 'SDN Lain', 'jenis' => 'sekolah', 'is_active' => false]);

        $this->as($this->admin);
        $this->getJson('/api/v1/titik-layanan')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/titik-layanan?sppg_id='.$this->sppgB)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/titik-layanan?jenis=posyandu')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/titik-layanan?is_active=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nama', 'SDN Lain');
        $this->getJson('/api/v1/titik-layanan?q=Kenanga')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/titik-layanan?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_ubah_dan_hapus_lunak_oleh_kepala(): void
    {
        $titik = $this->titik($this->sppgA);

        $this->as($this->kepalaA);
        $this->patchJson("/api/v1/titik-layanan/{$titik->id}", ['jumlah_sasaran' => 300, 'sppg_id' => $this->sppgB])
            ->assertOk()->assertJsonPath('data.jumlah_sasaran', 300)->assertJsonPath('data.sppg_id', $this->sppgA);

        $this->deleteJson("/api/v1/titik-layanan/{$titik->id}")->assertNoContent();
        $this->assertSoftDeleted('titik_layanans', ['id' => $titik->id]);
        $this->getJson("/api/v1/titik-layanan/{$titik->id}")->assertNotFound();
    }

    public function test_admin_tidak_memindahkan_sppg_saat_ubah(): void
    {
        $titik = $this->titik($this->sppgA);

        $this->as($this->admin)->patchJson("/api/v1/titik-layanan/{$titik->id}", ['sppg_id' => $this->sppgB, 'nama' => 'Baru'])->assertOk();

        $this->assertDatabaseHas('titik_layanans', ['id' => $titik->id, 'sppg_id' => $this->sppgA, 'nama' => 'Baru']);
    }

    public function test_pic_terhubung_tampil_sebagai_boolean_tanpa_chat_id(): void
    {
        $titik = $this->titik($this->sppgA, ['pic_telegram_chat_id' => 900123456]);

        $this->as($this->kepalaA)->getJson("/api/v1/titik-layanan/{$titik->id}")
            ->assertOk()->assertJsonPath('data.pic_terhubung', true)->assertJsonMissingPath('data.pic_telegram_chat_id');
    }

    public function test_kepala_menerbitkan_undangan_pic(): void
    {
        $titik = $this->titik($this->sppgA);

        $res = $this->as($this->kepalaA)->postJson("/api/v1/titik-layanan/{$titik->id}/undangan-pic")
            ->assertCreated()
            ->assertJsonPath('data.tipe', 'pic')
            ->assertJsonPath('data.titik_layanan_id', $titik->id)
            ->assertJsonPath('data.status', 'aktif');

        $token = $res->json('data.token');
        $this->assertSame("https://t.me/sigap_test_bot?start=inv_{$token}", $res->json('data.deep_link'));
        $inv = Invitation::query()->findOrFail($res->json('data.id'));
        $this->assertSame(hash('sha256', $token), $inv->token_hash);
        $this->assertSame($titik->id, $inv->titik_layanan_id);
        $this->assertSame($this->kepalaA->id, $inv->created_by);

        // Bot menautkan PIC lewat InvitationService.
        $this->forgetActor();
        app(InvitationService::class)->consumePic($token, ['id' => 7_200_000_001, 'first_name' => 'Ani']);
        $this->assertTrue($titik->fresh()->picTerhubung());
        $this->as($this->kepalaA)->getJson("/api/v1/titik-layanan/{$titik->id}")->assertJsonPath('data.pic_terhubung', true);
    }

    public function test_undangan_pic_baru_mencabut_yang_lama(): void
    {
        $titik = $this->titik($this->sppgA);

        $this->as($this->kepalaA);
        $a = $this->postJson("/api/v1/titik-layanan/{$titik->id}/undangan-pic")->json('data.id');
        $this->postJson("/api/v1/titik-layanan/{$titik->id}/undangan-pic")->assertCreated();

        $this->assertNotNull(Invitation::find($a)->revoked_at);
    }

    public function test_admin_menerbitkan_undangan_pic_untuk_sppg_mana_pun(): void
    {
        $titik = $this->titik($this->sppgB);

        $this->as($this->admin)->postJson("/api/v1/titik-layanan/{$titik->id}/undangan-pic")
            ->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB);
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/titik-layanan')->assertUnauthorized();
        $this->postJson('/api/v1/titik-layanan/1/undangan-pic')->assertUnauthorized();
    }

    public function test_ai_draft_id(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create(['user_id' => $this->kepalaA->id, 'sppg_id' => $this->sppgA, 'module' => 'titik-layanan']);

        $this->as($this->kepalaA)->postJson('/api/v1/titik-layanan', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'confirmed']);

        // Dipakai ulang: ditolak dan tidak membuat data kedua.
        $this->postJson('/api/v1/titik-layanan', $this->payload(['ai_draft_id' => $draf->uuid]))->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->assertSame(1, TitikLayanan::withoutGlobalScopes()->count());
    }
}

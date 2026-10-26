<?php

namespace Tests\Feature\Ai;

use App\Enums\AksiAiDraft;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use Tests\Feature\Master\MasterApiTestCase;

class AiDraftApiTest extends MasterApiTestCase
{
    /** @param array<string, mixed> $attrs */
    private function draft(User $owner, array $attrs = []): AiDraft
    {
        $this->forgetActor();

        return AiDraft::factory()->create(array_merge([
            'user_id' => $owner->id,
            'sppg_id' => $owner->sppg_id,
            'module' => 'titik-layanan',
            'action' => AksiAiDraft::Create,
            'payload' => ['nama' => 'SDN 3 Fiktif', 'jenis' => 'sekolah', 'alamat' => 'Jl. Fiktif 3', 'pic_nama' => 'Bu Ani'],
            'confidence' => ['nama' => 0.95, 'jenis' => 0.9, 'alamat' => 0.5, 'pic_nama' => 0.8],
        ], $attrs));
    }

    public function test_pemilik_membaca_draf_dengan_kontrak_lengkap(): void
    {
        $draft = $this->draft($this->kepalaA);

        $this->as($this->kepalaA)->getJson("/api/v1/ai-drafts/{$draft->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $draft->uuid)
            ->assertJsonPath('data.module', 'titik-layanan')
            ->assertJsonPath('data.action', 'create')
            ->assertJsonPath('data.payload.nama', 'SDN 3 Fiktif')
            ->assertJsonPath('data.confidence.alamat', 0.5)
            ->assertJsonPath('data.perlu_dicek', ['alamat'])
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.form', '/titik-layanan/baru')
            ->assertJsonStructure(['data' => ['uuid', 'module', 'action', 'payload', 'confidence', 'status', 'expires_at']]);
    }

    public function test_draf_hanya_untuk_pemilik_lainnya_404(): void
    {
        $draft = $this->draft($this->kepalaA);
        $rekanSppg = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->sppgA]);

        $this->as($rekanSppg)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNotFound();
        $this->as($this->kepalaB)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNotFound();
        $this->as($this->admin)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNotFound();
        $this->as($this->kepalaB)->deleteJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNotFound();
        $this->as($this->kepalaA)->getJson('/api/v1/ai-drafts/bukan-uuid')->assertNotFound();
        $this->as($this->kepalaA)->getJson('/api/v1/ai-drafts/'.fake()->uuid())->assertNotFound();

        $this->assertSame(StatusAiDraft::Pending, $draft->fresh()->status);
    }

    public function test_draf_pindah_sppg_tidak_lagi_terbaca_pemilik(): void
    {
        $draft = $this->draft($this->kepalaA, ['sppg_id' => $this->sppgB]);

        $this->as($this->kepalaA)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNotFound();
    }

    public function test_tanpa_token_401(): void
    {
        $draft = $this->draft($this->kepalaA);

        $this->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertUnauthorized();
        $this->getJson('/api/v1/ai-drafts')->assertUnauthorized();
    }

    public function test_draf_kedaluwarsa_terpakai_dibuang_410(): void
    {
        $kedaluwarsa = $this->draft($this->kepalaA, ['expires_at' => now()->subMinute()]);
        $terpakai = $this->draft($this->kepalaA, ['status' => StatusAiDraft::Confirmed, 'confirmed_at' => now()]);
        $dibuang = $this->draft($this->kepalaA, ['status' => StatusAiDraft::Discarded]);

        $this->as($this->kepalaA);
        $this->getJson("/api/v1/ai-drafts/{$kedaluwarsa->uuid}")->assertStatus(410)->assertJsonPath('message', 'Draf sudah kedaluwarsa. Kirim ulang pesan ke bot atau isi formulir manual.');
        $this->getJson("/api/v1/ai-drafts/{$terpakai->uuid}")->assertStatus(410);
        $this->getJson("/api/v1/ai-drafts/{$dibuang->uuid}")->assertStatus(410);

        $this->assertSame(StatusAiDraft::Expired, $kedaluwarsa->fresh()->status);
    }

    public function test_buang_draf_idempoten_dan_draf_tersimpan_409(): void
    {
        $draft = $this->draft($this->kepalaA);
        $terpakai = $this->draft($this->kepalaA, ['status' => StatusAiDraft::Confirmed, 'confirmed_at' => now()]);

        $this->as($this->kepalaA);
        $this->deleteJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNoContent();
        $this->deleteJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNoContent();
        $this->deleteJson("/api/v1/ai-drafts/{$terpakai->uuid}")->assertStatus(409);

        $this->assertSame(StatusAiDraft::Discarded, $draft->fresh()->status);
        $this->assertSame(StatusAiDraft::Confirmed, $terpakai->fresh()->status);

        // Draf yang dibuang tidak dapat dipakai menyimpan data.
        $this->postJson('/api/v1/titik-layanan', $draft->payload + ['ai_draft_id' => $draft->uuid])->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
    }

    public function test_daftar_hanya_draf_pending_milik_sendiri(): void
    {
        $milik = $this->draft($this->kepalaA);
        $this->draft($this->kepalaA, ['expires_at' => now()->subMinute()]);
        $this->draft($this->kepalaA, ['status' => StatusAiDraft::Discarded]);
        $this->draft($this->ahliGiziA);
        $this->draft($this->kepalaB);

        $this->as($this->kepalaA)->getJson('/api/v1/ai-drafts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $milik->uuid);
    }

    public function test_detail_memuat_galat_validasi_dry_run_terkini(): void
    {
        $draft = $this->draft($this->kepalaA, ['payload' => ['nama' => 'SD Tanpa Jenis', 'jenis' => 'pasar'], 'confidence' => ['nama' => 0.9, 'jenis' => 0.9]]);

        $this->as($this->kepalaA)->getJson("/api/v1/ai-drafts/{$draft->uuid}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['errors' => ['jenis', 'alamat', 'pic_nama']]])
            ->assertJsonPath('data.perlu_dicek', ['jenis', 'alamat', 'pic_nama']);
    }

    public function test_pemilik_yang_kehilangan_hak_modul_ditolak_403(): void
    {
        // Ahli gizi hanya R pada titik-layanan; draf create tidak boleh dilanjutkan.
        $draft = $this->draft($this->ahliGiziA);

        $this->as($this->ahliGiziA)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertForbidden();
    }

    public function test_draf_dengan_penanda_sub_modul_tak_dikenal_ditolak_403(): void
    {
        $draft = $this->draft($this->kepalaA, ['payload' => ['nama' => 'SDN 3 Fiktif', '_resource' => 'pengeluaran']]);

        $this->as($this->kepalaA)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertForbidden();
    }

    public function test_draf_sub_modul_tidak_dapat_dikonfirmasi_lewat_endpoint_sumber_daya_lain(): void
    {
        // Draf penanda 'pengeluaran' (modul titik-layanan tidak punya sub-modul) ditolak endpoint bawaan.
        $draft = $this->draft($this->kepalaA, ['payload' => ['nama' => 'SDN 3 Fiktif', '_resource' => 'pengeluaran']]);

        $this->as($this->kepalaA)->postJson('/api/v1/titik-layanan', [
            'nama' => 'SDN 3 Fiktif', 'jenis' => 'sekolah', 'alamat' => 'Jl. Fiktif 3', 'pic_nama' => 'Bu Ani', 'ai_draft_id' => $draft->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);

        $this->assertSame(StatusAiDraft::Pending, $draft->fresh()->status);
    }

    public function test_draf_create_tidak_dapat_dikonfirmasi_lewat_update(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $draft = $this->draft($this->kepalaA);

        $this->as($this->kepalaA)->putJson("/api/v1/titik-layanan/{$titik->id}", ['nama' => 'Ubah Fiktif', 'ai_draft_id' => $draft->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ai_draft_id']);

        $this->assertSame(StatusAiDraft::Pending, $draft->fresh()->status);
        $this->assertNotSame('Ubah Fiktif', $titik->fresh()->nama);
    }

    public function test_draf_update_hanya_untuk_target_yang_sama(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $lain = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $draft = $this->draft($this->kepalaA, ['action' => AksiAiDraft::Update, 'target_id' => $titik->id, 'payload' => ['nama' => 'Ubah Fiktif']]);

        $this->as($this->kepalaA)->putJson("/api/v1/titik-layanan/{$lain->id}", ['nama' => 'Ubah Fiktif', 'ai_draft_id' => $draft->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ai_draft_id']);

        // Draf update juga tidak dapat dipakai untuk membuat data baru.
        $this->postJson('/api/v1/titik-layanan', ['nama' => 'Baru Fiktif', 'jenis' => 'sekolah', 'alamat' => 'Jl. Fiktif 9', 'pic_nama' => 'Bu Fiktif', 'ai_draft_id' => $draft->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ai_draft_id']);

        $this->putJson("/api/v1/titik-layanan/{$titik->id}", ['nama' => 'Ubah Fiktif', 'ai_draft_id' => $draft->uuid])->assertOk();
        $this->assertSame(StatusAiDraft::Confirmed, $draft->fresh()->status);
    }

    public function test_alur_lengkap_chat_ke_draf_ke_konfirmasi_mini_app(): void
    {
        $llm = new FakeLlmClient([
            LlmResponse::toolCall('buat_titik_layanan', ['nama' => 'SDN 2 Sukamaju', 'jenis' => 'sekolah', 'alamat' => 'Jl. Sukamaju 2', 'jarak_km' => 3.5, 'jumlah_sasaran' => 240, 'pic_nama' => 'Bu Rina']),
            LlmResponse::text('Draf siap.'),
        ]);
        $this->app->instance(LlmClient::class, $llm);
        $this->forgetActor();
        $reply = $this->app->make(AiAgentService::class)->handle($this->kepalaA, AiInput::text('Tambah SDN 2 Sukamaju, 3,5 km, 240 siswa, PIC Bu Rina'));
        $uuid = $reply->drafts[0]->uuid;

        $this->assertSame(0, TitikLayanan::query()->withoutGlobalScopes()->count());

        $data = $this->as($this->kepalaA)->getJson("/api/v1/ai-drafts/{$uuid}")->assertOk()->json('data');
        $this->postJson('/api/v1/titik-layanan', $data['payload'] + ['ai_draft_id' => $uuid])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'SDN 2 Sukamaju')
            ->assertJsonPath('data.sppg_id', $this->sppgA);

        $this->assertSame(StatusAiDraft::Confirmed, AiDraft::query()->withoutGlobalScopes()->where('uuid', $uuid)->sole()->status);
        $this->getJson("/api/v1/ai-drafts/{$uuid}")->assertStatus(410);
    }
}

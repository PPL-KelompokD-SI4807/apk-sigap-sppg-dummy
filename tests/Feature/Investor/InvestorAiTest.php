<?php

namespace Tests\Feature\Investor;

use App\Enums\HasilAi;
use App\Models\AiDraft;
use App\Models\AiInteraction;
use App\Models\FeedbackKeluhan;
use App\Models\PenerimaManfaat;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Tools\ToolRegistry;

/**
 * R-06a Agen AI: investor hanya tool baca/ringkas, tidak pernah membuat/mengonfirmasi AiDraft,
 * memakai SPPG aktif, dan fail closed di chat bot bila memiliki lebih dari satu SPPG.
 */
class InvestorAiTest extends InvestorTestCase
{
    private FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
        $this->forgetActor();
    }

    public function test_investor_hanya_ditawari_tool_baca(): void
    {
        $nama = array_keys(app(ToolRegistry::class)->forUser($this->investorC));

        $this->assertNotEmpty($nama);
        $this->assertSame([], array_values(array_filter($nama, fn ($n) => preg_match('/^(buat|ubah|hapus|catat)_/', $n) === 1)), implode(',', $nama));
        $this->assertSame([], array_values(array_filter($nama, fn ($n) => str_contains($n, 'penerima_manfaat'))));
        $this->assertContains('cari_keluhan', $nama);
    }

    public function test_tool_baca_memakai_sppg_aktif_dan_tool_tulis_ditolak_tanpa_draf(): void
    {
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgC, 'deskripsi' => 'Keluhan SPPG C']);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Keluhan SPPG A']);
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgC, 'nama' => 'Anak Rahasia AI']);

        $this->llm
            ->push(LlmResponse::toolCall('cari_keluhan', []))
            ->push(LlmResponse::toolCall('buat_keluhan', ['kategori' => 'rasa', 'deskripsi' => 'Palsu']))
            ->push(LlmResponse::toolCall('cari_penerima_manfaat', ['q' => 'Anak']))
            ->push(LlmResponse::text('Selesai.'));

        $this->app->make(AiAgentService::class)->handle($this->investorC, AiInput::text('ringkas keluhan dan buat keluhan baru'));

        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, FeedbackKeluhan::query()->withoutGlobalScopes()->where('sppg_id', $this->sppgC)->count());

        $semua = json_encode($this->llm->calls, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Keluhan SPPG C', $semua);
        $this->assertStringNotContainsString('Keluhan SPPG A', $semua);
        $this->assertStringNotContainsString('Anak Rahasia AI', $semua);

        $log = AiInteraction::query()->sole();
        $this->assertSame($this->sppgC, $log->sppg_id);
        $status = array_column($log->argumen['tools'], 'status', 'nama');
        $this->assertSame('ok', $status['cari_keluhan']);
        $this->assertNotSame('draft', $status['buat_keluhan']);
        $this->assertNotSame('ok', $status['cari_penerima_manfaat']);
    }

    public function test_bot_investor_lebih_dari_satu_sppg_fail_closed(): void
    {
        $this->llm->push(LlmResponse::toolCall('cari_keluhan', []))->push(LlmResponse::text('x'));

        $reply = $this->app->make(AiAgentService::class)->handle($this->investorAB, AiInput::text('ringkas keluhan'));

        $this->assertTrue($reply->fallback);
        $this->assertStringContainsString('pilih SPPG aktif', $reply->text);
        $this->assertSame([], $this->llm->calls, 'LLM tidak boleh dipanggil tanpa SPPG aktif.');
        $this->assertSame(HasilAi::Ditolak, AiInteraction::query()->sole()->hasil);
    }

    public function test_investor_tidak_dapat_mengonfirmasi_draf_lewat_endpoint_modul(): void
    {
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $this->investorAB->id, 'module' => 'keluhan']);

        $this->sebagai($this->investorAB, $this->sppgA)
            ->postJson('/api/v1/keluhan', ['kategori' => 'rasa', 'sumber' => 'sekolah', 'deskripsi' => 'Dari draf', 'ai_draft_id' => $draf->uuid])
            ->assertForbidden();

        $this->assertSame('pending', $draf->refresh()->status->value);
    }
}

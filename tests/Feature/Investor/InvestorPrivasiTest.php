<?php

namespace Tests\Feature\Investor;

use App\Models\FeedbackKeluhan;
use App\Models\PemeriksaanMutu;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Catalog\RecordPresenter;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Storage\SecureUploadService;
use App\Support\LampiranTersembunyi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Telegram\FakesTelegram;
use Tests\Support\GambarUji;

/**
 * R06a-S-05 (foto keluhan/mutu disembunyikan dari investor; deskripsi keluhan ke LLM disamarkan) dan
 * R06a-S-07 (sapaan bot investor). Semua nama/kontak fiktif.
 */
class InvestorPrivasiTest extends InvestorTestCase
{
    use FakesTelegram, GambarUji;

    private function lampirkan(Model $induk, string $modul): int
    {
        $this->forgetActor();

        return (int) app(SecureUploadService::class)->unggah($this->fotoJpeg(), $induk, $modul, $this->kepalaA, 'foto')->getKey();
    }

    // ---------------------------------------------------------------- S-05 lampiran

    public function test_foto_keluhan_dan_mutu_disembunyikan_dari_investor_teks_tetap_terlihat(): void
    {
        Storage::fake('uploads');
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Sayur terlalu lembek (fiktif)']);
        $mutu = PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgA]);
        $fotoKeluhan = $this->lampirkan($keluhan, 'keluhan');
        $fotoMutu = $this->lampirkan($mutu, 'mutu');

        $this->sebagai($this->investorAB, $this->sppgA);

        $this->getJson("/api/v1/keluhan/{$keluhan->id}")->assertOk()
            ->assertJsonPath('data.deskripsi', 'Sayur terlalu lembek (fiktif)')
            ->assertJsonPath('data.lampiran', [])
            ->assertJsonPath('data.lampiran_disembunyikan', true);
        $this->getJson('/api/v1/keluhan')->assertOk()
            ->assertJsonPath('data.0.lampiran', [])
            ->assertJsonPath('data.0.lampiran_disembunyikan', true);
        $this->getJson("/api/v1/mutu/{$mutu->id}")->assertOk()
            ->assertJsonPath('data.lampiran', [])
            ->assertJsonPath('data.lampiran_disembunyikan', true);

        foreach ([$fotoKeluhan, $fotoMutu] as $id) {
            $this->assertAlasan($this->get("/api/v1/lampiran/{$id}", ['Accept' => 'application/json']), 403, LampiranTersembunyi::ALASAN);
        }

        // Peran lain tetap melihat foto.
        $this->sebagai($this->kepalaA);
        $this->getJson("/api/v1/keluhan/{$keluhan->id}")->assertOk()
            ->assertJsonPath('data.lampiran.0.id', $fotoKeluhan)
            ->assertJsonPath('data.lampiran_disembunyikan', false);
        $this->get("/api/v1/lampiran/{$fotoKeluhan}")->assertOk();
        $this->get("/api/v1/lampiran/{$fotoMutu}")->assertOk();
        $this->getJson("/api/v1/mutu/{$mutu->id}")->assertOk()->assertJsonPath('data.lampiran.0.id', $fotoMutu);

        // Investor SPPG lain: 404 (scope), bukan 403 penanda.
        $this->sebagai($this->investorC);
        $this->get("/api/v1/lampiran/{$fotoKeluhan}", ['Accept' => 'application/json'])->assertNotFound();
    }

    // ---------------------------------------------------------------- S-05 Agen AI

    public function test_deskripsi_keluhan_ke_llm_disamarkan_untuk_semua_peran(): void
    {
        FeedbackKeluhan::factory()->create([
            'sppg_id' => $this->sppgC,
            'deskripsi' => 'Ananda Rizky Pratama muntah setelah makan. Hubungi wali 0812-3456-7890 atau wali.fiktif@contoh.id',
            'tindak_lanjut' => 'Sudah dihubungi Bu Sartika Dewi',
        ]);
        $keluhanA = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Siti Aminah alergi telur']);

        // Investor (daftar) dan Kepala SPPG (detail per id, memuat tindak_lanjut).
        foreach ([
            [$this->investorC, ['batas' => 5], 'A. R. P. muntah setelah makan'],
            [$this->kepalaA, ['id' => $keluhanA->id], 'S. A. alergi telur'],
        ] as [$pengguna, $argumen, $tersamar]) {
            $llm = new FakeLlmClient([LlmResponse::toolCall('cari_keluhan', $argumen), LlmResponse::text('Ada keluhan.')]);
            $this->app->instance(LlmClient::class, $llm);
            $this->forgetActor();

            $this->app->make(AiAgentService::class)->handle($pengguna, AiInput::text('ringkas keluhan'));

            $hasilTool = (string) collect($llm->calls[1]['messages'] ?? [])->where('role', 'tool')->last()['content'];
            $this->assertStringContainsString($tersamar, $hasilTool, 'Isi keluhan tetap berguna setelah disamarkan.');

            $semua = json_encode($llm->calls, JSON_UNESCAPED_UNICODE);
            foreach (['Rizky', 'Pratama', 'Ananda', '0812', '3456-7890', 'wali.fiktif@contoh.id', 'Sartika', 'Siti', 'Aminah'] as $pribadi) {
                $this->assertStringNotContainsString($pribadi, $semua, $pribadi);
            }
        }
    }

    public function test_aturan_penyamaran_teks_bebas(): void
    {
        $this->assertSame('A. R. P. muntah setelah makan.', RecordPresenter::redactPersonal('Ananda Rizky Pratama muntah setelah makan.'));
        $this->assertSame('Nasi basi di SDN F. 2, Ibu S. melapor.', RecordPresenter::redactPersonal('Nasi basi di SDN Fiktif 2, Ibu Sartika melapor.'));
        $this->assertSame('telepon [disamarkan], email [disamarkan]', RecordPresenter::redactPersonal('telepon +62 812 3456 7890, email ortu@contoh.id'));
        $this->assertSame('lihat [disamarkan] jam 07.30', RecordPresenter::redactPersonal('lihat https://contoh.id/x?nama=Budi jam 07.30'));
        $this->assertSame('B. tidak mau makan', RecordPresenter::redactPersonal('BUDI tidak mau makan'));
    }

    // ---------------------------------------------------------------- S-07 sapaan bot

    public function test_sapaan_bot_investor_tidak_menyebut_semua_sppg(): void
    {
        $this->fakeTelegram();
        $this->webhook($this->privateUpdate((int) $this->investorAB->telegram_id, ['text' => '/start']))->assertOk();

        $teks = (string) $this->messagesTo((int) $this->investorAB->telegram_id)->sole()['text'];
        $this->assertStringContainsString('SPPG milik Anda (pilih di Mini App)', $teks);
        $this->assertStringNotContainsString('semua SPPG', $teks);

        // Admin tetap "semua SPPG".
        $this->webhook($this->privateUpdate((int) $this->admin->telegram_id, ['text' => '/start']))->assertOk();
        $this->assertStringContainsString('semua SPPG', (string) $this->messagesTo((int) $this->admin->telegram_id)->sole()['text']);
    }
}

<?php

namespace Tests\Feature\Investor;

use App\Models\FeedbackKeluhan;
use App\Models\PemeriksaanMutu;
use App\Models\PenerimaManfaat;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Catalog\KamusNamaOrang;
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

    // ---------------------------------------------------------------- F-04 audit RBAC: nota pengeluaran, no_va, saldo awal

    public function test_foto_nota_pengeluaran_disembunyikan_dari_investor_teks_dan_nominal_tetap_terlihat(): void
    {
        Storage::fake('uploads');
        $pengeluaran = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'uraian' => 'Beli sayur (fiktif)']);
        $foto = $this->lampirkan($pengeluaran, 'keuangan-pengeluaran');

        $this->sebagai($this->investorAB, $this->sppgA);

        $this->getJson("/api/v1/keuangan/pengeluaran/{$pengeluaran->id}")->assertOk()
            ->assertJsonPath('data.uraian', 'Beli sayur (fiktif)')
            ->assertJsonPath('data.lampiran', [])
            ->assertJsonPath('data.lampiran_disembunyikan', true)
            ->assertJsonPath('data.nota', null);
        $this->getJson('/api/v1/keuangan/pengeluaran')->assertOk()
            ->assertJsonPath('data.0.lampiran', [])
            ->assertJsonPath('data.0.lampiran_disembunyikan', true);
        $this->assertAlasan($this->get("/api/v1/lampiran/{$foto}", ['Accept' => 'application/json']), 403, LampiranTersembunyi::ALASAN);

        // Peran keuangan tetap melihat foto nota.
        $this->sebagai($this->akuntanA);
        $this->getJson("/api/v1/keuangan/pengeluaran/{$pengeluaran->id}")->assertOk()
            ->assertJsonPath('data.lampiran.0.id', $foto)
            ->assertJsonPath('data.lampiran_disembunyikan', false);
        $this->get("/api/v1/lampiran/{$foto}")->assertOk();
    }

    public function test_no_va_dan_saldo_awal_sppg_disembunyikan_dari_investor_tetapi_tidak_dari_peran_keuangan(): void
    {
        $this->forgetActor();
        Sppg::query()->whereKey($this->sppgA)->update(['no_va' => '8881234500', 'saldo_awal' => 25_000_000, 'saldo_awal_tanggal' => '2026-11-01']);

        $this->sebagai($this->investorAB, $this->sppgA);
        $json = $this->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk()->assertJsonPath('data.id', $this->sppgA)->json('data');
        foreach (['no_va', 'saldo_awal', 'saldo_awal_tanggal'] as $kolom) {
            $this->assertArrayNotHasKey($kolom, $json, $kolom);
        }
        $this->assertStringNotContainsString('8881234500', json_encode($this->getJson('/api/v1/sppg')->assertOk()->json()));

        foreach ([$this->akuntanA, $this->kepalaA] as $peran) {
            $this->sebagai($peran);
            $this->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk()
                ->assertJsonPath('data.no_va', '8881234500')
                ->assertJsonPath('data.saldo_awal', 25_000_000);
        }
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
        // Issue #175: nama tanpa penanda (sapaan/peran) disamarkan karena terdaftar sebagai penerima manfaat.
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Siti Aminah']);

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
        // Issue #175: nama lembaga/kata kapital biasa ("Fiktif") tidak lagi disingkat; nama setelah sapaan tetap inisial.
        $this->assertSame('Nasi basi di SDN Fiktif 2, Ibu S. melapor.', RecordPresenter::redactPersonal('Nasi basi di SDN Fiktif 2, Ibu Sartika melapor.'));
        $this->assertSame('telepon [disamarkan], email [disamarkan]', RecordPresenter::redactPersonal('telepon +62 812 3456 7890, email ortu@contoh.id'));
        $this->assertSame('lihat [disamarkan] jam 07.30', RecordPresenter::redactPersonal('lihat https://contoh.id/x?nama=Budi jam 07.30'));
        // Nama terdaftar tetap inisial walau ditulis huruf besar semua dan tanpa penanda.
        $this->assertSame('B. tidak mau makan', RecordPresenter::redactPersonal('BUDI tidak mau makan', KamusNamaOrang::dari(['Budi Santoso'])));
    }

    // ---------------------------------------------------------------- S-07 sapaan bot

    public function test_sapaan_bot_investor_tidak_menyebut_semua_sppg(): void
    {
        $this->fakeTelegram();
        // R-02 (A3): ringkasan peran/SPPG di sapaan hanya untuk pengguna dengan sesi login penuh.
        $this->beriSesiPenuh($this->investorAB);
        $this->beriSesiPenuh($this->admin);
        $this->webhook($this->privateUpdate((int) $this->investorAB->telegram_id, ['text' => '/start']))->assertOk();

        $teks = (string) $this->messagesTo((int) $this->investorAB->telegram_id)->sole()['text'];
        // R-02 A2: pemilih SPPG ada di beranda bot, sapaan tidak menyebut daftar/semua SPPG.
        $this->assertStringContainsString('SPPG milik Anda', $teks);
        $this->assertStringNotContainsString('semua SPPG', $teks);

        // Admin tetap "semua SPPG".
        $this->webhook($this->privateUpdate((int) $this->admin->telegram_id, ['text' => '/start']))->assertOk();
        $this->assertStringContainsString('semua SPPG', (string) $this->messagesTo((int) $this->admin->telegram_id)->sole()['text']);
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Enums\HasilAi;
use App\Models\AiDraft;
use App\Models\AiInteraction;
use App\Models\BahanBaku;
use App\Models\Pemasok;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Llm\ToolCall;
use App\Services\Telegram\AiChatHandler;
use App\Services\Telegram\Bot\BotAksiAiService;
use Illuminate\Http\Client\Request;
use Tests\Fakes\FakeVisionLlmClient;
use Tests\Feature\Ai\Eksekusi\AiEksekusiTestCase;

/**
 * Regresi staging (foto nota -> "Saya siapkan draf pembelian bahan baku" + <hasil_tool_xxxx>{JSON} tanpa tombol dan tanpa
 * AiDraft): jawaban model disaring, klaim draf tanpa AiDraft diganti pesan server yang jujur + tombol Mini App, draf
 * diringkas SERVER (tanpa id internal / skor keyakinan) + tombol konfirmasi, satu iterasi koreksi bila model menulis
 * panggilan tool sebagai teks, dan satu baris log 'ai.pesan' per pesan tanpa isi. LLM palsu; data fiktif, tanpa NIK.
 */
class BalasanDrafServerTest extends AiEksekusiTestCase
{
    private const NOTA = "PASAR SEGAR FIKTIF\nTgl 08/11/2026\nAyam Fiktif 10 kg x Rp30.000 = Rp300.000\nTOTAL Rp300.000";

    private BahanBaku $ayam;

    private Pemasok $pasar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ayam = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Ayam Fiktif', 'satuan' => 'kg']);
        $this->pasar = Pemasok::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Pasar Segar Fiktif']);
        $this->forgetActor();
    }

    private function visionLlm(): FakeVisionLlmClient
    {
        config(['ai.tool_selection.mode' => 'on', 'ai.agent.token_per_pesan' => 7000]);
        $llm = new FakeVisionLlmClient;
        $llm->pushVision(LlmResponse::text(self::NOTA));
        $this->app->instance(LlmClient::class, $llm);

        return $llm;
    }

    /** Teks persis seperti gejala staging: klaim + bungkus hasil_tool berisi argumen tool tulis. */
    private function bocor(): string
    {
        return 'Saya siapkan draf pembelian bahan baku <hasil_tool_9f8e7d6c5b4a>{"bahan_baku_id": '.$this->ayam->id
            .', "pemasok_id": '.$this->pasar->id.', "jumlah": 10, "keyakinan": {"bahan_baku_id": 0.98, "pemasok_id": 0.99}}</hasil_tool_9f8e7d6c5b4a>';
    }

    private function langkahCari(): LlmResponse
    {
        return LlmResponse::toolCalls([new ToolCall('c1', 'cari_bahan_baku', ['q' => 'Ayam']), new ToolCall('c2', 'cari_pemasok', ['q' => 'Pasar Segar'])]);
    }

    private function langkahPembelian(): LlmResponse
    {
        return LlmResponse::toolCall('buat_pembelian_bahan', [
            'bahan_baku_id' => $this->ayam->id, 'pemasok_id' => $this->pasar->id, 'tanggal_beli' => now('Asia/Jakarta')->toDateString(),
            'jumlah' => 10, 'harga_satuan' => 30000, 'keyakinan' => ['jumlah' => 0.98, 'harga_satuan' => 0.99],
        ]);
    }

    private function kirimFoto(): void
    {
        $this->forgetActor();
        $this->webhook($this->privateUpdate((int) $this->stafGudangA->telegram_id, [
            'photo' => [['file_id' => 'nota-fiktif', 'file_unique_id' => 'nota-unik-'.uniqid(), 'file_size' => 2000, 'width' => 1280, 'height' => 960]],
        ]))->assertOk();
        $this->forgetActor();
    }

    /** @return list<Request> */
    private function pesanKe(): array
    {
        return $this->messagesTo((int) $this->stafGudangA->telegram_id)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function tombolDari(Request $r): array
    {
        $markup = $this->markupOf($r);

        return $markup === null ? [] : array_merge(...($markup['inline_keyboard'] ?: [[]]));
    }

    private function adaLabel(Request $r, string $awal): bool
    {
        return collect($this->tombolDari($r))->contains(fn ($t) => str_starts_with((string) $t['text'], $awal));
    }

    /** Teks yang terlihat pengguna (semua pesan bot ke chat ini). */
    private function teksTerlihat(): string
    {
        return collect($this->pesanKe())->map(fn (Request $r) => (string) $r['text'])->implode("\n---\n");
    }

    private function assertTanpaJejakInternal(string $teks): void
    {
        foreach (['hasil_tool', '9f8e7d6c5b4a', 'bahan_baku_id', 'pemasok_id', 'keyakinan', '0.98', '0,98', '0.99', '{"'] as $jejak) {
            $this->assertStringNotContainsStringIgnoringCase($jejak, $teks, "Jejak internal '{$jejak}' bocor ke chat.");
        }

        $this->assertDoesNotMatchRegularExpression('/#'.$this->ayam->id.'\b|#'.$this->pasar->id.'\b/', $teks, 'Tanpa id internal.');
    }

    /** @return list<array<string, mixed>> konteks log 'ai.pesan' */
    private function logPesan(): array
    {
        return array_values(array_map(fn ($l) => $l['context'], array_filter($this->log, fn ($l) => $l['message'] === 'ai.pesan')));
    }

    // ------------------------------------------------------------------ gejala staging (foto)

    public function test_foto_model_menulis_tool_sebagai_teks_dua_kali_dibalas_jujur_tanpa_bocor_dengan_tombol_buka(): void
    {
        $llm = $this->visionLlm();
        $llm->push($this->langkahCari())->push(LlmResponse::text($this->bocor()))->push(LlmResponse::text($this->bocor()));

        $this->kirimFoto();

        // Satu iterasi koreksi (maks iterasi foto 3); teks bocor TIDAK dikirim balik ke penyedia.
        $this->assertCount(3, $llm->calls);
        $this->assertStringNotContainsString('Catatan sistem: jawaban sebelumnya', (string) $llm->calls[1]['options']['system']);
        $this->assertStringContainsString('Catatan sistem: jawaban sebelumnya menulis panggilan atau hasil tool sebagai TEKS', (string) $llm->calls[2]['options']['system']);
        $this->assertStringContainsString('buat_pembelian_bahan', (string) $llm->calls[2]['options']['system']);
        $this->assertStringNotContainsString('9f8e7d6c5b4a', json_encode($llm->calls[2]['messages']));

        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
        $pesan = $this->pesanKe();
        $this->assertCount(1, $pesan);
        $this->assertStringEndsWith(AiAgentService::PESAN_TANPA_DRAF, (string) $pesan[0]['text']);
        $this->assertStringNotContainsString('Saya siapkan draf', (string) $pesan[0]['text'], 'Klaim model tidak diteruskan.');
        $this->assertTanpaJejakInternal($this->teksTerlihat());
        $buka = collect($this->tombolDari($pesan[0]))->first(fn ($t) => $t['text'] === 'Buka formulir SiGAP');
        $this->assertNotNull($buka, 'Tombol Buka Mini App selalu ada pada balasan tanpa draf.');
        $this->assertArrayHasKey('web_app', $buka);

        $interaksi = AiInteraction::query()->withoutGlobalScopes()->sole();
        $this->assertSame(HasilAi::Fallback, $interaksi->hasil);
        $this->assertSame('tanpa_draf:klaim_draf', $interaksi->error);
        $this->assertSame(1, $interaksi->argumen['koreksi_tool_teks']);

        $log = $this->logPesan();
        $this->assertCount(1, $log, 'Satu baris log per pesan.');
        $this->assertSame('tanpa_draf', $log[0]['hasil']);
        $this->assertSame('foto', $log[0]['jenis']);
        $this->assertSame(3, $log[0]['iterasi']);
        $this->assertSame('server', $log[0]['balasan']);
        $this->assertArrayHasKey('token', $log[0]);
    }

    public function test_foto_koreksi_berhasil_draf_diringkas_server_dengan_tombol_tanpa_id_dan_keyakinan(): void
    {
        $llm = $this->visionLlm();
        $llm->push($this->langkahCari())->push(LlmResponse::text($this->bocor()))->push($this->langkahPembelian())
            ->push(LlmResponse::text('tidak boleh dipanggil'));

        $this->kirimFoto();

        $this->assertCount(3, $llm->calls);
        $draf = AiDraft::query()->withoutGlobalScopes()->sole();
        $this->assertSame('buat_pembelian_bahan', $draf->tool);

        [$ringkasan, $konfirmasi] = $this->pesanKe();
        $this->assertStringEndsWith(
            "Draf pembelian: Ayam Fiktif 10 kg x Rp30.000 = Rp300.000, pemasok Pasar Segar Fiktif\n".AiAgentService::PESAN_DRAF_FOTO,
            (string) $ringkasan['text'],
        );

        // Layar konfirmasi server: nama dari basis data + total rumus server; nota wajib -> Batal + Ubah di Mini App.
        $layar = (string) $konfirmasi['text'];
        $this->assertStringStartsWith('<b>Konfirmasi: Buat pembelian bahan</b>', $layar);
        $this->assertStringContainsString('- Bahan: Ayam Fiktif', $layar);
        $this->assertStringContainsString('- Pemasok: Pasar Segar Fiktif', $layar);
        $this->assertStringContainsString('- Jumlah: 10 kg', $layar);
        $this->assertStringContainsString('- Harga satuan: Rp30.000', $layar);
        $this->assertStringContainsString('- Total: Rp300.000', $layar);
        $this->assertTrue($this->adaLabel($konfirmasi, BotAksiAiService::LABEL_BATAL));
        $this->assertTrue($this->adaLabel($konfirmasi, BotAksiAiService::LABEL_UBAH));
        $this->assertFalse($this->adaLabel($konfirmasi, BotAksiAiService::LABEL_LANJUT), 'Pembelian butuh nota: hanya di Mini App.');
        $this->assertTanpaJejakInternal($this->teksTerlihat());

        $log = $this->logPesan();
        $this->assertCount(1, $log);
        $this->assertSame('draf_dibuat', $log[0]['hasil']);
        $this->assertSame(1, $log[0]['draf']);
        $this->assertSame(1, $log[0]['koreksi']);
    }

    // ------------------------------------------------------------------ jalur teks

    public function test_teks_draf_dibuat_balasan_dari_server_bukan_teks_model_dan_tombol_lanjutkan_batal(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Makmur Fiktif', 'jenis' => 'petani', 'keyakinan' => ['nama' => 0.97]]))
            ->push(LlmResponse::text('Draf sudah saya siapkan <hasil_tool_aa11>{"status": "draft", "data": {"draf_dibuat": true}}</hasil_tool_aa11> keyakinan 0,97'));

        $this->chat($this->stafGudangA, 'tambah pemasok Tani Makmur Fiktif, petani');

        [$ringkasan, $konfirmasi] = $this->pesanKe();
        $this->assertSame("Draf pemasok baru\n".AiAgentService::PESAN_DRAF, (string) $ringkasan['text']);
        $this->assertStringContainsString('Konfirmasi: Buat pemasok', (string) $konfirmasi['text']);
        $this->assertTrue($this->adaLabel($konfirmasi, BotAksiAiService::LABEL_LANJUT));
        $this->assertTrue($this->adaLabel($konfirmasi, BotAksiAiService::LABEL_BATAL));
        $teks = $this->teksTerlihat();
        $this->assertStringNotContainsString('sudah saya siapkan', $teks);
        $this->assertStringNotContainsString('0,97', $teks);
        $this->assertStringNotContainsString('hasil_tool', $teks);
    }

    public function test_tombol_selalu_ada_bila_draf_ada_walau_layar_konfirmasi_tidak_tersedia(): void
    {
        // Pesan dari chat yang bukan milik pengguna (layar konfirmasi hanya untuk chat pribadi pemilik): tombol Mini App.
        $this->llm->push($this->langkahPembelian())->push(LlmResponse::text('ok'));
        $this->forgetActor();
        $reply = app(AiAgentService::class)->handle($this->stafGudangA, AiInput::text('catat beli ayam 10 kg 30000 dari pasar segar'));
        $this->forgetActor();
        $this->assertCount(1, $reply->drafts);

        app(AiChatHandler::class)->deliver(990001, $reply, null);

        $pesan = $this->messagesTo(990001)->last();
        $this->assertStringStartsWith('Draf pembelian: Ayam Fiktif 10 kg x Rp30.000 = Rp300.000, pemasok Pasar Segar Fiktif', (string) $pesan['text']);
        $this->assertTrue($this->adaLabel($pesan, 'Periksa & Simpan'));
        $this->assertTanpaJejakInternal((string) $pesan['text']);
    }

    public function test_teks_klaim_draf_tanpa_tool_tulis_diganti_pesan_jujur(): void
    {
        // Koreksi dimatikan: klaim langsung diganti; koreksi aktif tetapi model mengulang klaim: sama.
        foreach ([false, true] as $koreksi) {
            config(['ai.agent.koreksi_tool_teks' => $koreksi]);
            $this->llm->push(LlmResponse::text('Draf pemasok Tani Fiktif sudah saya siapkan, silakan cek.'))
                ->push(LlmResponse::text('Baik, draf sudah dibuat.'));
            $sebelum = count($this->llm->calls);

            $this->chat($this->stafGudangA, 'tambah pemasok Tani Fiktif');

            $this->assertCount($sebelum + ($koreksi ? 2 : 1), $this->llm->calls);
            $pesan = $this->messagesTo((int) $this->stafGudangA->telegram_id)->last();
            $this->assertSame(AiAgentService::PESAN_TANPA_DRAF, (string) $pesan['text']);
            $this->assertTrue($this->adaLabel($pesan, 'Buka formulir SiGAP'));
            $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
            $this->llm->calls = [];
        }
    }

    public function test_jawaban_baca_biasa_diteruskan_dan_tag_dibuang(): void
    {
        // Tanpa iterasi koreksi (yang lain menguji koreksi): penyaring saja.
        config(['ai.agent.koreksi_tool_teks' => false]);
        $this->llm->push(LlmResponse::toolCall('cari_bahan_baku', ['q' => 'Ayam']))
            ->push(LlmResponse::text('Stok Ayam Fiktif tersedia. <HASIL_TOOL_bb22>{"status": "ok"}</HASIL_TOOL_bb22>'));

        $this->chat($this->stafGudangA, 'cek stok ayam');

        $pesan = $this->messagesTo((int) $this->stafGudangA->telegram_id)->last();
        $this->assertSame('Stok Ayam Fiktif tersedia.', (string) $pesan['text']);
        $this->assertSame(1, AiInteraction::query()->withoutGlobalScopes()->sole()->argumen['balasan_disaring']);
        $this->assertSame(HasilAi::Sukses, AiInteraction::query()->withoutGlobalScopes()->sole()->hasil);
    }

    // ------------------------------------------------------------------ log satu baris per pesan

    public function test_log_satu_baris_per_pesan_tanpa_isi_pesan(): void
    {
        $agen = fn (string $teks) => app(AiAgentService::class)->handle($this->stafGudangA, AiInput::text($teks));

        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Rahasia Fiktif', 'jenis' => 'petani']))->push(LlmResponse::text('ok'));
        $agen('tambah pemasok Tani Rahasia Fiktif');
        $this->llm->push(LlmResponse::text('Stok Ayam Fiktif aman.'));
        $agen('cek stok ayam rahasia');
        $this->llm->push(LlmException::fromStatus(500));
        $agen('pesan rahasia ketiga');

        $log = $this->logPesan();
        $this->assertCount(3, $log, 'Tepat satu baris per pesan.');
        $this->assertSame(['draf_dibuat', 'tanpa_draf', 'galat'], array_column($log, 'hasil'));
        $this->assertSame([2, 1, 1], array_column($log, 'iterasi'));
        $this->assertSame('teks', $log[0]['jenis']);
        $this->assertSame('server', $log[0]['balasan']);
        $this->assertSame('model', $log[1]['balasan']);
        $this->assertStringStartsWith('llm:', $log[2]['kode']);

        foreach ($log as $baris) {
            $this->assertSame([], array_diff(array_keys($baris), ['interaksi_id', 'jenis', 'hasil', 'iterasi', 'token', 'token_vision', 'draf', 'balasan', 'ditunda', 'koreksi', 'disaring', 'kode']));
            $this->assertIsInt($baris['token']);
            $json = json_encode($baris);
            $this->assertStringNotContainsStringIgnoringCase('rahasia', $json, 'Tanpa isi pesan / nama.');
            $this->assertStringNotContainsString('Ayam', $json);
            $this->assertStringNotContainsString('{"nama"', $json);
        }
    }
}

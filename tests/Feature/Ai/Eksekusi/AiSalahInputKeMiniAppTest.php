<?php

namespace Tests\Feature\Ai\Eksekusi;

use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\BahanBaku;
use App\Models\BotAksi;
use App\Models\Menu;
use App\Models\Pemasok;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\Drafts\GalatDraf;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Llm\ToolCall;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use App\Services\Telegram\AiChatHandler;
use App\Services\Telegram\Bot\BotAksiAiService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PR-F: masukan salah di bot langsung ke Mini App. Server TIDAK bertanya balik lewat LLM: draf dibuat dari bagian yang
 * valid (kolom salah dikosongkan + galat per kolom), loop agen berhenti (LLM dipanggil tepat sekali), lalu SATU pesan
 * server + tombol web_app "Buka di Mini App" (?draft=<uuid>), tanpa Lanjutkan/pilihan. Ambigu tetap tombol ai_pilih.
 * Semua data fiktif.
 */
class AiSalahInputKeMiniAppTest extends AiEksekusiTestCase
{
    private BahanBaku $ayam;

    private BahanBaku $fillet;

    private BahanBaku $beras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ayam = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Ayam', 'satuan' => 'kg']);
        $this->fillet = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Ayam fillet', 'satuan' => 'kg']);
        $this->beras = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Beras', 'satuan' => 'kg']);
        $this->forgetActor();
    }

    /** @param array<string, mixed> $lain */
    private static function menu(array $lain = []): array
    {
        return $lain + ['nama_menu' => 'Nasi Goreng Fiktif', 'kelompok_sasaran' => 'siswa'];
    }

    /** @return list<array<string, mixed>> semua tombol pada pesan-pesan ke chat $user */
    private function semuaTombol(User $user): array
    {
        $tombol = [];

        foreach ($this->messagesTo((int) $user->telegram_id) as $r) {
            foreach ($this->markupOf($r)['inline_keyboard'] ?? [] as $baris) {
                array_push($tombol, ...$baris);
            }
        }

        return $tombol;
    }

    private function assertSatuPesanDenganTombolMiniApp(User $user, AiDraft $draft): void
    {
        $pesan = $this->messagesTo((int) $user->telegram_id);
        $this->assertCount(1, $pesan, 'Tepat satu pesan server');
        $this->assertArrayNotHasKey('parse_mode', $pesan->sole()->data(), 'Teks polos');
        $this->assertStringContainsString('perlu diperbaiki', (string) $pesan->sole()['text']);

        $tombol = $this->semuaTombol($user);
        $this->assertCount(1, $tombol, 'Hanya tombol Mini App (tanpa Lanjutkan, tanpa pilihan, tanpa Batal)');
        $this->assertSame(AiChatHandler::LABEL_PERBAIKI, $tombol[0]['text']);
        $this->assertStringContainsString('draft='.$draft->uuid, $tombol[0]['web_app']['url']);
        $this->assertArrayNotHasKey('callback_data', $tombol[0]);
        $this->assertSame(0, BotAksi::query()->count(), 'Tidak ada token tombol callback yang diterbitkan');
    }

    // ------------------------------------------------------------------ kasus staging Ahli Gizi

    public function test_staging_lemak_ml_protein_ml_bahan_ay_satu_pesan_ke_mini_app_tanpa_bertanya_balik(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_menu', self::menu([
            'lemak_g' => '5 ml', 'protein_g' => '10 ml', 'energi_kkal' => 450,
            'bahan' => [['nama' => 'ay', 'jumlah' => 10, 'satuan' => 'gram']],
        ])))->push(LlmResponse::text('Maksud Anda lemak 5 gram? Bahan ay itu apa?'));

        $this->chat($this->ahliGiziA, 'buat menu nasi goreng siswa, lemak 5 ml, protein 10 ml, energi 450, bahan ay 10 gram');

        $this->assertCount(1, $this->llm->calls, 'Jalur kesalahan: LLM dipanggil tepat sekali');
        $draft = $this->drafTerakhir();
        $this->assertSame(StatusAiDraft::Pending, $draft->status);
        $this->assertSame(450, $draft->payload['energi_kkal'], 'Bagian yang valid tetap diisi');
        $this->assertArrayNotHasKey('lemak_g', $draft->payload);
        $this->assertArrayNotHasKey('protein_g', $draft->payload);
        $galat = GalatDraf::dari($draft);
        $this->assertSame(['Harus berupa angka tanpa satuan.'], $galat['lemak_g']);
        $this->assertArrayHasKey('protein_g', $galat);
        // "ay" cocok Ayam + Ayam fillet: tetap pilihan (tidak ditebak), dipilih di Mini App.
        $this->assertEqualsCanonicalizing([$this->ayam->id, $this->fillet->id], $draft->payload[PilihanReferensiDraf::KUNCI][0]['kandidat']);
        $this->assertSame([['gram_per_porsi' => 10]], $draft->payload['bahan']);

        $this->assertSatuPesanDenganTombolMiniApp($this->ahliGiziA, $draft);
        $teks = (string) $this->messagesTo((int) $this->ahliGiziA->telegram_id)->sole()['text'];
        $this->assertStringContainsString('Lemak (g)', $teks);
        $this->assertStringContainsString('Protein (g)', $teks);
        $this->assertStringNotContainsString('5 ml', $teks, 'Masukan mentah tidak digemakan');
        $this->assertStringNotContainsString('Maksud Anda', $teks);
        $this->assertStringNotContainsString(GalatDraf::PESAN_TERTUNDA, $teks, 'Satu aksi: tidak ada sisa permintaan');
        $this->assertSame(0, Menu::query()->withoutGlobalScope(SppgScope::class)->count());

        // Mini App: status tetap pending, perlu_diperbaiki + errors bertitik + pilihan_referensi format PR #57.
        $res = $this->as($this->ahliGiziA)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertOk();
        $res->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.perlu_diperbaiki', true)
            ->assertJsonPath('data.errors.lemak_g.0', 'Harus berupa angka tanpa satuan.')
            ->assertJsonPath('data.pilihan_referensi.0.kolom', 'bahan')
            ->assertJsonPath('data.pilihan_referensi.0.indeks', 0)
            ->assertJsonPath('data.payload.energi_kkal', 450);
        $this->assertArrayHasKey('bahan.0.bahan_baku_id', $res->json('data.errors'));
        $this->assertArrayNotHasKey(GalatDraf::KUNCI, $res->json('data.payload'));
        $this->assertArrayNotHasKey(PilihanReferensiDraf::KUNCI, $res->json('data.payload'));
        $this->assertArrayNotHasKey('lemak_g', $res->json('data.payload'));
        $this->assertContains('lemak_g', $res->json('data.perlu_dicek'));

        // Pemilik lain / SPPG lain tidak melihat draf.
        $this->as($this->kepalaB)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNotFound();
        $this->as($this->kepalaA)->getJson("/api/v1/ai-drafts/{$draft->uuid}")->assertNotFound();

        // Disimpan dari Mini App setelah diperbaiki (konfirmasi manusia + ai_draft_id).
        $this->as($this->ahliGiziA)->postJson('/api/v1/menu-gizi', self::menu([
            'lemak_g' => 5, 'protein_g' => 10, 'energi_kkal' => 450, 'ai_draft_id' => $draft->uuid,
            'bahan' => [['bahan_baku_id' => $this->ayam->id, 'gram_per_porsi' => 10]],
        ]))->assertCreated();
        $this->assertSame(StatusAiDraft::Confirmed, $draft->fresh()->status);
    }

    // ------------------------------------------------------------------ tiap kategori

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function kategoriProvider(): array
    {
        return [
            'satuan tidak cocok' => ['ahliGiziA', 'buat_menu', self::menu(['bahan' => [['nama' => 'beras', 'jumlah' => 10, 'satuan' => 'ml']]]), 'bahan.0.gram_per_porsi'],
            'satuan tak dikenal' => ['ahliGiziA', 'buat_menu', self::menu(['bahan' => [['nama' => 'beras', 'jumlah' => 2, 'satuan' => 'sdm']]]), 'bahan.0.gram_per_porsi'],
            'referensi tidak ditemukan' => ['ahliGiziA', 'buat_menu', self::menu(['bahan' => [['nama' => 'kentang fiktif', 'jumlah' => 10, 'satuan' => 'gram']]]), 'bahan.0.bahan_baku_id'],
            'tanggal tidak valid' => ['ahliGiziA', 'buat_menu', self::menu(['tanggal_sajian' => '2026-13-45']), 'tanggal_sajian'],
            'angka di luar batas' => ['ahliGiziA', 'buat_menu', self::menu(['energi_kkal' => 999999999]), 'energi_kkal'],
            'kolom wajib kosong' => ['kepalaA', 'buat_titik_layanan', ['nama' => 'Posyandu Fiktif'], 'jenis'],
            'galat skema enum' => ['stafGudangA', 'buat_pemasok', ['nama' => 'Tani Fiktif', 'jenis' => 'pasar'], 'jenis'],
            'galat skema angka bersatuan' => ['ahliGiziA', 'buat_menu', self::menu(['lemak_g' => '5 ml']), 'lemak_g'],
        ];
    }

    /** @param array<string, mixed> $argumen */
    #[DataProvider('kategoriProvider')]
    public function test_tiap_kategori_kesalahan_satu_pesan_dan_tombol_mini_app(string $aktor, string $tool, array $argumen, string $kolom): void
    {
        /** @var User $user */
        $user = $this->{$aktor};
        $this->llm->push(LlmResponse::toolCall($tool, $argumen))->push(LlmResponse::text('Bisa diulangi?'));

        $this->chat($user, 'tolong siapkan draf');

        $this->assertCount(1, $this->llm->calls, 'LLM dipanggil tepat sekali');
        $draft = $this->drafTerakhir();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame($user->sppg_id, $draft->sppg_id);
        $this->assertArrayHasKey($kolom, GalatDraf::dari($draft), json_encode(GalatDraf::dari($draft)));
        $this->assertSatuPesanDenganTombolMiniApp($user, $draft);
        $this->assertStringNotContainsString('Bisa diulangi', $this->teks($user));
    }

    // ------------------------------------------------------------------ multi-aksi terpotong

    public function test_multi_aksi_terpotong_pesan_menyebut_sisa_permintaan_perlu_dikirim_ulang(): void
    {
        $this->llm->push(LlmResponse::toolCalls([
            new ToolCall('m1', 'buat_menu', self::menu(['lemak_g' => '5 ml'])),
            new ToolCall('m2', 'buat_menu', ['nama_menu' => 'Bubur Fiktif', 'kelompok_sasaran' => 'balita']),
        ]))->push(LlmResponse::text('Tidak boleh terpakai.'));

        $this->chat($this->ahliGiziA, 'buat menu nasi goreng lemak 5 ml, lalu buat menu bubur untuk balita');

        $this->assertCount(1, $this->llm->calls);
        $this->assertSame(1, AiDraft::query()->withoutGlobalScopes()->count(), 'Aksi kedua tidak diproses');
        $draft = $this->drafTerakhir();
        $this->assertSatuPesanDenganTombolMiniApp($this->ahliGiziA, $draft);
        $this->assertStringContainsString(GalatDraf::PESAN_TERTUNDA, $this->teks($this->ahliGiziA));
        $this->assertStringContainsString('kirim ulang', $this->teks($this->ahliGiziA));
    }

    // ------------------------------------------------------------------ tidak dapat dijalankan dari chat

    public function test_draf_perlu_diperbaiki_tidak_pernah_dijalankan_dari_chat(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Fiktif', 'jenis' => 'pasar']));
        $this->chat($this->stafGudangA, 'tambah pemasok tani fiktif jenis pasar');
        $draft = $this->drafTerakhir();
        $this->assertTrue(GalatDraf::ada($draft));

        // Token Lanjutkan palsu/lompat langkah: server tetap menolak (hambatan HANYA_MINIAPP), tanpa data tersimpan.
        $this->tekan($this->stafGudangA, $this->tokenAi($this->stafGudangA, $draft, BotAksi::JENIS_AI_LANJUT));

        $this->assertSame(0, Pemasok::query()->withoutGlobalScopes()->count());
        $this->assertSame(StatusAiDraft::Pending, $draft->fresh()->status);
        $this->assertNull(app(BotAksiAiService::class)->layarKonfirmasi($this->stafGudangA, (int) $this->stafGudangA->telegram_id, $draft->fresh()));
        $this->assertNull(app(BotAksiAiService::class)->layarPilihan($this->stafGudangA, (int) $this->stafGudangA->telegram_id, $draft->fresh()));
    }

    // ------------------------------------------------------------------ ambigu tetap ai_pilih

    public function test_ambigu_tanpa_galat_tetap_tombol_ai_pilih_bukan_jalur_kesalahan(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_menu', self::menu(['bahan' => [['nama' => 'ay', 'jumlah' => 10, 'satuan' => 'gram']]])))
            ->push(LlmResponse::text('Silakan pilih bahan lewat tombol.'));

        $this->chat($this->ahliGiziA, 'buat menu nasi goreng siswa, bahan ay 10 gram');

        $draft = $this->drafTerakhir();
        $this->assertFalse(GalatDraf::ada($draft));
        $label = collect($this->tombolLayar($this->ahliGiziA))->pluck('text')->all();
        $this->assertContains('Ayam (kg) #'.$this->ayam->id, $label);
        $this->assertContains('Ayam fillet (kg) #'.$this->fillet->id, $label);
        $this->assertSame(1, BotAksi::query()->where('jenis', BotAksi::JENIS_AI_PILIH)->where('subjek_id', $draft->id)->distinct()->count('subjek_id'));
        $this->assertNotContains(AiChatHandler::LABEL_PERBAIKI, collect($this->semuaTombol($this->ahliGiziA))->pluck('text')->all());
    }

    // ------------------------------------------------------------------ hak peran

    public function test_peran_tanpa_hak_tulis_tanpa_draf_dan_tanpa_tombol(): void
    {
        foreach ([$this->relawanA, $this->admin] as $user) {
            $this->llm->push(LlmResponse::toolCall('buat_menu', self::menu(['lemak_g' => '5 ml'])))
                ->push(LlmResponse::text('Maaf, Anda tidak berhak membuat menu.'));
            $this->chat($user, 'buat menu nasi goreng, lemak 5 ml');

            $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count(), $user->role->value);
            $this->assertNotContains(AiChatHandler::LABEL_PERBAIKI, collect($this->semuaTombol($user))->pluck('text')->all(), $user->role->value);
            $this->assertStringContainsString('tidak berhak', $this->teks($user));
        }
    }

    public function test_admin_tanpa_sppg_terpilih_input_salah_baca_saja_tanpa_draf_dan_tanpa_tombol(): void
    {
        $this->assertNull($this->admin->sppg_id);

        // Tool tulis modul ber-tenant tidak pernah ditawarkan ke Admin tanpa SPPG: LLM menjawab teks, dipanggil SEKALI.
        $this->llm->push(LlmResponse::text('Untuk membuat menu, pilih SPPG di Mini App.'));
        $this->chat($this->admin, 'buat menu nasi goreng, lemak 5 ml, bahan ay');

        $this->assertCount(1, $this->llm->calls);
        $this->assertNotContains('buat_menu', $this->llm->offeredTools(0));
        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
        $this->assertNotContains(AiChatHandler::LABEL_PERBAIKI, collect($this->semuaTombol($this->admin))->pluck('text')->all());

        // LLM tetap memaksa buat_menu dengan argumen salah: ditolak SEBELUM skema/draf (tanpa jalur perbaikan, tanpa
        // draf, tanpa tombol web_app); LLM hanya dipanggil satu kali lagi untuk menyusun penolakan biasa.
        $this->llm->calls = [];
        $this->llm->push(LlmResponse::toolCall('buat_menu', self::menu(['lemak_g' => '5 ml', 'bahan' => [['nama' => 'ay', 'jumlah' => 10, 'satuan' => 'gram']]])))
            ->push(LlmResponse::text('Maaf, Anda tidak berhak membuat menu tanpa SPPG terpilih.'));
        $this->chat($this->admin, 'buat menu nasi goreng, lemak 5 ml, bahan ay');

        $this->assertCount(2, $this->llm->calls);
        $this->assertStringContainsString('"status":"ditolak"', (string) collect($this->llm->calls[1]['messages'])->where('role', 'tool')->last()['content']);
        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, BotAksi::query()->count());
        $this->assertNotContains(AiChatHandler::LABEL_PERBAIKI, collect($this->semuaTombol($this->admin))->pluck('text')->all());
        $this->assertStringNotContainsString('perlu diperbaiki', $this->keluaranTelegram());
    }

    // ------------------------------------------------------------------ Markdown

    public function test_markdown_mentah_jawaban_llm_dibersihkan(): void
    {
        $this->llm->push(LlmResponse::text("## Ringkasan\n**Stok beras** aman, __minyak__ menipis. Gunakan `cari_bahan_baku`.\n```\nkode\n```\n- Item *penting* 2 * 3"));

        $this->chat($this->ahliGiziA, 'ringkas stok');

        $this->assertSame("Ringkasan\nStok beras aman, minyak menipis. Gunakan cari_bahan_baku.\nkode\n- Item penting 2 * 3", $this->teks($this->ahliGiziA));
        $this->assertSame('snake_case_tetap dan 2*3*4', AiAgentService::tanpaMarkdown('snake_case_tetap dan 2*3*4'));
    }
}

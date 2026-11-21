<?php

namespace Tests\Feature\Ai;

use App\Enums\HasilAi;
use App\Enums\JenisInputAi;
use App\Enums\KategoriPengeluaran;
use App\Enums\StatusAiDraft;
use App\Enums\SubKategoriPengeluaran;
use App\Models\AiDraft;
use App\Models\AiInteraction;
use App\Models\FeedbackKeluhan;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiReply;
use App\Services\Ai\HakModulAi;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolSelector;
use Tests\Feature\LaporanBgn\LaporanBgnTestCase;

/**
 * Temuan uji per peran sebelum deploy final (fixture fiktif LaporanBgnTestCase: SPPG A, November 2026).
 *
 * P10: Akuntan (keuangan CRUD) meminta "ubah sub-kategori pengeluaran X menjadi Y" -> DRAF (bukan penolakan "hubungi
 *      Kepala SPPG atau Admin"); Kepala SPPG (keuangan R) ditolak dengan menyebut peran yang berhak (Akuntan).
 * P11: Kepala SPPG (keluhan RU) membaca keluhan lewat agen dengan nomor HP tersamar; peran tanpa modul keluhan mendapat
 *      "Anda tidak punya akses ke data keluhan".
 * P9:  peta hak /auth/me investor multi-SPPG mengikuti SPPG aktif permintaan (header X-SPPG-Id).
 * Pemilihan tool dipaksa aktif (mode on) agar sama dengan staging (Groq, openai_compat).
 */
class UjiPeranAiTest extends LaporanBgnTestCase
{
    private const TULIS = 'ubah_sub_kategori_pengeluaran';

    private Pengeluaran $tokenPln;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.tool_selection.mode' => 'on']);
        $this->tokenPln = $this->keluar('2026-11-04', KategoriPengeluaran::Energi, 250_000, ['uraian' => 'Token PLN dapur November']);
        $this->forgetActor();
    }

    /** @param list<LlmResponse> $langkah */
    private function agen(User $user, string $teks, array $langkah = []): array
    {
        $llm = new FakeLlmClient($langkah);
        $this->app->instance(LlmClient::class, $llm);
        $this->forgetActor();
        $reply = $this->app->make(AiAgentService::class)->handle($user, AiInput::text($teks));

        return [$reply, $llm];
    }

    private static function kontenTool(FakeLlmClient $llm, int $panggilan): string
    {
        return (string) collect($llm->calls[$panggilan]['messages'])->where('role', 'tool')->last()['content'];
    }

    // ------------------------------------------------------------------ P10

    public function test_p10_akuntan_mendapat_draf_sub_kategori_dari_kata_kunci_uraian(): void
    {
        /** @var AiReply $reply */
        [$reply, $llm] = $this->agen($this->akuntanA, 'ubah sub-kategori pengeluaran Token PLN dapur menjadi Listrik', [
            LlmResponse::toolCall(self::TULIS, ['kata_kunci' => 'Token PLN dapur', 'sub_kategori' => 'listrik']),
            LlmResponse::text('Draf siap.'),
        ]);

        // Tool tulis ditawarkan ke Akuntan; prompt tidak lagi mengarahkan penolakan ke "Kepala SPPG/Admin" dan modul
        // keuangan yang sudah ditawarkan tidak disebut sebagai "modul lain".
        $this->assertContains(self::TULIS, $llm->offeredTools(0));
        $system = (string) $llm->calls[0]['options']['system'];
        $this->assertStringNotContainsString('Kepala SPPG/Admin', $system);
        $this->assertStringNotContainsString('Kepala SPPG atau Admin', $system);
        $this->assertStringNotContainsString('Hak terkait pesan ini', $system, 'Akuntan memegang hak ubah keuangan');
        $this->assertDoesNotMatchRegularExpression('/Modul lain peran ini:[^\n]*Data Keuangan/u', $system);
        // Deskripsi ringkas (pemilihan tool aktif) tetap memuat cara menunjuk pengeluaran.
        $skema = collect($llm->calls[0]['tools'])->firstWhere('name', self::TULIS);
        $this->assertStringContainsString('kata_kunci', $skema['description']);
        $this->assertArrayHasKey('kata_kunci', $skema['input_schema']['properties']);

        // Hanya AiDraft: data belum berubah sampai disimpan pengguna.
        $draft = AiDraft::query()->sole();
        $this->assertSame(self::TULIS, $draft->tool);
        $this->assertSame(StatusAiDraft::Pending, $draft->status);
        $this->assertSame($this->tokenPln->id, (int) $draft->target_id);
        $this->assertSame($this->sppgA, (int) $draft->sppg_id);
        $this->assertSame(['sub_kategori' => 'listrik', 'versi' => 1, '_resource' => 'pengeluaran'], (array) $draft->payload);
        $this->assertNull($this->tokenPln->fresh()->sub_kategori);
        $this->assertSame([$draft->id], array_map(fn (AiDraft $d) => $d->id, $reply->drafts));
        $this->assertStringContainsString('Draf sub-kategori pengeluaran 2026-11-04 Rp250.000: Listrik', $reply->text);
        $this->assertStringContainsString(AiAgentService::PESAN_DRAF, $reply->text);

        // Konfirmasi manusia di Mini App (endpoint REST + ai_draft_id) yang menyimpan.
        $this->as($this->akuntanA)->patchJson("/api/v1/keuangan/pengeluaran/{$this->tokenPln->id}", [
            'versi' => 1, 'sub_kategori' => 'listrik', 'ai_draft_id' => $draft->uuid,
        ])->assertOk();
        $this->assertSame(SubKategoriPengeluaran::Listrik, $this->tokenPln->fresh()->sub_kategori);
        $this->assertSame(StatusAiDraft::Confirmed, $draft->fresh()->status);
    }

    public function test_p10_kata_kunci_ambigu_mengembalikan_kandidat_tanpa_uraian_lalu_target_id_menjadi_draf(): void
    {
        $energi = Pengeluaran::query()->withoutGlobalScopes()->where('sppg_id', $this->sppgA)
            ->where('kategori', KategoriPengeluaran::Energi->value)->where('uraian', 'Belanja fiktif energi')->orderBy('id')->get();
        $this->assertCount(2, $energi);
        $belumDiisi = $energi->firstOrFail(fn (Pengeluaran $p) => $p->sub_kategori === null);

        [, $llm] = $this->agen($this->akuntanA, 'ubah sub kategori pengeluaran belanja fiktif energi 200 ribu jadi gas', [
            LlmResponse::toolCall(self::TULIS, ['kata_kunci' => 'belanja fiktif energi', 'sub_kategori' => 'gas']),
            LlmResponse::toolCall(self::TULIS, ['target_id' => $belumDiisi->id, 'sub_kategori' => 'gas']),
            LlmResponse::text('Draf siap.'),
        ]);

        $kandidat = self::kontenTool($llm, 1);
        $this->assertStringContainsString('"status":"invalid"', $kandidat);
        $this->assertStringContainsString('lebih dari satu pengeluaran', $kandidat);
        $this->assertStringContainsString('target_id '.$belumDiisi->id, $kandidat);
        $this->assertStringNotContainsString('Belanja fiktif', $kandidat, 'uraian (teks bebas) tidak dikirim ulang');

        $draft = AiDraft::query()->sole();
        $this->assertSame($belumDiisi->id, (int) $draft->target_id);
        $this->assertSame('gas', $draft->payload['sub_kategori']);
    }

    public function test_p10_kata_kunci_tidak_menjangkau_sppg_lain_atau_bukan_operasional(): void
    {
        $this->keluar('2026-11-04', KategoriPengeluaran::Energi, 99_000, ['uraian' => 'Genset milik SPPG B'], $this->sppgB);
        $this->keluar('2026-11-04', KategoriPengeluaran::BahanBaku, 98_000, ['uraian' => 'Genset beras A']);

        [, $llm] = $this->agen($this->akuntanA, 'ubah sub kategori pengeluaran genset menjadi listrik', [
            LlmResponse::toolCall(self::TULIS, ['kata_kunci' => 'genset', 'sub_kategori' => 'listrik']),
            LlmResponse::text('Tidak ditemukan.'),
        ]);

        $this->assertStringContainsString('tidak ditemukan', self::kontenTool($llm, 1));
        $this->assertSame(0, AiDraft::query()->count());
    }

    public function test_p10_kepala_sppg_ditolak_server_dengan_peran_berhak_akuntan_tanpa_llm(): void
    {
        /** @var AiReply $reply */
        [$reply, $llm] = $this->agen($this->kepalaA, 'ubah sub-kategori pengeluaran Token PLN dapur menjadi Listrik');

        $this->assertCount(0, $llm->calls, 'penolakan disusun server, tanpa panggilan LLM');
        $this->assertSame('Anda tidak berhak mengubah data keuangan. Yang berhak: Akuntan. Data tidak diubah dan tidak ada draf yang dibuat.', $reply->text);
        $this->assertStringNotContainsString('Kepala SPPG', $reply->text);
        $this->assertStringNotContainsString('Admin', $reply->text);
        $this->assertSame([], $reply->drafts);
        $this->assertSame(0, AiDraft::query()->count());
        $interaksi = AiInteraction::query()->sole();
        $this->assertSame(HasilAi::Ditolak, $interaksi->hasil);
        $this->assertSame('tanpa_hak_modul', $interaksi->error);

        // Investor (baca saja) juga diarahkan ke Akuntan.
        $investorA = $this->investor([$this->sppgA]);
        [$reply] = $this->agen($investorA, 'ubah sub kategori pengeluaran Token PLN dapur jadi listrik');
        $this->assertStringContainsString('Yang berhak: Akuntan', $reply->text);
    }

    public function test_p10_pesan_campuran_kepala_ke_agen_dengan_hak_terkait_dan_tool_terlarang_menyebut_akuntan(): void
    {
        [, $llm] = $this->agen($this->kepalaA, 'cek pengeluaran Token PLN dapur lalu ubah sub kategorinya jadi listrik', [
            LlmResponse::toolCall(self::TULIS, ['target_id' => $this->tokenPln->id, 'sub_kategori' => 'listrik']),
            LlmResponse::text('Maaf, perubahan sub-kategori dilakukan Akuntan.'),
        ]);

        $this->assertNotContains(self::TULIS, $llm->offeredTools(0));
        $system = (string) $llm->calls[0]['options']['system'];
        $this->assertStringContainsString('Hak terkait pesan ini: Data Keuangan (Pencairan Dana & Pengeluaran): mengubah hanya oleh Akuntan', $system);
        $this->assertStringNotContainsString('Kepala SPPG/Admin', $system);

        $konten = self::kontenTool($llm, 1);
        $this->assertStringContainsString('"status":"ditolak"', $konten);
        $this->assertStringContainsString('Peran yang berhak: Akuntan.', $konten);
        $this->assertSame(0, AiDraft::query()->count());
        $this->assertNull($this->tokenPln->fresh()->sub_kategori);
    }

    // ------------------------------------------------------------------ P11

    public function test_p11_kepala_sppg_membaca_keluhan_terbaru_dengan_hp_tersamar(): void
    {
        $this->forgetActor();
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Keluhan lama porsi kurang']);
        $baru = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Nasi basi, hubungi wali 0812-3456-7890']);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgB, 'deskripsi' => 'Keluhan rahasia SPPG B']);

        // "terbaru" bukan niat tambah ("baru" hanya kata utuh).
        $this->assertArrayNotHasKey('C', ToolSelector::niat(ToolSelector::haystack('tampilkan keluhan terbaru')));
        $this->assertArrayHasKey('C', ToolSelector::niat(ToolSelector::haystack('catat keluhan baru')));

        /** @var AiReply $reply */
        [$reply, $llm] = $this->agen($this->kepalaA, 'tampilkan keluhan terbaru', [
            LlmResponse::toolCall('cari_keluhan', ['batas' => 5]),
            LlmResponse::text('Ada 2 keluhan terbaru.'),
        ]);

        $this->assertSame(['cari_keluhan'], $llm->offeredTools(0));
        $konten = self::kontenTool($llm, 1);
        $this->assertStringContainsString('"status":"ok"', $konten);
        $this->assertStringContainsString('"id":'.$baru->id, $konten);
        $this->assertLessThan(strpos($konten, 'Keluhan lama'), strpos($konten, '"id":'.$baru->id), 'terbaru lebih dulu');
        $this->assertStringContainsString('Nasi basi', $konten);
        $this->assertStringNotContainsString('0812', $konten, 'nomor HP tersamar (RecordPresenter)');
        $this->assertStringNotContainsString('3456', $konten);
        $this->assertStringNotContainsString('rahasia SPPG B', $konten);
        $this->assertSame('Ada 2 keluhan terbaru.', $reply->text);
        $this->assertSame(0, AiDraft::query()->count());
    }

    public function test_p11_peran_tanpa_modul_keluhan_mendapat_pesan_tanpa_akses_tanpa_llm(): void
    {
        foreach (['on', 'off'] as $mode) {
            config(['ai.tool_selection.mode' => $mode]);

            foreach ([$this->akuntanA, $this->stafGudangA] as $user) {
                AiInteraction::query()->withoutGlobalScopes()->delete();
                [$reply, $llm] = $this->agen($user, 'tampilkan keluhan terbaru');

                $this->assertSame('Anda tidak punya akses ke data keluhan.', $reply->text, $user->role->value." ({$mode})");
                $this->assertCount(0, $llm->calls);
                $this->assertSame(HasilAi::Ditolak, AiInteraction::query()->withoutGlobalScopes()->sole()->hasil);
            }
        }

        // Peran ber-hak baca keluhan tidak ditolak server (relawan CR: data catatannya sendiri; investor R).
        foreach ([$this->relawanA, $this->ahliGiziA] as $user) {
            [$reply, $llm] = $this->agen($user, 'tampilkan keluhan terbaru', [LlmResponse::text('Belum ada keluhan.')]);
            $this->assertCount(1, $llm->calls, $user->role->value);
            $this->assertContains('cari_keluhan', $llm->offeredTools(0));
        }
    }

    public function test_hak_modul_tidak_menolak_pesan_yang_menyentuh_modul_milik_peran(): void
    {
        $hak = app(HakModulAi::class);
        $registry = app(ToolRegistry::class);
        $akuntan = $registry->forUser($this->akuntanA);

        // Akuntan menyebut keluhan sambil mencatat pengeluaran: ada tool milik peran yang cocok -> ke agen.
        $this->assertNull($hak->penolakan($this->akuntanA, 'catat pengeluaran 50000 untuk menangani keluhan nasi basi', JenisInputAi::Teks, $akuntan));
        // Tanpa modul yang disebut jelas -> ke agen.
        $this->assertNull($hak->penolakan($this->akuntanA, 'halo, apa kabar?', JenisInputAi::Teks, $akuntan));
        // Foto tidak pernah ditolak lewat jalur ini.
        $this->assertNull($hak->penolakan($this->akuntanA, 'keluhan', JenisInputAi::Foto, $akuntan));
        // Permintaan ganda (bagian baca yang berhak + bagian tulis tanpa hak) diserahkan ke agen.
        $investorA = $this->investor([$this->sppgA]);
        $this->assertNull($hak->penolakan($investorA, 'ringkas keluhan dan buat keluhan baru', JenisInputAi::Teks, $registry->forUser($investorA)));
        $this->assertNotNull($hak->penolakan($investorA, 'buat keluhan baru nasi basi', JenisInputAi::Teks, $registry->forUser($investorA)));

        $this->assertSame(['Akuntan'], HakModulAi::peranBerhak('keuangan', 'U'));
        $this->assertSame(['Admin', 'Kepala SPPG', 'Ahli Gizi'], HakModulAi::peranBerhak('keluhan', 'U'));
        $this->assertNotContains('Investor', HakModulAi::peranBerhak('keuangan', 'C'));
    }

    // ------------------------------------------------------------------ P9 (server benar; perbaikan di Mini App)

    public function test_p9_peta_hak_laporan_bgn_agregat_investor_multi_sppg_mengikuti_header_sppg_aktif(): void
    {
        $tanpaHeader = $this->sebagai($this->investorAB)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue($tanpaHeader->json('user.multi_sppg'));
        $this->assertNull($tanpaHeader->json('user.sppg_aktif_id'));
        $this->assertFalse($tanpaHeader->json('user.aksi.lihat_laporan_bgn_agregat'), 'belum ada SPPG aktif: gagal tertutup');
        $this->assertAlasan($this->sebagai($this->investorAB)->getJson('/api/v1/laporan-bgn/porsi'), 409, 'sppg_aktif_belum_dipilih');

        foreach ([$this->sppgA, $this->sppgB] as $sppg) {
            $me = $this->sebagai($this->investorAB, $sppg)->getJson('/api/v1/auth/me')->assertOk();
            $this->assertSame($sppg, $me->json('user.sppg_aktif_id'));
            $this->assertTrue($me->json('user.aksi.lihat_laporan_bgn_agregat'));
            $this->assertFalse($me->json('user.aksi.lihat_laporan_bgn'), 'investor tetap tanpa 30b/30d-C');
        }

        $this->assertAlasan($this->sebagai($this->investorAB, $this->sppgC)->getJson('/api/v1/auth/me'), 403, 'sppg_aktif_ditolak');
        $this->assertTrue($this->sebagai($this->investorC)->getJson('/api/v1/auth/me')->json('user.aksi.lihat_laporan_bgn_agregat'), 'satu SPPG: otomatis aktif');
    }
}

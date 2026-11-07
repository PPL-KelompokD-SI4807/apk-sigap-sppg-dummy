<?php

namespace Tests\Feature\Ai\Eksekusi;

use App\Enums\AksiAiDraft;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\BahanBaku;
use App\Models\BotAksi;
use App\Models\Menu;
use App\Models\Pemasok;
use App\Models\Scopes\SppgScope;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Referensi\HasilPilihan;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use App\Services\Telegram\Bot\BotAksiAiService;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\Bot\BotCallbackHandler;

/**
 * Jalur Bot untuk rujukan nama ambigu: layar PILIHAN (tombol ai_pilih per kandidat, satu grup sekali pakai) ->
 * pilihan diverifikasi ulang server (draf milik penekan, kandidat termasuk daftar + milik SPPG, hak saat ini) ->
 * payload draf diperbarui -> layar pratinjau server (nama + satuan bahan) + Periksa & Simpan. Tidak ada data modul
 * yang tersimpan dari pilihan. Semua data fiktif.
 */
class AiPilihanReferensiBotTest extends AiEksekusiTestCase
{
    private BahanBaku $fillet;

    private BahanBaku $kampung;

    private BahanBaku $beras;

    private BahanBaku $ayamB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fillet = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Ayam Fillet', 'satuan' => 'kg']);
        $this->kampung = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Ayam Kampung', 'satuan' => 'ekor']);
        $this->beras = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Beras', 'satuan' => 'kg']);
        $this->ayamB = BahanBaku::factory()->create(['sppg_id' => $this->sppgB, 'nama' => 'Ayam Fillet', 'satuan' => 'kg']);
        $this->forgetActor();
    }

    /** @param list<array<string, mixed>> $bahan */
    private function mintaMenu(array $bahan): AiDraft
    {
        $this->llm->push(LlmResponse::toolCall('buat_menu', ['nama_menu' => 'Nasi Ayam Fiktif', 'kelompok_sasaran' => 'siswa', 'bahan' => $bahan]))
            ->push(LlmResponse::text('Silakan pilih bahan lewat tombol.'));
        $this->chat($this->ahliGiziA, 'buatkan draf menu Nasi Ayam Fiktif untuk siswa, bahan baku ayam 10 gram per porsi');

        return $this->drafTerakhir();
    }

    public function test_ambigu_tampil_tombol_pilihan_lalu_pratinjau_nama_dan_satuan(): void
    {
        $draft = $this->mintaMenu([
            ['nama' => 'beras', 'jumlah' => 80, 'satuan' => 'gram'],
            ['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram'],
        ]);

        $teks = $this->teks($this->ahliGiziA);
        $this->assertStringContainsString('Pilih bahan baku untuk &quot;ayam&quot;', $teks);
        $this->assertStringContainsString('Takaran: 10 gram per porsi.', $teks);
        $this->assertStringContainsString('sistem tidak menebak', $teks);
        $label = collect($this->tombolLayar($this->ahliGiziA))->pluck('text')->all();
        $this->assertContains('Ayam Fillet (kg) #'.$this->fillet->id, $label);
        $this->assertContains('Ayam Kampung (ekor) #'.$this->kampung->id, $label);
        $this->assertNotContains('Ayam Fillet (kg) #'.$this->ayamB->id, $label, 'Bahan SPPG lain tidak ditampilkan');
        $this->assertTrue($this->adaTombol($this->ahliGiziA, BotAksiAiService::LABEL_BATAL));
        $this->assertTrue($this->adaTombol($this->ahliGiziA, BotAksiAiService::LABEL_UBAH));
        $this->assertFalse($this->adaTombol($this->ahliGiziA, BotAksiAiService::LABEL_LANJUT), 'Draf berpilihan tidak dapat dijalankan');

        $this->tekan($this->ahliGiziA, $this->tombol($this->ahliGiziA, 'Ayam Kampung'));

        $draft->refresh();
        $this->assertSame(['bahan_baku_id' => $this->kampung->id, 'gram_per_porsi' => 10], $draft->payload['bahan'][1]);
        $this->assertArrayNotHasKey(PilihanReferensiDraf::KUNCI, $draft->payload);
        $this->assertSame(StatusAiDraft::Pending, $draft->status);
        $this->assertSame('Dipilih.', $this->jawaban());
        $this->assertSame(0, Menu::query()->withoutGlobalScope(SppgScope::class)->count(), 'Memilih tidak menyimpan menu');

        $teks = $this->teks($this->ahliGiziA);
        $this->assertStringContainsString('Pratinjau draf: Buat menu', $teks);
        $this->assertStringContainsString('- Bahan: Beras (kg) - 80 gram per porsi', $teks);
        $this->assertStringContainsString('- Bahan: Ayam Kampung (ekor) - 10 gram per porsi', $teks);
        $this->assertStringContainsString('Pilihan disimpan pada draf.', $teks);
        $periksa = collect($this->tombolLayar($this->ahliGiziA))->first(fn ($t) => ($t['text'] ?? null) === BotAksiAiService::LABEL_PERIKSA);
        $this->assertStringContainsString('draft='.$draft->uuid, $periksa['web_app']['url']);

        // Simpan dari Mini App dengan payload draf (setelah pemilihan) + ai_draft_id.
        $this->as($this->ahliGiziA)->postJson('/api/v1/menu-gizi', [
            'nama_menu' => 'Nasi Ayam Fiktif', 'kelompok_sasaran' => 'siswa', 'bahan' => $draft->payload['bahan'], 'ai_draft_id' => $draft->uuid,
        ])->assertCreated();
        $this->forgetActor();
        $this->assertSame(StatusAiDraft::Confirmed, $draft->fresh()->status);
    }

    public function test_nama_tepat_langsung_pratinjau_tanpa_meminta_id(): void
    {
        $draft = $this->mintaMenu([['nama' => 'ayam fillet', 'jumlah' => 10, 'satuan' => 'gram']]);

        $this->assertSame([['bahan_baku_id' => $this->fillet->id, 'gram_per_porsi' => 10]], $draft->payload['bahan']);
        $teks = $this->teks($this->ahliGiziA);
        $this->assertStringContainsString('- Bahan: Ayam Fillet (kg) - 10 gram per porsi', $teks);
        $this->assertStringNotContainsString('ID', str_replace(['draft', 'Ubah'], '', $teks));
        $this->assertTrue($this->adaTombol($this->ahliGiziA, BotAksiAiService::LABEL_PERIKSA));
    }

    public function test_dua_nama_ambigu_dipilih_berurutan_dan_layar_lama_tidak_berlaku(): void
    {
        BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Beras Putih', 'satuan' => 'kg']);
        $this->beras->update(['nama' => 'Beras Pulen']);
        $draft = $this->mintaMenu([
            ['nama' => 'beras', 'jumlah' => 80, 'satuan' => 'gram'],
            ['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram'],
        ]);

        $this->assertStringContainsString('untuk &quot;beras&quot;', $this->teks($this->ahliGiziA));
        $this->assertStringContainsString('masih ada 1 nama lain', $this->teks($this->ahliGiziA));
        $lainLayarPertama = $this->tombol($this->ahliGiziA, 'Beras Putih');
        $this->tekan($this->ahliGiziA, $this->tombol($this->ahliGiziA, 'Beras Pulen'));

        $this->assertStringContainsString('untuk &quot;ayam&quot;', $this->teks($this->ahliGiziA));
        $this->tekan($this->ahliGiziA, $lainLayarPertama);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawaban(), 'Satu tombol per layar (grup sekali pakai)');
        $this->assertSame($this->beras->id, $draft->fresh()->payload['bahan'][0]['bahan_baku_id']);

        $this->tekan($this->ahliGiziA, $this->tombol($this->ahliGiziA, 'Ayam Fillet'));
        $this->assertSame([$this->beras->id, $this->fillet->id], array_column($draft->fresh()->payload['bahan'], 'bahan_baku_id'));
    }

    /** Token ai_pilih buatan (meniru baris server) dengan subjek_versi = kode bebas. */
    private function tokenPilih($user, AiDraft $draft, int $kode): string
    {
        $svc = app(BotAksiService::class);

        return $svc->callbackData($svc->terbit($user, (int) $user->telegram_id, BotAksi::JENIS_AI_PILIH, [
            'sppg_id' => $this->sppgA, 'subjek_tipe' => BotAksi::SUBJEK_AI_DRAFT, 'subjek_id' => (int) $draft->id, 'subjek_versi' => $kode, 'expires_at' => $draft->expires_at,
        ]));
    }

    public function test_token_pilihan_palsu_posisi_lain_atau_draf_orang_lain_ditolak(): void
    {
        $draft = $this->mintaMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']]);
        $sebelum = $draft->payload;
        $slot = PilihanReferensiDraf::tertunda($draft)[0];

        // ID bahan sebagai kode (format lama), posisi di luar daftar kandidat, indeks slot lain.
        foreach ([$this->ayamB->id * PilihanReferensiDraf::KODE_BASIS, $this->beras->id, 5, PilihanReferensiDraf::KODE_BASIS] as $kode) {
            $this->tekan($this->ahliGiziA, $this->tokenPilih($this->ahliGiziA, $draft, $kode));
            $this->assertStringContainsString('tidak berlaku', $this->teks($this->ahliGiziA), "kode {$kode}");
        }

        // Pengguna lain (kepala SPPG yang sama) memakai kode sah untuk draf ahli gizi.
        $this->tekan($this->kepalaA, $this->tokenPilih($this->kepalaA, $draft, (int) PilihanReferensiDraf::kode($slot, $this->fillet->id)));
        $this->assertStringContainsString('tidak berlaku', $this->teks($this->kepalaA));

        $this->assertSame($sebelum, $draft->fresh()->payload);
    }

    public function test_token_terikat_slot_tidak_berlaku_untuk_slot_berikutnya(): void
    {
        $draft = $this->mintaMenu([
            ['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram'],
            ['nama' => 'ayam', 'jumlah' => 20, 'satuan' => 'gram'],
        ]);
        [$slot1, $slot2] = PilihanReferensiDraf::tertunda($draft);
        $this->assertSame($slot1['kandidat'], $slot2['kandidat'], 'Dua slot dengan kandidat sama');

        // Token sah untuk slot PERTAMA yang tidak ikut terpakai grup layar (mis. layar lama).
        $basi = $this->tokenPilih($this->ahliGiziA, $draft, (int) PilihanReferensiDraf::kode($slot1, $this->kampung->id));
        // Token untuk slot KEDUA ditekan saat slot pertama belum dipilih.
        $this->tekan($this->ahliGiziA, $this->tokenPilih($this->ahliGiziA, $draft, (int) PilihanReferensiDraf::kode($slot2, $this->fillet->id)));
        $this->assertStringContainsString('tidak berlaku', $this->teks($this->ahliGiziA));
        $this->assertCount(2, PilihanReferensiDraf::tertunda($draft->fresh()));

        $this->tekan($this->ahliGiziA, $this->tokenPilih($this->ahliGiziA, $draft, (int) PilihanReferensiDraf::kode($slot1, $this->fillet->id)));
        $this->assertSame($this->fillet->id, $draft->fresh()->payload['bahan'][0]['bahan_baku_id']);

        // Token slot pertama tidak diterapkan ke slot kedua (kandidat sama, posisi sama).
        $this->tekan($this->ahliGiziA, $basi);
        $this->assertStringContainsString('tidak berlaku', $this->teks($this->ahliGiziA));
        $this->assertSame(['gram_per_porsi' => 20], $draft->fresh()->payload['bahan'][1]);
        $this->assertCount(1, PilihanReferensiDraf::tertunda($draft->fresh()));
    }

    public function test_pilih_langsung_untuk_draf_final_atau_kedaluwarsa_tidak_berlaku(): void
    {
        foreach ([StatusAiDraft::Confirmed, StatusAiDraft::Cancelled, StatusAiDraft::Expired, 'lewat_ttl'] as $keadaan) {
            $draft = $this->mintaMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']]);
            $kode = (int) PilihanReferensiDraf::kode(PilihanReferensiDraf::tertunda($draft)[0], $this->fillet->id);
            $draft->forceFill($keadaan instanceof StatusAiDraft ? ['status' => $keadaan] : ['expires_at' => now()->subMinute()])->save();
            $sebelum = $draft->fresh()->payload;

            $this->forgetActor();
            $hasil = app(PilihanReferensiDraf::class)->pilih($this->ahliGiziA, (int) $draft->id, $kode);

            $label = $keadaan instanceof StatusAiDraft ? $keadaan->value : $keadaan;
            $this->assertSame(HasilPilihan::TIDAK_BERLAKU, $hasil->kode, $label);
            $this->assertSame($sebelum, $draft->fresh()->payload, $label);
        }

        // Pembanding: draf terbuka dengan kode yang sama diterima.
        $draft = $this->mintaMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']]);
        $this->forgetActor();
        $hasil = app(PilihanReferensiDraf::class)->pilih($this->ahliGiziA, (int) $draft->id, (int) PilihanReferensiDraf::kode(PilihanReferensiDraf::tertunda($draft)[0], $this->fillet->id));
        $this->assertSame(HasilPilihan::DIPILIH, $hasil->kode);
        $this->assertSame(0, Menu::query()->withoutGlobalScope(SppgScope::class)->count());
    }

    public function test_kandidat_terhapus_atau_satuan_tak_kompatibel_tampil_ulang_dengan_pesan(): void
    {
        $minyak = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Minyak Goreng', 'satuan' => 'liter']);
        $kelapa = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Minyak Kelapa Padat', 'satuan' => 'liter']);
        $draft = $this->mintaMenu([['nama' => 'minyak', 'jumlah' => 5, 'satuan' => 'ml']]);

        // Satuan master berubah SETELAH draf dibuat: dicek ulang saat ditekan.
        $kelapa->update(['satuan' => 'kg']);
        $this->tekan($this->ahliGiziA, $this->tombol($this->ahliGiziA, 'Minyak Kelapa'));
        $teks = $this->teks($this->ahliGiziA);
        $this->assertStringContainsString('tidak tercatat dalam satuan volume', $teks);
        $this->assertStringContainsString('Pilih bahan baku untuk &quot;minyak&quot;', $teks);
        $this->assertArrayHasKey(PilihanReferensiDraf::KUNCI, $draft->fresh()->payload);

        $minyak->delete();
        $this->tekan($this->ahliGiziA, $this->tombol($this->ahliGiziA, 'Minyak Goreng'));
        $this->assertStringContainsString('sudah tidak tersedia', $this->teks($this->ahliGiziA));
        $this->assertFalse($this->adaTombol($this->ahliGiziA, 'Minyak Goreng'), 'Kandidat terhapus tidak ditampilkan lagi');
        $this->assertTrue($this->adaTombol($this->ahliGiziA, 'Minyak Kelapa'));
        $this->assertSame(['gram_per_porsi' => 5], $draft->fresh()->payload['bahan'][0]);
        $this->assertNotNull($kelapa->fresh());
    }

    public function test_hak_dicabut_batal_dan_kedaluwarsa(): void
    {
        $draft = $this->mintaMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']]);
        $fillet = $this->tombol($this->ahliGiziA, 'Ayam Fillet');

        config(['rbac.modules.menu-gizi.ahli_gizi' => 'R']);
        $this->tekan($this->ahliGiziA, $fillet);
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawaban());
        $this->assertArrayHasKey(PilihanReferensiDraf::KUNCI, $draft->fresh()->payload);

        config(['rbac.modules.menu-gizi.ahli_gizi' => 'CRUD']);
        $kedua = $this->mintaMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']]);
        $this->tekan($this->ahliGiziA, $this->tombol($this->ahliGiziA, BotAksiAiService::LABEL_BATAL));
        $this->assertSame(StatusAiDraft::Cancelled, $kedua->fresh()->status);

        $ketiga = $this->mintaMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']]);
        $tombol = $this->tombol($this->ahliGiziA, 'Ayam Fillet');
        $this->travel(31)->minutes();
        $this->tekan($this->ahliGiziA, $tombol);
        $this->assertArrayHasKey(PilihanReferensiDraf::KUNCI, $ketiga->fresh()->payload);
    }

    /** Lapis kedua: draf tool ber-eksekusi yang masih punya pilihan tertunda tidak pernah dijalankan dari chat. */
    public function test_draf_dengan_pilihan_tertunda_tidak_dapat_dijalankan_lewat_lanjutkan(): void
    {
        $paksa = $this->drafPaksa($this->stafGudangA, 'buat_pemasok', 'pemasok', AksiAiDraft::Create, [
            'nama' => 'Tani Fiktif', 'jenis' => 'petani',
            PilihanReferensiDraf::KUNCI => [['jenis' => 'bahan_baku', 'kolom' => 'bahan', 'indeks' => 0, 'kandidat' => [$this->fillet->id]]],
        ]);

        $this->tekan($this->stafGudangA, $this->tokenAi($this->stafGudangA, $paksa, BotAksi::JENIS_AI_LANJUT));

        $this->assertStringContainsString('Mini App', $this->teks($this->stafGudangA));
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
        $this->assertNotSame(StatusAiDraft::Confirmed, $paksa->fresh()->status);
    }
}

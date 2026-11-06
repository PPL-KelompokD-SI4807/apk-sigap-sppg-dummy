<?php

namespace Tests\Feature\Ai\Eksekusi;

use App\Enums\AksiAiDraft;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\FeedbackKeluhan;
use App\Models\Pemasok;
use App\Models\Pengeluaran;
use App\Models\Scopes\SppgScope;
use App\Models\TitikLayanan;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\WriteDraftTool;
use App\Services\Telegram\Bot\BotAksiAiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fondasi eksekusi GENERIK lintas peran: tool tulis modul yang sudah ada (pemasok oleh staf gudang, titik layanan oleh
 * kepala SPPG, keluhan oleh relawan) dijalankan lewat sub-request REST yang sama, dengan hak dari Rbac/Policy peran
 * penekan tombol. Investor tidak pernah mendapat tool tulis. Aksi berberkas hanya "Ubah".
 */
class AiEksekusiLintasPeranTest extends AiEksekusiTestCase
{
    public function test_staf_gudang_buat_pemasok_lanjutkan_tersimpan_lewat_endpoint_modul_dengan_audit_ai(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Makmur Fiktif', 'jenis' => 'petani']))->push(LlmResponse::text('Draf siap.'));
        $this->chat($this->stafGudangA, 'tambah pemasok Tani Makmur Fiktif, petani');
        $draft = $this->drafTerakhir();

        $this->assertSame((int) $this->stafGudangA->sppg_id, (int) $draft->sppg_id);
        $teks = $this->teks($this->stafGudangA);
        $this->assertStringContainsString('Konfirmasi: Buat pemasok', $teks);
        $this->assertStringContainsString('Nama: Tani Makmur Fiktif', $teks);
        $this->assertTrue($this->adaTombol($this->stafGudangA, BotAksiAiService::LABEL_UBAH));
        $ubah = collect($this->tombolLayar($this->stafGudangA))->first(fn ($t) => str_starts_with((string) $t['text'], BotAksiAiService::LABEL_UBAH));
        $this->assertStringContainsString('draft='.$draft->uuid, $ubah['web_app']['url']);
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());

        $this->tekan($this->stafGudangA, $this->tombol($this->stafGudangA, BotAksiAiService::LABEL_LANJUT));

        $pemasok = Pemasok::query()->withoutGlobalScope(SppgScope::class)->sole();
        $this->assertSame('Tani Makmur Fiktif', $pemasok->nama);
        $this->assertSame($this->sppgA, (int) $pemasok->sppg_id);
        $draft->refresh();
        $this->assertSame(StatusAiDraft::Confirmed, $draft->status, 'Dikonfirmasi AiDraftConfirmer di transaksi endpoint modul');
        $this->assertSame($pemasok->id, $draft->hasil['id']);
        $this->assertSame('ai', AuditLog::query()->withoutGlobalScopes()->where('event', 'ai_eksekusi_draf')->sole()->sumber);
        $this->assertNull(AuditLog::currentSource(), 'Sumber audit dipulihkan');
        $this->assertFalse(Auth::guard('sanctum')->hasUser(), 'Guard tidak membawa identitas ke update berikutnya');
    }

    /** Balapan Mini App vs tombol chat: draf hanya dapat dipakai sekali (kunci baris + status). */
    public function test_draf_sudah_disimpan_di_mini_app_tombol_chat_tidak_berlaku(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Fiktif', 'jenis' => 'petani']))->push(LlmResponse::text('ok'));
        $this->chat($this->stafGudangA, 'tambah pemasok Tani Fiktif');
        $draft = $this->drafTerakhir();
        $lanjut = $this->tombol($this->stafGudangA, BotAksiAiService::LABEL_LANJUT);

        $this->as($this->stafGudangA)->postJson('/api/v1/pemasok', ['nama' => 'Tani Fiktif', 'jenis' => 'petani', 'ai_draft_id' => $draft->uuid])->assertCreated();
        $this->forgetActor();

        $this->tekan($this->stafGudangA, $lanjut);
        $this->assertStringContainsString('tidak berlaku', $this->teks($this->stafGudangA));
        $this->assertSame(1, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());

        // Sebaliknya: draf yang sudah dijalankan dari chat tidak dapat disimpan lagi di Mini App.
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Dua', 'jenis' => 'petani']))->push(LlmResponse::text('ok'));
        $this->chat($this->stafGudangA, 'tambah pemasok Tani Dua');
        $kedua = $this->drafTerakhir();
        $this->tekan($this->stafGudangA, $this->tombol($this->stafGudangA, BotAksiAiService::LABEL_LANJUT));
        $this->as($this->stafGudangA)->postJson('/api/v1/pemasok', ['nama' => 'Tani Dua', 'jenis' => 'petani', 'ai_draft_id' => $kedua->uuid])->assertUnprocessable()->assertJsonValidationErrors('ai_draft_id');
        $this->forgetActor();
        $this->assertSame(2, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
    }

    /** Temuan 1: Kepala SPPG dipindah SPPG setelah draf dibuat -> draf SPPG lama tidak dapat dijalankan. */
    public function test_kepala_dipindah_sppg_setelah_draf_lanjutkan_ditolak(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_titik_layanan', ['nama' => 'SDN Pindah Fiktif', 'jenis' => 'sekolah', 'alamat' => 'Jl. Fiktif 1', 'pic_nama' => 'Bu Uji']))->push(LlmResponse::text('ok'));
        $this->chat($this->kepalaA, 'tambah titik layanan SDN Pindah Fiktif');
        $draft = $this->drafTerakhir();
        $this->assertSame($this->sppgA, (int) $draft->sppg_id);
        $lanjut = $this->tombol($this->kepalaA, BotAksiAiService::LABEL_LANJUT);

        // Dipindah tanpa mencabut sesi (mis. diubah langsung di DB): sesi penuh masih ada.
        DB::table('users')->where('id', $this->kepalaA->id)->update(['sppg_id' => $this->sppgB]);
        $this->tekan($this->kepalaA, $lanjut);

        $this->assertStringContainsString('tidak berlaku', $this->teks($this->kepalaA));
        $this->assertSame(0, TitikLayanan::query()->withoutGlobalScope(SppgScope::class)->where('nama', 'SDN Pindah Fiktif')->count());
        $this->assertNotSame(StatusAiDraft::Confirmed, $draft->fresh()->status);
    }

    public function test_hak_staf_gudang_dicabut_setelah_draf_ditolak_tanpa_data(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Fiktif', 'jenis' => 'petani']))->push(LlmResponse::text('ok'));
        $this->chat($this->stafGudangA, 'tambah pemasok Tani Fiktif');
        $lanjut = $this->tombol($this->stafGudangA, BotAksiAiService::LABEL_LANJUT);

        config(['rbac.modules.pemasok.staf_gudang' => 'R']);
        $this->tekan($this->stafGudangA, $lanjut);

        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawaban());
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
        $this->assertSame(StatusAiDraft::Gagal, $this->drafTerakhir()->status);
    }

    public function test_kepala_ubah_satu_langkah_dan_hapus_titik_layanan_dua_langkah(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'SDN Lama Fiktif']);
        $titikB = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB, 'nama' => 'SDN B Fiktif']);

        $this->llm->push(LlmResponse::toolCall('ubah_titik_layanan', ['target_id' => $titik->id, 'nama' => 'SDN Baru Fiktif']))->push(LlmResponse::text('ok'));
        $this->chat($this->kepalaA, 'ubah nama titik layanan SDN Lama jadi SDN Baru');
        $this->assertStringContainsString('Target: <b>SDN Lama Fiktif (#'.$titik->id.')</b>', $this->teks($this->kepalaA));
        $this->tekan($this->kepalaA, $this->tombol($this->kepalaA, BotAksiAiService::LABEL_LANJUT));
        $this->assertSame('SDN Baru Fiktif', $titik->fresh()->nama);

        $this->llm->push(LlmResponse::toolCall('hapus_titik_layanan', ['target_id' => $titik->id]))->push(LlmResponse::text('ok'));
        $this->chat($this->kepalaA, 'hapus titik layanan SDN Baru');
        $this->tekan($this->kepalaA, $this->tombol($this->kepalaA, BotAksiAiService::LABEL_LANJUT));
        $this->assertNotNull(TitikLayanan::query()->withoutGlobalScope(SppgScope::class)->find($titik->id), 'Belum terhapus setelah langkah 1');
        $this->tekan($this->kepalaA, $this->tombol($this->kepalaA, BotAksiAiService::LABEL_YAKIN));
        $this->assertNull(TitikLayanan::query()->withoutGlobalScope(SppgScope::class)->find($titik->id));

        // Lintas SPPG: titik SPPG B tidak dapat dijadikan target (tool menolak; draf paksa ditolak di sub-request).
        $this->llm->push(LlmResponse::toolCall('hapus_titik_layanan', ['target_id' => $titikB->id]))->push(LlmResponse::text('x'));
        $jumlah = AiDraft::query()->withoutGlobalScopes()->count();
        $this->chat($this->kepalaA, 'hapus titik layanan SDN B');
        $this->assertSame($jumlah, AiDraft::query()->withoutGlobalScopes()->count());

        $paksa = $this->drafPaksa($this->kepalaA, 'hapus_titik_layanan', 'titik-layanan', AksiAiDraft::Delete, [], $titikB->id);
        $this->tekan($this->kepalaA, $this->tokenAi($this->kepalaA, $paksa, BotAksi::JENIS_AI_LANJUT));
        $this->tekan($this->kepalaA, $this->tokenAi($this->kepalaA, $paksa->fresh(), BotAksi::JENIS_AI_YAKIN));
        $this->assertNotNull(TitikLayanan::query()->withoutGlobalScope(SppgScope::class)->find($titikB->id));
        $this->assertNotSame(StatusAiDraft::Confirmed, $paksa->fresh()->status);
    }

    public function test_relawan_buat_keluhan_dijalankan_dan_aturan_unit_tetap_berlaku(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_keluhan', ['sumber' => 'guru', 'kategori' => 'rasa', 'deskripsi' => 'Sayur terlalu asin menurut guru kelas']))->push(LlmResponse::text('ok'));
        $this->chat($this->relawanA, 'buat keluhan: sayur terlalu asin');
        $teks = $this->teks($this->relawanA);
        $this->assertStringContainsString('Deskripsi: (tidak ditampilkan di chat', $teks, 'Teks bebas keluhan tidak ditampilkan di ringkasan');
        $this->tekan($this->relawanA, $this->tombol($this->relawanA, BotAksiAiService::LABEL_LANJUT));

        $keluhan = FeedbackKeluhan::query()->withoutGlobalScope(SppgScope::class)->sole();
        $this->assertSame($this->sppgA, (int) $keluhan->sppg_id);

        // Unit relawan: log produksi hanya unit dapur.
        $distribusi = $this->relawanDistribusi();
        $this->assertArrayHasKey('buat_log_produksi', app(ToolRegistry::class)->forUser($this->relawanA));
        $this->assertArrayNotHasKey('buat_log_produksi', app(ToolRegistry::class)->forUser($distribusi));
        $this->assertArrayHasKey('buat_keluhan', app(ToolRegistry::class)->forUser($distribusi));
    }

    public function test_investor_tidak_pernah_mendapat_tool_tulis_dan_dipaksa_ditolak(): void
    {
        $investor = $this->investor();
        $tulis = array_filter(app(ToolRegistry::class)->forUser($investor), fn ($t) => $t->isWrite());
        $this->assertSame([], array_keys($tulis));

        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'X', 'jenis' => 'petani']))->push(LlmResponse::text('x'));
        $this->chat($investor, 'tambah pemasok X');
        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
    }

    /** Daftar tool tulis yang dapat dijalankan dari chat per peran mengikuti Rbac (bukan daftar peran di eksekutor). */
    public function test_ketersediaan_eksekusi_chat_per_peran_mengikuti_rbac(): void
    {
        $dapat = fn ($user) => array_keys(array_filter(app(ToolRegistry::class)->forUser($user), fn ($t) => $t instanceof WriteDraftTool && ($t->eksekusi()?->dapatDijalankan() ?? false)));

        $this->assertEqualsCanonicalizing(['buat_undangan_pengguna', 'cabut_undangan', 'ubah_peran_pengguna', 'nonaktifkan_pengguna', 'aktifkan_pengguna', 'hapus_pengguna'], $dapat($this->admin));
        $this->assertEqualsCanonicalizing(['buat_titik_layanan', 'ubah_titik_layanan', 'hapus_titik_layanan', 'ubah_keluhan', 'ubah_log_produksi', 'hapus_log_produksi'], $dapat($this->kepalaA));
        $this->assertEqualsCanonicalizing(['buat_pemasok', 'ubah_pemasok'], $dapat($this->stafGudangA));
        $this->assertEqualsCanonicalizing(['buat_keluhan', 'buat_log_produksi', 'ubah_log_produksi'], $dapat($this->relawanA));
        $this->assertEqualsCanonicalizing(['ubah_keluhan'], $dapat($this->ahliGiziA));
        $this->assertSame([], $dapat($this->akuntanA), 'Keuangan butuh nota/bukti: hanya Mini App');
    }

    public function test_aksi_berberkas_hanya_ubah_dan_token_paksa_ditolak(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pengeluaran', ['tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Gas elpiji', 'nominal' => 150000]))->push(LlmResponse::text('Draf siap.'));
        $this->chat($this->akuntanA, 'catat pengeluaran gas elpiji 150 ribu');
        $draft = $this->drafTerakhir();

        $this->assertFalse($this->adaTombol($this->akuntanA, BotAksiAiService::LABEL_LANJUT));
        $this->assertTrue($this->adaTombol($this->akuntanA, BotAksiAiService::LABEL_UBAH));
        $this->assertTrue($this->adaTombol($this->akuntanA, BotAksiAiService::LABEL_BATAL));
        $this->assertStringContainsString('perlu diselesaikan di Mini App', $this->teks($this->akuntanA));

        $this->tekan($this->akuntanA, $this->tokenAi($this->akuntanA, $draft, BotAksi::JENIS_AI_LANJUT));
        $this->assertSame(0, Pengeluaran::query()->withoutGlobalScope(SppgScope::class)->count());
        $this->assertSame(StatusAiDraft::Pending, $draft->fresh()->status);
    }

    public function test_isian_berkeyakinan_rendah_tanpa_lanjutkan(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Mak?ur', 'jenis' => 'petani', 'keyakinan' => ['nama' => 0.3]]))->push(LlmResponse::text('ok'));
        $this->chat($this->stafGudangA, 'tambah pemasok tani mak..ur');
        $draft = $this->drafTerakhir();

        $this->assertFalse($this->adaTombol($this->stafGudangA, BotAksiAiService::LABEL_LANJUT));
        $this->assertStringContainsString('kurang jelas', $this->teks($this->stafGudangA));

        $this->tekan($this->stafGudangA, $this->tokenAi($this->stafGudangA, $draft, BotAksi::JENIS_AI_LANJUT));
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
    }
}

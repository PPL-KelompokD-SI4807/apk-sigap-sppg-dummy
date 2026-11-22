<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\Menu;
use App\Models\PersetujuanPengeluaran;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\DB;

/** Keputusan investor lewat Bot (R-02): menu dan pengeluaran, token, sesi, hak, versi, catatan penolakan. */
class BotKeputusanTest extends BotTestCase
{
    private FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeLlmClient([LlmResponse::text('jawaban AI')]);
        $this->app->instance(LlmClient::class, $this->llm);
        $this->sesiPenuh($this->investorAB, $this->investorC, $this->kepalaA, $this->akuntanA, $this->admin);
    }

    private function terbitUntuk(User $user, string $jenis, array $opsi, ?int $chatId = null): string
    {
        $svc = app(BotAksiService::class);

        return $svc->callbackData($svc->terbit($user, $chatId ?? (int) $user->telegram_id, $jenis, $opsi));
    }

    private function opsiKeluar(int $id, int $sppg, int $versi = 1): array
    {
        return ['sppg_id' => $sppg, 'subjek_tipe' => BotAksi::SUBJEK_PENGELUARAN, 'subjek_id' => $id, 'subjek_versi' => $versi];
    }

    private function keputusanApi(int $id, array $body)
    {
        return $this->sebagai($this->investorAB, $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body);
    }

    private function setelahTekan(): void
    {
        $this->forgetActor();
        $this->flushHeaders();
    }

    // ------------------------------------------------------------------ jalur sukses

    public function test_investor_dua_sppg_menyetujui_menu_lewat_pemilih_daftar_tinjau(): void
    {
        $id = $this->pengajuanMenu();
        $inv = $this->investorAB;

        $this->bukaDaftar($inv);
        $this->assertStringContainsString('Pilih SPPG', $this->teksLayar($inv));
        $this->assertTrue($this->adaTombol($inv, 'SPPG A'));
        $this->assertTrue($this->adaTombol($inv, 'SPPG B'));

        $this->tekan($inv, $this->dataTombol($inv, 'SPPG A'));
        $this->assertStringContainsString('Nasi Telur Uji', $this->teksLayar($inv));

        $this->tekan($inv, $this->dataTombol($inv, 'Tinjau menu'));
        $this->assertStringContainsString('Menu menunggu persetujuan', $this->teksLayar($inv));
        $this->assertTrue($this->adaTombol($inv, 'Setujui'));
        $this->assertTrue($this->adaTombol($inv, 'Tolak'));
        $this->assertFalse($this->adaTombol($inv, 'Revisi'));

        $this->tekan($inv, $this->dataTombol($inv, 'Setujui'));

        $this->assertSame('disetujui', $this->menuRaw($id)->status->value);
        $this->assertSame($inv->id, (int) $this->menuRaw($id)->diputuskan_oleh);
        $this->assertStringContainsString('Menu DISETUJUI', $this->teksLayar($inv));
        // Audit keputusan bot mencatat pemutus (ActingUser) dan sumber 'bot'.
        $audit = AuditLog::query()->withoutGlobalScopes()->where('sumber', 'bot')->where('event', 'updated')->get();
        $this->assertNotEmpty($audit);
        foreach ($audit as $baris) {
            $this->assertSame($inv->id, (int) $baris->user_id);
        }
    }

    public function test_investor_menyetujui_pengeluaran_dan_tinjau_tanpa_uraian_atau_nota(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;

        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $layar = $this->teksLayar($inv);
        $this->assertStringContainsString('Pengeluaran menunggu persetujuan', $layar);
        $this->assertStringContainsString('750.000', $layar);
        $this->assertStringNotContainsString('rahasia-uraian', $layar);
        $this->assertStringNotContainsString('Gas elpiji', $layar);

        $this->tekan($inv, $this->dataTombol($inv, 'Setujui'));

        $this->assertSame('disetujui', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame('disetujui', PersetujuanPengeluaran::query()->withoutGlobalScopes()->where('pengeluaran_id', $id)->value('status')->value);
        $this->assertStringContainsString('Pengeluaran DISETUJUI', $this->teksLayar($inv));
    }

    public function test_investor_satu_sppg_melewati_pemilih(): void
    {
        $satu = $this->investor([$this->sppgA]);
        $this->sesiPenuh($satu);
        $this->pengeluaranMenunggu();

        $this->bukaDaftar($satu);

        $this->assertStringNotContainsString('Pilih SPPG', $this->teksLayar($satu));
        $this->assertTrue($this->adaTombol($satu, 'Tinjau pengeluaran'));
    }

    public function test_investor_tanpa_sppg_dan_daftar_kosong_dan_batas_lima(): void
    {
        $kosong = $this->investor([]);
        $this->sesiPenuh($kosong);
        $this->ketik($kosong, '/menu');
        $this->tekan($kosong, $this->dataTombol($kosong, 'Menunggu keputusan'));
        $this->assertStringContainsString('belum terhubung', $this->teksLayar($kosong));

        $this->bukaDaftar($this->investorC);
        $this->assertStringContainsString('Tidak ada pengajuan', $this->teksLayar($this->investorC));

        for ($i = 0; $i < 6; $i++) {
            $this->pengeluaranMenunggu(100_000 + $i);
        }

        $this->bukaTinjau($this->investorAB, 'Tinjau pengeluaran');
        $this->bukaDaftar($this->investorAB);
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'SPPG A'));
        $tinjau = array_filter($this->tombolLayar((int) $this->investorAB->telegram_id), fn ($t) => str_starts_with($t['text'], 'Tinjau pengeluaran'));
        $this->assertCount(5, $tinjau);
        $this->assertStringContainsString('dan 1 lainnya', $this->teksLayar($this->investorAB));
    }

    // ------------------------------------------------------------------ tolak + catatan

    private function sampaiMintaCatatan(User $inv, string $awalTinjau): void
    {
        $this->bukaTinjau($inv, $awalTinjau);
        $this->tekan($inv, $this->dataTombol($inv, 'Tolak'));
    }

    public function test_tolak_pengeluaran_wajib_catatan_lewat_forcereply_dan_tidak_masuk_ai(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $uid = (int) $inv->telegram_id;

        $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');

        $prompt = $this->telegramCalls('sendMessage')->first(fn ($r) => str_contains((string) $r['reply_markup'], 'force_reply'));
        $this->assertNotNull($prompt);
        $this->assertStringContainsString('BALASAN', $prompt['text']);
        $this->assertCount(1, $this->telegramCalls('editMessageReplyMarkup'), 'Tombol layar tinjau dihapus saat Tolak');
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value, 'Tolak tanpa catatan tidak mengubah data');
        $aksi = BotAksi::query()->where('jenis', 'tolak')->sole();
        $this->assertSame('menunggu_catatan', $aksi->status);
        $this->assertNotNull($aksi->prompt_message_id);

        // Terlalu pendek: diminta ulang, jendela tidak habis, data tidak berubah.
        $this->ketik($inv, 'abc', $aksi->prompt_message_id);
        $this->assertStringContainsString('5-500', (string) $this->layar($uid)['text']);
        $this->assertSame('menunggu_catatan', $aksi->fresh()->status);
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);

        // Terlalu panjang untuk pengeluaran (501).
        $this->ketik($inv, str_repeat('x', 501), $aksi->prompt_message_id);
        $this->assertSame('menunggu_catatan', $aksi->fresh()->status);

        $this->ketik($inv, 'Nota pembelian belum dilampirkan', $aksi->prompt_message_id);

        $this->assertSame('ditolak', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame('Nota pembelian belum dilampirkan', PersetujuanPengeluaran::query()->withoutGlobalScopes()->where('pengeluaran_id', $id)->value('catatan_keputusan'));
        $this->assertSame('selesai', $aksi->fresh()->status);
        $this->assertSame([], $this->llm->calls, 'Catatan penolakan tidak boleh sampai ke Agen AI');
        $this->assertStringContainsString('Pengeluaran DITOLAK', $this->teksLayar($inv));
    }

    public function test_tolak_menu_catatan_hingga_1000_karakter(): void
    {
        $id = $this->pengajuanMenu();
        $inv = $this->investorAB;

        $this->sampaiMintaCatatan($inv, 'Tinjau menu');
        $aksi = BotAksi::query()->where('jenis', 'tolak')->sole();

        $this->ketik($inv, str_repeat('a', 1001), $aksi->prompt_message_id);
        $this->assertSame('diajukan', $this->menuRaw($id)->status->value);

        $this->ketik($inv, str_repeat('a', 1000), $aksi->prompt_message_id);
        $this->assertSame('ditolak', $this->menuRaw($id)->status->value);
        $this->assertSame(1000, mb_strlen((string) $this->menuRaw($id)->catatan_keputusan));
        $this->assertSame('ditolak', Menu::query()->withoutGlobalScopes()->findOrFail($this->menuRaw($id)->menu_id)->status_persetujuan->value);
        $this->assertSame([], $this->llm->calls);
    }

    public function test_teks_bukan_balasan_atau_balasan_ke_pesan_lain_tetap_ke_agen_ai(): void
    {
        $id = $this->pengeluaranMenunggu();
        // Investor satu SPPG agar Agen AI memiliki SPPG aktif (investor dua SPPG ditolak AI sebelum LLM).
        $inv = $this->investor([$this->sppgA]);
        $this->sesiPenuh($inv);
        $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');
        $aksi = BotAksi::query()->where('jenis', 'tolak')->sole();

        $this->ketik($inv, 'Nota belum lengkap bukan balasan');
        $this->assertCount(1, $this->llm->calls);
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);

        $this->ketik($inv, 'Balasan ke pesan lain saja', $aksi->prompt_message_id + 500);
        $this->assertCount(2, $this->llm->calls);
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame('menunggu_catatan', $aksi->fresh()->status);
    }

    public function test_batal_dan_jendela_catatan_habis_tidak_mengubah_data(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');
        $aksi = BotAksi::query()->where('jenis', 'tolak')->sole();

        $this->ketik($inv, '/batal');
        $this->assertSame('kedaluwarsa', $aksi->fresh()->status);
        $this->ketik($inv, 'Alasan setelah batal', $aksi->prompt_message_id);
        $this->assertStringContainsString('sudah habis', $this->teksLayar($inv));
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);

        // Jendela habis karena waktu.
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $this->tekan($inv, $this->dataTombol($inv, 'Tolak'));
        $baru = BotAksi::query()->where('jenis', 'tolak')->where('status', 'menunggu_catatan')->sole();
        BotAksi::query()->whereKey($baru->id)->update(['catatan_expires_at' => now()->subSecond()]);
        $this->ketik($inv, 'Alasan terlambat sekali', $baru->prompt_message_id);

        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame([], $this->llm->calls);
    }

    public function test_hanya_satu_jendela_catatan_per_pengguna(): void
    {
        $this->pengeluaranMenunggu();
        $this->pengajuanMenu();
        $inv = $this->investorAB;

        $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');
        $pertama = BotAksi::query()->where('jenis', 'tolak')->where('status', 'menunggu_catatan')->sole();

        $this->sampaiMintaCatatan($inv, 'Tinjau menu');

        $this->assertSame('kedaluwarsa', $pertama->fresh()->status);
        $this->assertSame(1, BotAksi::query()->where('status', 'menunggu_catatan')->count());
    }

    // ------------------------------------------------------------------ tanpa sesi penuh

    public function test_callback_tanpa_sesi_penuh_hanya_petunjuk_masuk_dan_token_tidak_dihabiskan(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju = $this->dataTombol($inv, 'Setujui');

        app(SesiService::class)->keluarSemua($inv);
        $this->tekan($inv, $setuju);

        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->layar((int) $inv->telegram_id)['text']);
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame(1, BotAksi::query()->where('jenis', 'setuju')->where('status', 'aktif')->count());
        $this->assertStringNotContainsString('750.000', (string) $this->layar((int) $inv->telegram_id)['text']);
    }

    public function test_sesi_dicabut_saat_menunggu_catatan_menutup_jendela_dan_tidak_mengubah_data(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');
        $aksi = BotAksi::query()->where('jenis', 'tolak')->sole();

        app(SesiService::class)->keluarSemua($inv);
        $this->ketik($inv, 'Alasan yang sah dan panjang', $aksi->prompt_message_id);

        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->layar((int) $inv->telegram_id)['text']);
        $this->assertSame('kedaluwarsa', $aksi->fresh()->status);
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame([], $this->llm->calls);
    }

    public function test_balasan_catatan_tanpa_sesi_sejak_awal_tidak_ke_ai(): void
    {
        $this->pengeluaranMenunggu();
        $tanpa = $this->userOf(Role::Investor, $this->sppgA);
        $this->ketik($tanpa, 'balasan apa saja', 1);

        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->layar((int) $tanpa->telegram_id)['text']);
        $this->assertSame([], $this->llm->calls);
    }

    // ------------------------------------------------------------------ hak

    public function test_peran_selain_investor_ditolak_dan_data_serta_token_tidak_berubah(): void
    {
        $id = $this->pengeluaranMenunggu();

        foreach ([$this->kepalaA, $this->akuntanA, $this->admin] as $user) {
            $data = $this->terbitUntuk($user, BotAksi::JENIS_SETUJU, $this->opsiKeluar($id, $this->sppgA));
            $this->tekan($user, $data);

            $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir(), $user->role->value);
            $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
        }

        $this->assertSame(3, BotAksi::query()->where('status', 'aktif')->count());
    }

    public function test_investor_sppg_lain_atau_sppg_token_tidak_cocok_ditolak(): void
    {
        $id = $this->pengeluaranMenunggu();

        // Investor C bukan pemilik SPPG A.
        $this->tekan($this->investorC, $this->terbitUntuk($this->investorC, BotAksi::JENIS_SETUJU, $this->opsiKeluar($id, $this->sppgA)));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());

        // Investor C dengan SPPG-nya sendiri tetapi subjek milik SPPG A.
        $this->tekan($this->investorC, $this->terbitUntuk($this->investorC, BotAksi::JENIS_SETUJU, $this->opsiKeluar($id, $this->sppgC)));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());

        // Investor AB memiliki A dan B, tetapi token menyebut B untuk subjek di A.
        $this->tekan($this->investorAB, $this->terbitUntuk($this->investorAB, BotAksi::JENIS_SETUJU, $this->opsiKeluar($id, $this->sppgB)));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());

        // SPPG yang tidak dimiliki sama sekali.
        $this->tekan($this->investorAB, $this->terbitUntuk($this->investorAB, BotAksi::JENIS_DAFTAR, ['sppg_id' => $this->sppgC]));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());

        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
    }

    public function test_pivot_dicabut_setelah_layar_tampil_menolak_keputusan(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju = $this->dataTombol($inv, 'Setujui');

        DB::table('investor_sppg')->where('user_id', $inv->id)->where('sppg_id', $this->sppgA)->delete();
        $this->tekan($inv, $setuju);

        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
    }

    public function test_pengguna_dinonaktifkan_setelah_layar_tampil_ditolak(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju = $this->dataTombol($inv, 'Setujui');

        User::query()->withoutGlobalScopes()->whereKey($inv->id)->update(['is_active' => false]);
        $this->tekan($inv, $setuju);

        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
    }

    // ------------------------------------------------------------------ token

    public function test_token_dipalsukan_dan_format_salah(): void
    {
        $id = $this->pengeluaranMenunggu();

        foreach (['b1.AAAAAAAAAAA.AAAAAAAA', 'sembarang', str_repeat('z', 80), 'b1..'] as $data) {
            $this->tekan($this->investorAB, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        }

        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
    }

    public function test_token_kedaluwarsa_dan_dipakai_ulang(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju = $this->dataTombol($inv, 'Setujui');

        // Kedaluwarsa karena waktu.
        BotAksi::query()->where('jenis', 'setuju')->update(['expires_at' => now()->subSecond()]);
        $this->tekan($inv, $setuju);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);

        // Dipakai ulang: tinjau baru, setujui dua kali.
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju2 = $this->dataTombol($inv, 'Setujui');
        $tolak2 = $this->dataTombol($inv, 'Tolak');
        $this->tekan($inv, $setuju2);
        $this->assertSame('disetujui', $this->keluarRaw($id)->status_persetujuan->value);
        $this->tekan($inv, $setuju2);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame(1, PersetujuanPengeluaran::query()->withoutGlobalScopes()->where('pengeluaran_id', $id)->where('status', 'disetujui')->count());

        // Token saudara (Tolak pada layar yang sama) sudah kedaluwarsa.
        $this->tekan($inv, $tolak2);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame(0, BotAksi::query()->where('status', 'menunggu_catatan')->count());
    }

    public function test_token_pengguna_lain_chat_lain_user_id_lain_dan_grup_ditolak(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju = $this->dataTombol($inv, 'Setujui');

        // Dari pengguna lain (MAC terikat telegram_id).
        $this->tekan($this->investorC, $setuju);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());

        // Dari chat lain / grup.
        $this->tekan($inv, $setuju, chatId: 424242);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->tekan($inv, $setuju, chatId: -100123, chatType: 'supergroup');
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);

        // Baris dimanipulasi: user_id lain.
        BotAksi::query()->where('jenis', 'setuju')->update(['user_id' => $this->investorC->id]);
        $this->tekan($inv, $setuju);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
    }

    public function test_rate_limit_callback(): void
    {
        config(['telegram.callback_rate' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->tekan($this->investorAB, 'b1.AAAAAAAAAAA.AAAAAAAA');
        }

        $this->tekan($this->investorAB, 'b1.AAAAAAAAAAA.AAAAAAAA');
        $this->assertStringContainsString('Terlalu cepat', (string) $this->jawabanTerakhir());
    }

    // ------------------------------------------------------------------ versi / 409

    public function test_versi_pengeluaran_usang_409_dengan_muat_ulang_dan_data_tidak_berubah(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju = $this->dataTombol($inv, 'Setujui');

        DB::table('pengeluarans')->where('id', $id)->increment('versi');
        $this->tekan($inv, $setuju);

        $this->assertStringContainsString('berubah', $this->teksLayar($inv));
        $this->assertTrue($this->adaTombol($inv, 'Muat ulang'));
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);

        // Muat ulang menampilkan daftar terbaru.
        $this->tekan($inv, $this->dataTombol($inv, 'Muat ulang'));
        $this->assertStringContainsString('Menunggu keputusan', $this->teksLayar($inv));
    }

    public function test_versi_menu_usang_409(): void
    {
        $id = $this->pengajuanMenu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau menu');
        $setuju = $this->dataTombol($inv, 'Setujui');

        Menu::query()->withoutGlobalScopes()->whereKey($this->menuRaw($id)->menu_id)->update(['versi' => 5]);
        $this->tekan($inv, $setuju);

        $this->assertTrue($this->adaTombol($inv, 'Muat ulang'));
        $this->assertSame('diajukan', $this->menuRaw($id)->status->value);
    }

    public function test_daftar_usang_saat_tinjau_dan_sudah_diputuskan_409(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaDaftar($inv);
        $this->tekan($inv, $this->dataTombol($inv, 'SPPG A'));
        $tinjau = $this->dataTombol($inv, 'Tinjau pengeluaran');

        DB::table('pengeluarans')->where('id', $id)->increment('versi');
        $this->tekan($inv, $tinjau);
        $this->assertStringContainsString('Data berubah', $this->teksLayar($inv));
        $this->assertFalse($this->adaTombol($inv, 'Setujui'));
    }

    public function test_sudah_diputuskan_di_tempat_lain_409(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $tolakLayar = $this->dataTombol($inv, 'Setujui');

        $this->keputusanApi($id, ['keputusan' => 'ditolak', 'catatan' => 'Ditolak lewat Mini App', 'versi' => 1])->assertOk();
        $this->setelahTekan();
        $this->tekan($inv, $tolakLayar);

        $this->assertTrue($this->adaTombol($inv, 'Muat ulang'));
        $this->assertSame('ditolak', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame(1, PersetujuanPengeluaran::query()->withoutGlobalScopes()->where('pengeluaran_id', $id)->whereIn('status', ['disetujui', 'ditolak'])->count());
    }

    // ------------------------------------------------------------------ privasi

    public function test_log_audit_dan_basis_data_bot_tanpa_catatan_atau_data_pribadi(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');
        $aksi = BotAksi::query()->where('jenis', 'tolak')->sole();
        $rahasia = 'catatan-rahasia-uji-zxq';

        $this->ketik($inv, $rahasia, $aksi->prompt_message_id);
        $this->assertSame('ditolak', $this->keluarRaw($id)->status_persetujuan->value);

        $log = json_encode($this->logBot);
        $this->assertStringContainsString('bot.aksi', $log);
        foreach ([$rahasia, 'rahasia-uraian', (string) $inv->name, (string) $inv->telegram_id, (string) $inv->phone] as $nilai) {
            if ($nilai !== '') {
                $this->assertStringNotContainsString($nilai, $log);
            }
        }

        $this->assertStringNotContainsString($rahasia, json_encode(BotAksi::query()->get()->toArray()));
        $this->assertStringNotContainsString($rahasia, json_encode(TelegramUpdate::query()->get()->toArray()));
        $this->assertStringNotContainsString($rahasia, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString($rahasia, json_encode($this->llm->calls));
    }
}

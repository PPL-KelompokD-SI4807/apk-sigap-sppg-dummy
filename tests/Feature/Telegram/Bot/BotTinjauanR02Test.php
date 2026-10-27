<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Keuangan\BudgetService;
use App\Services\Persetujuan\PersetujuanPengeluaranService;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\Bot\BotKeputusanService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Perbaikan temuan tinjauan keamanan R-02 (opus): audit pemutus, balasan non-teks, konsumsi grup, pivot segar, privasi, rate limit. */
class BotTinjauanR02Test extends BotTestCase
{
    private FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeLlmClient([LlmResponse::text('jawaban AI')]);
        $this->app->instance(LlmClient::class, $this->llm);
        $this->sesiPenuh($this->investorAB, $this->investorC);
    }

    private function sampaiMintaCatatan(User $inv, string $awalTinjau): BotAksi
    {
        $this->bukaTinjau($inv, $awalTinjau);
        $this->tekan($inv, $this->dataTombol($inv, 'Tolak'));

        return BotAksi::query()->where('jenis', 'tolak')->where('status', 'menunggu_catatan')->sole();
    }

    // ------------------------------------------------------------------ 1. audit pemutus

    public function test_audit_keputusan_menu_dan_pengeluaran_lewat_bot_mencatat_user_id_pemutus(): void
    {
        $this->pengeluaranMenunggu();
        $this->pengajuanMenu();
        $inv = $this->investorAB;

        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $this->tekan($inv, $this->dataTombol($inv, 'Setujui'));
        $this->bukaTinjau($inv, 'Tinjau menu');
        $this->tekan($inv, $this->dataTombol($inv, 'Setujui'));

        $audit = AuditLog::query()->withoutGlobalScopes()->where('sumber', 'bot')->get();
        $this->assertNotEmpty($audit->where('auditable_type', Pengeluaran::class));
        $this->assertNotEmpty($audit->where('auditable_type', PengajuanMenu::class));
        foreach ($audit as $baris) {
            $this->assertSame($inv->id, (int) $baris->user_id, $baris->auditable_type);
        }
    }

    // ------------------------------------------------------------------ 2. balasan non-teks

    public function test_foto_dan_suara_sebagai_balasan_prompt_ditolak_jendela_terbuka_dan_tidak_ke_ai(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $uid = (int) $inv->telegram_id;
        $aksi = $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');
        $balas = ['message_id' => $aksi->prompt_message_id, 'chat' => ['id' => $uid, 'type' => 'private'], 'date' => time()];

        $this->kirim($this->privateUpdate($uid, ['photo' => [['file_id' => 'f1', 'file_size' => 10]], 'caption' => 'alasan di foto', 'reply_to_message' => $balas]));
        $this->assertStringContainsString('Kirim alasan penolakan sebagai teks', (string) $this->layar($uid)['text']);

        $this->kirim($this->privateUpdate($uid, ['voice' => ['file_id' => 'v1', 'duration' => 3], 'reply_to_message' => $balas]));
        $this->assertStringContainsString('Kirim alasan penolakan sebagai teks', (string) $this->layar($uid)['text']);

        $this->kirim($this->privateUpdate($uid, ['sticker' => ['file_id' => 's1'], 'reply_to_message' => $balas]));

        $this->assertSame('menunggu_catatan', $aksi->fresh()->status, 'Jendela tetap terbuka');
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
        $this->assertSame([], $this->llm->calls);
        $this->assertCount(0, $this->telegramCalls('getFile'), 'Foto/suara tidak diunduh');
    }

    // ------------------------------------------------------------------ 3. konsumsi grup atomik

    public function test_konsumsi_grup_hanya_satu_token_per_layar_walau_instance_basi(): void
    {
        $svc = app(BotAksiService::class);
        $uid = (int) $this->investorAB->telegram_id;
        $grup = BotAksiService::grupBaru();
        $setuju = $svc->terbit($this->investorAB, $uid, BotAksi::JENIS_SETUJU, [], $grup);
        $tolak = $svc->terbit($this->investorAB, $uid, BotAksi::JENIS_TOLAK, [], $grup);
        $setujuBasi = BotAksi::query()->findOrFail($setuju->id);
        $tolakBasi = BotAksi::query()->findOrFail($tolak->id);

        $this->assertTrue($svc->konsumsi($setujuBasi));
        // Instance basi masih berstatus aktif di memori, tetapi basis data sudah berubah.
        $this->assertSame('aktif', $tolakBasi->status);
        $this->assertFalse($svc->konsumsi($tolakBasi, BotAksi::STATUS_MENUNGGU_CATATAN));
        $this->assertSame('kedaluwarsa', $tolak->fresh()->status);
        $this->assertSame(0, $this->jumlahAksi('menunggu_catatan'));
    }

    public function test_konsumsi_ditolak_bila_saudara_sudah_menunggu_catatan_walau_masih_aktif(): void
    {
        $svc = app(BotAksiService::class);
        $uid = (int) $this->investorAB->telegram_id;
        $grup = BotAksiService::grupBaru();
        $setuju = $svc->terbit($this->investorAB, $uid, BotAksi::JENIS_SETUJU, [], $grup);
        $tolak = $svc->terbit($this->investorAB, $uid, BotAksi::JENIS_TOLAK, [], $grup);

        // Keadaan perlombaan: saudara sudah di status terpakai tetapi token ini belum sempat dikedaluwarsakan.
        BotAksi::query()->whereKey($tolak->id)->update(['status' => 'menunggu_catatan', 'catatan_expires_at' => now()->addMinutes(5)]);

        $this->assertFalse($svc->konsumsi($setuju->fresh()));
        $this->assertSame('aktif', $setuju->fresh()->status);
    }

    // ------------------------------------------------------------------ 4. pivot segar

    public function test_pivot_dicabut_antara_periksa_dan_service_ditolak_tanpa_mengubah_data(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $this->bukaTinjau($inv, 'Tinjau pengeluaran');
        $setuju = BotAksi::query()->where('jenis', 'setuju')->sole();

        $svc = app(BotKeputusanService::class);
        $this->assertNull($svc->periksa($inv, $setuju), 'Lolos pemeriksaan awal (pivot masih ada)');

        DB::table('investor_sppg')->where('user_id', $inv->id)->where('sppg_id', $this->sppgA)->delete();
        $hasil = $svc->putuskan($inv, $setuju, 'disetujui', null, (int) $inv->telegram_id);

        $this->assertSame('ditolak_hak', $hasil['hasil']);
        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
    }

    // ------------------------------------------------------------------ 5. audit menu tanpa catatan

    public function test_audit_menu_tidak_memuat_catatan_keputusan(): void
    {
        $id = $this->pengajuanMenu();
        $inv = $this->investorAB;
        $aksi = $this->sampaiMintaCatatan($inv, 'Tinjau menu');
        $rahasia = 'catatan-menu-rahasia-qwx';

        $this->ketik($inv, $rahasia, $aksi->prompt_message_id);

        $this->assertSame($rahasia, $this->menuRaw($id)->catatan_keputusan, 'Tetap tersimpan di kolom keputusan');
        $this->assertStringNotContainsString($rahasia, json_encode(AuditLog::query()->withoutGlobalScopes()->get()->toArray()));
    }

    // ------------------------------------------------------------------ 6. galat aman

    public function test_query_exception_pada_jalur_catatan_tidak_membocorkan_catatan_ke_log(): void
    {
        $id = $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $aksi = $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');
        $rahasia = 'catatan-bocor-uji-mnb';

        $this->app->instance(PersetujuanPengeluaranService::class, new class(app(BudgetService::class)) extends PersetujuanPengeluaranService
        {
            public function putuskan(User $investor, Pengeluaran $pengeluaran, string $keputusan, ?string $catatan, int $versi): Pengeluaran
            {
                throw new QueryException('sqlite', 'insert into x (catatan) values (?)', [$catatan], new Exception("SQLSTATE[HY000]: gagal untuk {$catatan}"));
            }
        });

        $this->ketik($inv, $rahasia, $aksi->prompt_message_id);

        $this->assertSame('diajukan', $this->keluarRaw($id)->status_persetujuan->value);
        $log = json_encode($this->logBot);
        $this->assertStringContainsString('bot.galat', $log);
        $this->assertStringContainsString('QueryException', $log);
        $this->assertStringNotContainsString($rahasia, $log);
        $this->assertStringNotContainsString($rahasia, json_encode($this->llm->calls));
    }

    // ------------------------------------------------------------------ 7. rate limit

    public function test_perintah_menu_start_bantuan_dibatasi_per_pengguna_dan_diam_bila_lewat(): void
    {
        config(['telegram.perintah_rate' => 3]);
        $uid = (int) $this->investorAB->telegram_id;

        $this->ketik($this->investorAB, '/menu');
        $this->ketik($this->investorAB, '/start');
        $this->ketik($this->investorAB, '/bantuan');
        $this->assertCount(3, $this->messagesTo($uid));

        $this->ketik($this->investorAB, '/menu');
        $this->ketik($this->investorAB, '/bantuan');
        $this->assertCount(3, $this->messagesTo($uid), 'Lewat batas: tanpa balasan');

        // Pengguna lain tidak terpengaruh.
        $this->ketik($this->investorC, '/menu');
        $this->assertCount(1, $this->messagesTo((int) $this->investorC->telegram_id));
    }

    public function test_balasan_catatan_gagal_berulang_dibatasi_dan_tetap_tidak_ke_ai(): void
    {
        config(['telegram.catatan_gagal_rate' => 3]);
        $this->pengeluaranMenunggu();
        $inv = $this->investorAB;
        $uid = (int) $inv->telegram_id;
        $aksi = $this->sampaiMintaCatatan($inv, 'Tinjau pengeluaran');

        for ($i = 0; $i < 3; $i++) {
            $this->ketik($inv, 'ab', $aksi->prompt_message_id);
        }
        $sebelum = $this->messagesTo($uid)->count();

        // Setelah batas: diam, walau balasan sebenarnya sah.
        $this->ketik($inv, 'Alasan yang sah dan cukup panjang', $aksi->prompt_message_id);

        $this->assertCount($sebelum, $this->messagesTo($uid));
        $this->assertSame('menunggu_catatan', $aksi->fresh()->status);
        $this->assertSame([], $this->llm->calls);
    }

    // ------------------------------------------------------------------ 11. indeks

    public function test_bot_aksi_memiliki_indeks_created_at_untuk_pembersihan(): void
    {
        $this->assertTrue(Schema::hasIndex('bot_aksi', ['created_at']));
        $this->assertTrue(Schema::hasIndex('bot_aksi', ['expires_at']));
    }
}

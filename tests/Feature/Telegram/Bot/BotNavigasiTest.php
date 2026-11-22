<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\Bot\Layar\LayarBaca;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Router navigasi baca `n1` (R02-A1): token dapat dipakai ulang, tetapi SETIAP klik menghitung ulang pengguna,
 * sesi penuh, peran/RBAC, SPPG/pivot, dan Policy. callback_data tidak menentukan hak. Layar memakai LayarUji.
 */
class BotNavigasiTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LayarUji::$render = [];
        $this->pasangLayar();

        foreach ([$this->sppgA, $this->sppgB, $this->sppgC] as $i => $sppg) {
            TitikLayanan::factory()->count($i + 1)->create(['sppg_id' => $sppg]);
        }

        $this->forgetActor();
    }

    /** @param list<LayarBaca> $tambahan */
    private function pasangLayar(array $tambahan = []): void
    {
        $this->app->instance(BotLayarRegistry::class, new BotLayarRegistry([
            ...BotLayarRegistry::layarBawaan(),
            new LayarUji('ku', 'keuangan'),
            new LayarUji('jd', 'jadwal'),
            new LayarUji('pg', 'pengguna'),
            new LayarUji('pr', 'pengguna', LayarBaca::HAK_BACA_MILIK_SENDIRI),
            new LayarUji('pm', 'penerima-manfaat', LayarBaca::HAK_AGREGAT),
            new LayarUji('rk', 'rekap-harian', jeda: 3),
            new LayarUji('tl', 'titik-layanan', izinkan: fn (User $u) => $u->role !== Role::KepalaSppg),
            ...$tambahan,
        ]));
    }

    private function n1(User $user, string $layar, ?int $sppg, ?int $sekarang = null): string
    {
        return BotNavCodec::data($layar, $sppg, (int) $user->telegram_id, $sekarang);
    }

    private function jumlahEdit(): int
    {
        return $this->jumlahEditData();
    }

    private function jumlahKirim(int $uid): int
    {
        return $this->messagesTo($uid)->count();
    }

    // ------------------------------------------------------------------ jalur sukses + replay

    public function test_klik_n1_mengedit_pesan_dengan_data_sppg_sendiri_dan_dapat_dipakai_ulang(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $data = $this->n1($this->kepalaA, 'ku', $this->sppgA);

        $this->tekan($this->kepalaA, $data);
        $this->tekan($this->kepalaA, $data);

        $this->assertSame(2, $this->jumlahEdit(), 'Replay diizinkan: dua kali edit di tempat');
        $this->assertCount(2, LayarUji::$render);
        $this->assertSame("LAYAR-ku SPPG-{$this->sppgA} TITIK-1", $this->teksLayar($this->kepalaA));
        $this->assertNull($this->jawabanTerakhir());
        $this->assertSame(0, BotAksi::query()->count(), 'Navigasi tidak menulis baris bot_aksi');

        // Tombol pada layar = n1 milik pengguna ini, < 64 byte.
        foreach ($this->tombolLayar((int) $this->kepalaA->telegram_id) as $t) {
            $this->assertNotNull(BotNavCodec::urai($t['callback_data'], (int) $this->kepalaA->telegram_id));
            $this->assertLessThan(64, strlen($t['callback_data']));
        }
    }

    public function test_replay_diizinkan_tetapi_hak_dicek_ulang_setiap_klik(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $data = $this->n1($this->kepalaA, 'ku', $this->sppgA);
        $this->tekan($this->kepalaA, $data);
        $this->assertCount(1, LayarUji::$render);

        // Hak modul dicabut di config/rbac.php (sesi tetap utuh): klik ulang dihitung dari matriks SAAT INI.
        config(['rbac.modules.keuangan.kepala_sppg' => '']);
        $this->tekan($this->kepalaA, $data);

        $this->assertCount(1, LayarUji::$render, 'Klik ulang setelah hak hilang tidak merender');
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame(1, $this->jumlahEdit());
    }

    // ------------------------------------------------------------------ perubahan di antara render dan klik

    public function test_peran_berubah_lewat_model_mencabut_sesi_sehingga_klik_hanya_tombol_masuk(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $data = $this->n1($this->akuntanA, 'ku', $this->sppgA);
        $this->akuntanA->forceFill(['role' => Role::Relawan, 'unit' => RelawanUnit::Dapur])->save();

        $this->tekan($this->akuntanA, $data);

        $this->assertSame([], LayarUji::$render);
        $this->assertSame(0, $this->jumlahEdit());
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->teksLayar($this->akuntanA));
        $this->assertSame(BotCallbackHandler::PERLU_MASUK_SINGKAT, $this->jawabanTerakhir());
    }

    public function test_peran_berubah_di_antara_render_dan_klik_ditolak_tanpa_data(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $data = $this->n1($this->akuntanA, 'ku', $this->sppgA);
        // Lapis kedua: peran berubah TANPA pencabutan sesi (mis. diubah langsung di basis data).
        DB::table('users')->where('id', $this->akuntanA->id)->update(['role' => Role::Relawan->value, 'unit' => RelawanUnit::Dapur->value]);
        $this->assertTrue(app(SesiService::class)->punyaSesiPenuh($this->akuntanA->fresh()));
        $kirim = $this->jumlahKirim((int) $this->akuntanA->telegram_id);

        $this->tekan($this->akuntanA, $data);

        $this->assertSame([], LayarUji::$render);
        $this->assertSame(0, $this->jumlahEdit());
        $this->assertSame($kirim, $this->jumlahKirim((int) $this->akuntanA->telegram_id));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame('true', $this->telegramCalls('answerCallbackQuery')->last()['show_alert']);
    }

    public function test_pengguna_nonaktif_atau_dihapus_dijawab_seragam(): void
    {
        foreach (['nonaktif', 'dihapus'] as $kasus) {
            $user = $this->userOf(Role::KepalaSppg, $this->sppgA);
            $this->sesiPenuh($user);
            $data = $this->n1($user, 'ku', $this->sppgA);

            $kasus === 'nonaktif' ? $user->forceFill(['is_active' => false])->save() : $user->delete();
            $this->tekan($user, $data);

            $this->assertSame([], LayarUji::$render, $kasus);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir(), $kasus);
        }

        $this->assertSame(0, $this->jumlahEdit());
    }

    public function test_pivot_investor_dicabut_di_antara_render_dan_klik(): void
    {
        $this->sesiPenuh($this->investorAB);
        $data = $this->n1($this->investorAB, 'ku', $this->sppgA);
        $this->tekan($this->investorAB, $data);
        $this->assertSame("LAYAR-ku SPPG-{$this->sppgA} TITIK-1", $this->teksLayar($this->investorAB));

        DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgA)->delete();
        $this->tekan($this->investorAB, $data);

        $this->assertCount(1, LayarUji::$render);
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());

        // SPPG yang masih dimiliki tetap bisa dibuka (hak dihitung dari pivot saat ini).
        $this->tekan($this->investorAB, $this->n1($this->investorAB, 'ku', $this->sppgB));
        $this->assertSame("LAYAR-ku SPPG-{$this->sppgB} TITIK-2", $this->teksLayar($this->investorAB));
    }

    public function test_sesi_dicabut_di_antara_render_dan_klik_hanya_tombol_masuk(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $data = $this->n1($this->kepalaA, 'ku', $this->sppgA);
        $this->tekan($this->kepalaA, $data);
        app(SesiService::class)->keluarSemua($this->kepalaA);
        $this->fakeTelegram();

        $this->tekan($this->kepalaA, $data);

        $this->assertCount(1, LayarUji::$render, 'Tidak dirender lagi');
        $this->assertSame(0, $this->jumlahEdit());
        $pesan = $this->messagesTo((int) $this->kepalaA->telegram_id);
        $this->assertCount(1, $pesan);
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $pesan->first()['text']);
        $tombol = $this->markupOf($pesan->first())['inline_keyboard'];
        $this->assertSame([[['text' => TelegramUpdateHandler::LABEL_MASUK, 'web_app' => ['url' => 'https://sigap.test/miniapp/']]]], $tombol);
        $this->assertSame(BotCallbackHandler::PERLU_MASUK_SINGKAT, $this->jawabanTerakhir());
    }

    // ------------------------------------------------------------------ token palsu / milik orang lain / kedaluwarsa

    public function test_token_palsu_milik_orang_lain_atau_layar_tak_dikenal_dijawab_seragam(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->kepalaB);
        $sah = $this->n1($this->kepalaA, 'ku', $this->sppgA);
        [$p, $l, $s, $e, $m] = explode('.', $sah);

        $kasus = [
            'sppg diubah' => "{$p}.{$l}.".base_convert((string) $this->sppgB, 10, 36).".{$e}.{$m}",
            'mac diubah' => "{$p}.{$l}.{$s}.{$e}.".strrev($m),
            'layar diubah' => "{$p}.jd.{$s}.{$e}.{$m}",
            'milik telegram lain' => $this->n1($this->kepalaB, 'ku', $this->sppgA),
            'format rusak' => 'n1.ku',
            'layar tak dikenal (MAC sah)' => $this->n1($this->kepalaA, 'zz', $this->sppgA),
        ];

        foreach ($kasus as $nama => $data) {
            $this->tekan($this->kepalaA, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir(), $nama);
            $this->assertNull($this->telegramCalls('answerCallbackQuery')->last()['show_alert'] ?? null, $nama);
        }

        $this->assertSame([], LayarUji::$render);
        $this->assertSame(0, $this->jumlahEdit());
        $this->assertSame(0, $this->jumlahKirim((int) $this->kepalaA->telegram_id));
    }

    public function test_token_dipakai_dari_chat_lain_ditolak(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $data = $this->n1($this->kepalaA, 'ku', $this->sppgA);

        $this->tekan($this->kepalaA, $data, chatId: (int) $this->kepalaB->telegram_id);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());

        $this->tekan($this->kepalaA, $data, chatId: -100555, chatType: 'group');
        $this->assertSame([], LayarUji::$render);
        $this->assertSame(0, $this->jumlahEdit() + $this->telegramCalls('sendMessage')->count());
    }

    public function test_banyak_token_palsu_memicu_batas_gagal(): void
    {
        $this->sesiPenuh($this->kepalaA);

        for ($i = 0; $i < 10; $i++) {
            $this->tekan($this->kepalaA, 'n1.ku.1.zzzzzz.AAAAAAAA');
        }

        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'ku', $this->sppgA));
        $this->assertSame([], LayarUji::$render, 'Lewat batas gagal: klik sah pun dijawab diam');
        $this->assertNull($this->jawabanTerakhir());
    }

    public function test_token_kedaluwarsa_merender_beranda_segar_di_tempat(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $lama = $this->n1($this->kepalaA, 'ku', $this->sppgA, time() - 31 * 60);

        $this->tekan($this->kepalaA, $lama);

        $this->assertSame([], LayarUji::$render, 'Layar tujuan tidak dirender');
        $this->assertSame(1, $this->jumlahEdit());
        $teks = $this->teksLayar($this->kepalaA);
        $this->assertStringContainsString(BotCallbackHandler::MENU_DIPERBARUI, $teks);
        $this->assertStringContainsString('Menu SiGAP', $teks);
        $this->assertNull($this->jawabanTerakhir());
    }

    public function test_token_kedaluwarsa_tanpa_sesi_tetap_hanya_tombol_masuk(): void
    {
        $lama = $this->n1($this->kepalaA, 'ku', $this->sppgA, time() - 31 * 60);
        $this->tekan($this->kepalaA, $lama);

        $this->assertSame(0, $this->jumlahEdit());
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->teksLayar($this->kepalaA));
    }

    // ------------------------------------------------------------------ SPPG

    public function test_peran_satu_sppg_tidak_bisa_berpindah_sppg_lewat_token(): void
    {
        $this->sesiPenuh($this->kepalaA);

        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'ku', $this->sppgB));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());

        // Dipindahkan ke SPPG B setelah render (sesi tetap ada): token SPPG A ditolak.
        $data = $this->n1($this->kepalaA, 'ku', $this->sppgA);
        DB::table('users')->where('id', $this->kepalaA->id)->update(['sppg_id' => $this->sppgB]);
        $this->tekan($this->kepalaA, $data);
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame([], LayarUji::$render);

        // Tanpa SPPG di token: memakai users.sppg_id saat ini.
        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'ku', null));
        $this->assertSame("LAYAR-ku SPPG-{$this->sppgB} TITIK-2", $this->teksLayar($this->kepalaA));
    }

    public function test_investor_sppg_bukan_miliknya_dan_pilih_sppg(): void
    {
        $this->sesiPenuh($this->investorAB, $this->investorC);

        $this->tekan($this->investorAB, $this->n1($this->investorAB, 'ku', $this->sppgC));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());

        $this->tekan($this->investorAB, $this->n1($this->investorAB, 'ku', null));
        $this->assertSame(BotCallbackHandler::PILIH_SPPG, $this->jawabanTerakhir(), 'Banyak SPPG tanpa pilihan');

        $this->tekan($this->investorC, $this->n1($this->investorC, 'ku', null));
        $this->assertSame("LAYAR-ku SPPG-{$this->sppgC} TITIK-3", $this->teksLayar($this->investorC), 'Satu SPPG: otomatis');
        $this->assertCount(1, LayarUji::$render);
    }

    public function test_admin_membaca_satu_sppg_terpilih_baca_saja(): void
    {
        $this->sesiPenuh($this->admin);

        $this->tekan($this->admin, $this->n1($this->admin, 'ku', null));
        $this->assertSame(BotCallbackHandler::PILIH_SPPG, $this->jawabanTerakhir(), 'Admin tanpa SPPG: data lintas SPPG tidak dirender');

        $this->tekan($this->admin, $this->n1($this->admin, 'ku', $this->sppgB));
        $this->assertSame("LAYAR-ku SPPG-{$this->sppgB} TITIK-2", $this->teksLayar($this->admin), 'SppgBound dipersempit ke SPPG terpilih');

        Sppg::query()->whereKey($this->sppgC)->delete();
        $this->tekan($this->admin, $this->n1($this->admin, 'ku', $this->sppgC));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir(), 'SPPG terhapus');
        $this->assertCount(1, LayarUji::$render);
    }

    public function test_admin_tidak_dapat_menulis_atau_menerbitkan_tombol_aksi_dari_layar_baca(): void
    {
        $this->sesiPenuh($this->admin);
        $this->pasangLayar([new LayarUji('jh', 'keuangan', efek: function (User $u, ?int $sppg): void {
            app(BotAksiService::class)->terbit($u, (int) $u->telegram_id, BotAksi::JENIS_DAFTAR, ['sppg_id' => $sppg]);
            TitikLayanan::factory()->create(['sppg_id' => $sppg]);
        })]);
        $titikSebelum = TitikLayanan::query()->withoutGlobalScopes()->count();

        $this->tekan($this->admin, $this->n1($this->admin, 'jh', $this->sppgA));

        $this->assertCount(1, LayarUji::$render);
        $this->assertSame(0, BotAksi::query()->count(), 'Token b1 di jalur admin di-rollback');
        $this->assertSame($titikSebelum, TitikLayanan::query()->withoutGlobalScopes()->count(), 'Tulisan di-rollback');
    }

    // ------------------------------------------------------------------ RBAC / Policy / jeda / Segarkan

    public function test_hak_layar_dari_rbac_dan_policy_saat_ini(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->relawanA, $this->ahliGiziA, $this->investorC);

        // pengguna R* (relawan): layar HAK_BACA ditolak, HAK_BACA_MILIK_SENDIRI boleh.
        $this->tekan($this->relawanA, $this->n1($this->relawanA, 'pg', $this->sppgA));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->tekan($this->relawanA, $this->n1($this->relawanA, 'pr', $this->sppgA));
        $this->assertStringStartsWith('LAYAR-pr', $this->teksLayar($this->relawanA));

        // Agregat: investor (rbac.agregat) boleh, relawan tidak.
        $this->tekan($this->investorC, $this->n1($this->investorC, 'pm', $this->sppgC));
        $this->assertStringStartsWith('LAYAR-pm', $this->teksLayar($this->investorC));
        $this->tekan($this->relawanA, $this->n1($this->relawanA, 'pm', $this->sppgA));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());

        // izinkan() (Policy tambahan) menolak walau RBAC mengizinkan.
        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'tl', $this->sppgA));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->tekan($this->ahliGiziA, $this->n1($this->ahliGiziA, 'tl', $this->sppgA));
        $this->assertStringStartsWith('LAYAR-tl', $this->teksLayar($this->ahliGiziA));

        $this->assertSame(['pr', 'pm', 'tl'], array_column(LayarUji::$render, 'layar'));
    }

    public function test_jeda_layar_berat(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $data = $this->n1($this->kepalaA, 'rk', $this->sppgA);

        $this->tekan($this->kepalaA, $data);
        $this->tekan($this->kepalaA, $data);

        $this->assertCount(1, LayarUji::$render);
        $this->assertSame('Terlalu cepat. Coba lagi sebentar.', $this->jawabanTerakhir());
    }

    public function test_segarkan_tanpa_perubahan_dijawab_sudah_terbaru_tanpa_pesan_baru(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->fakeTelegram(['api.telegram.org/*/editMessageText' => Http::response([
            'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same',
        ], 400)]);

        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'ku', $this->sppgA));

        $this->assertSame(1, $this->jumlahEdit());
        $this->assertSame(0, $this->jumlahKirim((int) $this->kepalaA->telegram_id), 'Tidak menggandakan pesan');
        $this->assertCount(1, $this->telegramCalls('answerCallbackQuery'), 'Dijawab tepat sekali');
        $this->assertSame(BotCallbackHandler::SUDAH_TERBARU, $this->jawabanTerakhir());
    }

    public function test_edit_gagal_fallback_pesan_baru_terlindungi(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->fakeTelegram(['api.telegram.org/*/editMessageText' => Http::response([
            'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found',
        ], 400)]);

        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'ku', $this->sppgA));

        $pesan = $this->messagesTo((int) $this->kepalaA->telegram_id);
        $this->assertCount(1, $pesan);
        $this->assertSame('true', $pesan->first()['protect_content']);
        $this->assertSame((string) $this->kepalaA->telegram_id, (string) $pesan->first()['chat_id']);
    }

    public function test_galat_render_dijawab_umum_tanpa_detail(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->pasangLayar([new LayarUji('gl', 'keuangan', efek: fn () => throw new LogicException('rahasia-internal'))]);

        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'gl', $this->sppgA));

        $this->assertSame('Terjadi kesalahan. Ketik /menu untuk memulai lagi.', $this->jawabanTerakhir());
        $this->assertStringNotContainsString('rahasia-internal', json_encode($this->logBot));
    }

    public function test_log_navigasi_tanpa_isi_layar(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->tekan($this->kepalaA, $this->n1($this->kepalaA, 'ku', $this->sppgA));

        $log = collect($this->logBot)->firstWhere('message', 'bot.nav');
        $this->assertSame(['layar' => 'ku', 'hasil' => 'ok', 'user_id' => $this->kepalaA->id, 'sppg_id' => $this->sppgA], $log['context']);
        $this->assertStringNotContainsString('LAYAR-ku', json_encode($this->logBot));
    }

    // ------------------------------------------------------------------ registri

    public function test_registri_menolak_layar_tidak_konsisten(): void
    {
        foreach ([
            'modul tak dikenal' => new LayarUji('xx', 'tidak-ada'),
            'tanpa modul bukan HAK_SESI' => new LayarUji('xx', null),
            'HAK_SESI dengan id di luar daftar' => new LayarUji('xx', null, LayarBaca::HAK_SESI),
            'HAK_SESI dengan modul' => new LayarUji('h', 'keuangan', LayarBaca::HAK_SESI),
            'hak tak dikenal' => new LayarUji('xx', 'keuangan', 'CRUD'),
            'id tidak sah' => new LayarUji('X1', 'keuangan'),
        ] as $nama => $layar) {
            try {
                new BotLayarRegistry([$layar]);
                $this->fail($nama);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        new BotLayarRegistry([new LayarUji('ku', 'keuangan'), new LayarUji('ku', 'jadwal')]);
    }

    public function test_registri_bawaan_valid_dan_perintah_menunjuk_id_sah(): void
    {
        $registry = new BotLayarRegistry;
        $this->assertNotNull($registry->cari(BotLayarRegistry::BERANDA));

        foreach (BotLayarRegistry::PERINTAH as $perintah => $id) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{0,7}$/', $id, $perintah);
            $this->assertArrayHasKey($perintah, BotLayarRegistry::DESKRIPSI_PERINTAH);
        }
    }

    public function test_relawan_tanpa_unit_mengikuti_rbac_saat_ini(): void
    {
        $tanpaUnit = $this->userOf(Role::Relawan, $this->sppgA);
        $tanpaUnit->forceFill(['unit' => null])->save();
        $this->sesiPenuh($tanpaUnit);

        $this->tekan($tanpaUnit, $this->n1($tanpaUnit, 'jd', $this->sppgA));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir(), 'jadwal ber-unit_rules tertutup tanpa unit');
        $this->assertSame([], LayarUji::$render);
    }
}

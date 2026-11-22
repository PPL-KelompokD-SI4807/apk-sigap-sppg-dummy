<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Auth\SesiService;
use App\Services\Dashboard\DashboardService;
use App\Services\Keuangan\BudgetService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\Bot\Layar\LayarDashboard;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Support\SppgAktif;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tombol dan layar "Dashboard" Bot (R-07, layar `db`): ringkasan 5-8 angka kunci per peran dari DashboardService.
 * Memakai pola n1 (bertanda tangan, terikat telegram_id, TTL 30 menit, hak dicek ulang tiap klik, sesi penuh wajib).
 */
class BotDashboardTest extends LayarBacaTestCase
{
    /** Label baris yang harus ada per peran (kunci = nama properti pengguna di LayarBacaTestCase::pengguna). */
    private const LABEL = [
        'kepalaA' => ['Jadwal produksi hari ini', 'Porsi hari ini', 'Distribusi hari ini', 'Mutu hari ini', 'Keluhan terbuka', 'Stok kritis', 'Serapan anggaran bulan ini', 'Rekap harian'],
        'ahliGiziA' => ['Menu hari ini', 'Kesesuaian gizi', 'Biaya per porsi rata-rata', 'Penerima dengan catatan alergi', 'Mutu hari ini', 'Persen layak bulan ini'],
        'akuntanA' => ['Pencairan bulan ini', 'Pengeluaran disetujui bulan ini', 'Selisih', 'Menunggu persetujuan investor', 'Pembelian menunggu verifikasi', 'Pengeluaran tanpa bukti nota'],
        'stafGudangA' => ['Jenis bahan baku', 'Stok di bawah minimum', 'Kedaluwarsa', 'Mutasi stok hari ini', 'Penerimaan dari pemasok hari ini'],
        'relawanA' => ['Jadwal masak hari ini', 'Log produksi hari ini', 'Checklist mutu belum diisi'],
        'relawanDistribusi' => ['Titik tujuan hari ini', 'Status pengiriman', 'Bukti serah terima belum diunggah', 'Armada'],
        'investorC' => ['Porsi hari ini', 'Serapan anggaran bulan ini', 'Menunggu keputusan investor', 'Stok kritis', 'Pemeriksaan mutu tidak layak', 'Keluhan kritis terbuka', 'Pengeluaran tanpa bukti nota'],
        'admin' => ['SPPG dicakup', 'Porsi hari ini', 'Distribusi hari ini', 'Keluhan terbuka', 'Stok di bawah minimum', 'Pengguna aktif', 'Login gagal hari ini', 'Draf Agen AI menunggu konfirmasi'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->isiData($this->sppgA, 'Alfa');
        $this->isiData($this->sppgB, 'Beta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function peranBerisi(): iterable
    {
        foreach (array_keys(self::LABEL) as $p) {
            yield $p => [$p];
        }
    }

    /** Dari /menu: tekan "Dashboard". */
    private function bukaDashboard(User $user): string
    {
        $this->sesiPenuh($user);
        $this->ketik($user, '/menu');
        $this->tekan($user, $this->dataTombol($user, 'Dashboard'));

        return $this->teksLayar($user);
    }

    private function sppgDariPeran(string $peran): ?int
    {
        return in_array($peran, ['admin', 'investorAB'], true) ? null : $this->sppgDari($peran);
    }

    // ------------------------------------------------------------------ matriks menu

    public function test_menu_punya_tombol_dashboard_untuk_semua_profil_kecuali_relawan_tanpa_unit(): void
    {
        foreach (BotLayarRegistry::MENU as $profil => $entri) {
            $ada = collect($entri)->contains(fn (array $e) => $e[0] === 'db' && $e[3] === BotLayarRegistry::HAK_DASHBOARD && $e[2] === null);
            $this->assertSame($profil !== 'relawan.tanpa_unit', $ada, $profil);
        }

        $this->assertNotNull(app(BotLayarRegistry::class)->cari('db'));
        $this->assertSame('sesi', app(BotLayarRegistry::class)->cari('db')->hak());
    }

    public function test_tombol_dashboard_di_beranda_per_peran(): void
    {
        foreach (['kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'investorC', 'investorAB', 'admin'] as $peran) {
            $user = $this->pengguna($peran);
            $this->sesiPenuh($user);
            $this->ketik($user, '/menu');
            $this->assertTrue($this->adaTombol($user, 'Dashboard'), $peran);
            $this->assertStringStartsWith('n1.db.', $this->dataTombol($user, 'Dashboard'), $peran);
        }

        $tanpaUnit = $this->relawanTanpaUnit();
        $this->sesiPenuh($tanpaUnit);
        $this->ketik($tanpaUnit, '/menu');
        $this->assertFalse($this->adaTombol($tanpaUnit, 'Dashboard'));
    }

    // ------------------------------------------------------------------ isi per peran

    #[DataProvider('peranBerisi')]
    public function test_ringkasan_per_peran_5_sampai_8_angka_tanpa_data_pribadi(string $peran): void
    {
        $user = $this->pengguna($peran);
        $teks = $this->bukaDashboard($user);

        $this->assertStringStartsWith('<b>Dashboard</b>', $teks);
        $this->assertSame('HTML', $this->layar((int) $user->telegram_id)['parse_mode']);

        foreach (self::LABEL[$peran] as $label) {
            $this->assertStringContainsString("- <b>{$label}</b>: ", $teks, "{$peran}: {$label}");
        }

        $baris = substr_count($teks, "\n- <b>");
        $this->assertGreaterThanOrEqual(min(3, count(self::LABEL[$peran])), $baris, $peran);
        $this->assertLessThanOrEqual(8, $baris, $peran);

        $this->assertStringNotContainsString((string) $user->name, $teks);
        $this->assertTanpaTerlarang($teks, $peran);

        foreach (['SD Alfa Uji', 'SD Beta Uji', 'Menu Alfa', 'Menu Beta', 'Bahan Alfa', 'Bahan Beta', 'Dana Alfa', 'BATCH-', 'Siti', 'Sayur lembek'] as $privat) {
            $this->assertStringNotContainsString($privat, $teks, "{$peran} bocor {$privat}");
        }

        // Tombol: callback_data < 64 byte, "Lihat detail" membuka Mini App utama (layar dashboard Mini App belum ada).
        $tombol = $this->tombolLayar((int) $user->telegram_id);
        $detail = collect($tombol)->first(fn (array $t) => $t['text'] === 'Lihat detail');
        $this->assertNotNull($detail, $peran);
        $this->assertStringStartsWith('https://sigap.test/miniapp/', $detail['web_app']['url']);
        $this->assertTrue($this->adaTombol($user, 'Kembali'));

        foreach ($tombol as $t) {
            $this->assertLessThan(64, strlen((string) ($t['callback_data'] ?? '')));
            $this->assertStringStartsNotWith('b1.', (string) ($t['callback_data'] ?? ''), 'layar baca tidak menerbitkan aksi tulis');
        }
    }

    public function test_peran_satu_sppg_hanya_melihat_angka_sppg_sendiri(): void
    {
        $a = $this->bukaDashboard($this->kepalaA);
        $this->assertStringContainsString('<b>Dashboard</b> - <b>SPPG A</b>', $a);
        $this->assertStringNotContainsString('SPPG B', $a);
        $this->assertStringContainsString('Porsi hari ini</b>: 480 dari 500 porsi', $a);

        $b = $this->bukaDashboard($this->kepalaB);
        $this->assertStringContainsString('<b>Dashboard</b> - <b>SPPG B</b>', $b);
        $this->assertStringNotContainsString('SPPG A', $b);
    }

    public function test_pesan_terlindungi_dan_dashboard_membalas_html_terlindungi(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->ketik($this->kepalaA, '/menu');
        $this->assertSame('true', $this->layar((int) $this->kepalaA->telegram_id)['protect_content']);

        $layar = ActingUser::run($this->kepalaA, fn () => app(LayarDashboard::class)->render($this->kepalaA, $this->sppgA, new KonteksLayar(1, (int) $this->kepalaA->telegram_id)));
        $this->assertTrue($layar->lindungi);
        $this->assertTrue($layar->html);
    }

    public function test_nilai_dari_basis_data_di_escape_html(): void
    {
        DB::table('sppgs')->where('id', $this->sppgA)->update(['nama' => 'SPPG <b>&"Co']);

        $teks = $this->bukaDashboard($this->kepalaA);

        $this->assertStringContainsString('SPPG &lt;b&gt;&amp;&quot;Co', $teks);
        $this->assertStringNotContainsString('SPPG <b>&', $teks);
    }

    // ------------------------------------------------------------------ investor dan admin

    public function test_investor_banyak_sppg_gabungan_lalu_memilih_sppg_miliknya(): void
    {
        $teks = $this->bukaDashboard($this->investorAB);
        $this->assertStringContainsString('<i>Gabungan 2 SPPG</i>', $teks);
        $this->assertStringContainsString('Serapan anggaran bulan ini</b>: Rp 246.000 dari Rp 20.000.000', $teks);
        $this->assertTrue($this->adaTombol($this->investorAB, 'SPPG A'));
        $this->assertTrue($this->adaTombol($this->investorAB, 'SPPG B'));
        $this->assertFalse($this->adaTombol($this->investorAB, 'SPPG C'), 'hanya SPPG miliknya');
        $this->assertFalse($this->adaTombol($this->investorAB, 'Semua SPPG'));

        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'SPPG A'));
        $satu = $this->teksLayar($this->investorAB);
        $this->assertStringContainsString('<b>Dashboard</b> - <b>SPPG A</b>', $satu);
        $this->assertStringNotContainsString('Gabungan', $satu);
        $this->assertStringContainsString('Serapan anggaran bulan ini</b>: Rp 123.000 dari Rp 10.000.000', $satu);
        $this->assertTrue($this->adaTombol($this->investorAB, 'Semua SPPG'));
        $this->assertTrue($this->adaTombol($this->investorAB, 'SPPG B'));
        $this->assertFalse($this->adaTombol($this->investorAB, 'SPPG A'));
        $detail = collect($this->tombolLayar((int) $this->investorAB->telegram_id))->first(fn (array $t) => $t['text'] === 'Lihat detail');
        $this->assertStringEndsWith('?sppg='.$this->sppgA, $detail['web_app']['url']);

        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Semua SPPG'));
        $this->assertStringContainsString('Gabungan 2 SPPG', $this->teksLayar($this->investorAB));
    }

    public function test_investor_tidak_bisa_membuka_sppg_milik_orang_lain_atau_yang_dicabut(): void
    {
        $this->sesiPenuh($this->investorAB);
        $tg = (int) $this->investorAB->telegram_id;

        // SPPG C bukan miliknya (token bertanda tangan sah, hak dicek ulang).
        $this->tekan($this->investorAB, BotNavCodec::data('db', $this->sppgC, $tg));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame(0, $this->jumlahEditData());

        // Pivot SPPG A dicabut setelah tombol muncul.
        $this->tekan($this->investorAB, BotNavCodec::data('db', $this->sppgA, $tg));
        $this->assertStringContainsString('SPPG A', $this->teksLayar($this->investorAB));
        DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgA)->delete();
        $edit = $this->jumlahEditData();
        $this->tekan($this->investorAB, BotNavCodec::data('db', $this->sppgA, $tg));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame($edit, $this->jumlahEditData());

        // Gabungan sekarang hanya SPPG B.
        $this->tekan($this->investorAB, BotNavCodec::data('db', null, $tg));
        $this->assertStringContainsString('SPPG B', $this->teksLayar($this->investorAB));
        $this->assertStringNotContainsString('SPPG A', $this->teksLayar($this->investorAB));
    }

    public function test_admin_gabungan_lalu_satu_sppg_baca_saja(): void
    {
        $this->forgetActor();
        $teks = $this->bukaDashboard($this->admin);
        $this->assertStringContainsString('<i>Gabungan ', $teks);
        $this->assertTrue($this->adaTombol($this->admin, 'Pilih SPPG'));

        $this->tekan($this->admin, $this->dataTombol($this->admin, 'Pilih SPPG'));
        $this->tekan($this->admin, $this->dataTombol($this->admin, 'SPPG B'));
        $this->tekan($this->admin, $this->dataTombol($this->admin, 'Dashboard'));

        $satu = $this->teksLayar($this->admin);
        $this->assertStringContainsString('<b>Dashboard</b> - <b>SPPG B</b>', $satu);
        $this->assertStringContainsString('SPPG dicakup</b>: 1', $satu);
        $this->assertTrue($this->adaTombol($this->admin, 'Semua SPPG'));

        foreach ($this->tombolLayar((int) $this->admin->telegram_id) as $t) {
            $this->assertStringStartsNotWith('b1.', (string) ($t['callback_data'] ?? ''));
        }

        $this->assertSame(0, BotAksi::query()->where('user_id', $this->admin->id)->count());

        // SPPG yang tidak ada: ditolak, tidak dirender.
        $edit = $this->jumlahEditData();
        $this->tekan($this->admin, BotNavCodec::data('db', 987654, (int) $this->admin->telegram_id));
        $this->assertSame($edit, $this->jumlahEditData());
    }

    // ------------------------------------------------------------------ keamanan callback

    public function test_callback_palsu_milik_orang_lain_atau_kedaluwarsa_ditolak(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->kepalaB);
        $tg = (int) $this->kepalaA->telegram_id;
        $sah = BotNavCodec::data('db', $this->sppgA, $tg);
        [$p, $l, $s, $e, $m] = explode('.', $sah);

        $kasus = [
            'mac diubah' => "{$p}.{$l}.{$s}.{$e}.".strrev($m),
            'sppg diubah' => "{$p}.{$l}.".base_convert((string) $this->sppgB, 10, 36).".{$e}.{$m}",
            'layar diubah' => "{$p}.jd.{$s}.{$e}.{$m}",
            'milik telegram lain' => BotNavCodec::data('db', $this->sppgA, (int) $this->kepalaB->telegram_id),
            'format rusak' => 'n1.db',
        ];

        foreach ($kasus as $nama => $data) {
            $this->tekan($this->kepalaA, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir(), $nama);
        }

        $this->assertSame(0, $this->jumlahEditData());

        // Token sah tetapi untuk SPPG lain: peran satu-SPPG tidak berpindah SPPG lewat token.
        $this->tekan($this->kepalaA, BotNavCodec::data('db', $this->sppgB, $tg));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame(0, $this->jumlahEditData());

        // Kedaluwarsa (31 menit): tidak merender dashboard, beranda segar menggantikan.
        $this->tekan($this->kepalaA, BotNavCodec::data('db', $this->sppgA, $tg, time() - 31 * 60));
        $teks = $this->teksLayar($this->kepalaA);
        $this->assertStringContainsString(BotCallbackHandler::MENU_DIPERBARUI, $teks);
        $this->assertStringNotContainsString('<b>Dashboard</b>', $teks);

        // Token dalam TTL tetap berlaku.
        $this->tekan($this->kepalaA, BotNavCodec::data('db', $this->sppgA, $tg, time() - 29 * 60));
        $this->assertStringStartsWith('<b>Dashboard</b>', $this->teksLayar($this->kepalaA));
    }

    public function test_tanpa_sesi_penuh_hanya_tombol_masuk_dan_dashboard_tidak_dihitung(): void
    {
        // DashboardService final: hitung pembuatannya (layar meresolusinya hanya saat render).
        $dibuat = 0;
        $this->app->bind(DashboardService::class, function ($app) use (&$dibuat): DashboardService {
            $dibuat++;

            return new DashboardService($app->make(BudgetService::class), $app->make(SppgAktif::class));
        });

        foreach (['kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'investorAB', 'investorC', 'admin'] as $peran) {
            $user = $this->pengguna($peran);
            $uid = (int) $user->telegram_id;

            // Tanpa token sesi: menu hanya tombol Masuk, klik n1 db dibalas petunjuk masuk.
            $this->ketik($user, '/menu');
            $this->assertSame(['Masuk lewat Mini App'], array_column($this->tombolLayar($uid), 'text'), $peran);
            $this->tekan($user, BotNavCodec::data('db', $this->sppgDariPeran($peran), $uid));
            $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->teksLayar($user), $peran);

            // Sesi dicabut setelah tombol dirender.
            $this->sesiPenuh($user);
            $token = BotNavCodec::data('db', $this->sppgDariPeran($peran), $uid);
            app(SesiService::class)->keluarSemua($user);
            $this->tekan($user, $token);
            $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->teksLayar($user), "{$peran}/dicabut");
            $this->assertSame(BotCallbackHandler::PERLU_MASUK_SINGKAT, $this->jawabanTerakhir(), $peran);
            $this->assertSame(['Masuk lewat Mini App'], array_column($this->tombolLayar($uid), 'text'), $peran);
        }

        $this->assertSame(0, $this->jumlahEditData());
        $this->assertSame(0, $dibuat, 'DashboardService tidak pernah diresolusi tanpa sesi penuh');
    }

    // ------------------------------------------------------------------ hak dicabut setelah menu muncul

    public function test_peran_dicabut_setelah_menu_muncul_ditolak_tanpa_data(): void
    {
        // Relawan tanpa unit tidak punya dashboard: token sah tetap ditolak (hak dihitung ulang).
        $tanpaUnit = $this->relawanTanpaUnit();
        $this->sesiPenuh($tanpaUnit);
        $this->tekan($tanpaUnit, BotNavCodec::data('db', $this->sppgA, (int) $tanpaUnit->telegram_id));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame(0, $this->jumlahEditData());

        // Menu muncul untuk relawan dapur, lalu unit dicabut lewat basis data (sesi masih utuh): ditolak.
        $relawan = $this->relawanA;
        $this->sesiPenuh($relawan);
        $token = BotNavCodec::data('db', $this->sppgA, (int) $relawan->telegram_id);
        $this->tekan($relawan, $token);
        $this->assertStringStartsWith('<b>Dashboard</b>', $this->teksLayar($relawan));
        $edit = $this->jumlahEditData();

        DB::table('users')->where('id', $relawan->id)->update(['unit' => null]);
        $this->assertTrue(app(SesiService::class)->punyaSesiPenuh($relawan->fresh()));
        $this->tekan($relawan, $token);
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame($edit, $this->jumlahEditData());
        $this->assertTrue($this->telegramCalls('answerCallbackQuery')->last()['show_alert'] === 'true');
    }

    public function test_peran_berubah_diikuti_isi_dashboard_peran_saat_ini(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $token = BotNavCodec::data('db', $this->sppgA, (int) $this->kepalaA->telegram_id);
        $this->tekan($this->kepalaA, $token);
        $this->assertStringContainsString('Jadwal produksi hari ini', $this->teksLayar($this->kepalaA));

        // Diubah langsung di basis data (tanpa pencabutan sesi): isi mengikuti peran SAAT INI, bukan peran saat tombol dibuat.
        DB::table('users')->where('id', $this->kepalaA->id)->update(['role' => Role::AhliGizi->value]);
        $this->tekan($this->kepalaA, $token);

        $teks = $this->teksLayar($this->kepalaA);
        $this->assertStringContainsString('Kesesuaian gizi', $teks);
        $this->assertStringNotContainsString('Jadwal produksi hari ini', $teks);
        $this->assertStringNotContainsString('Serapan anggaran', $teks);
    }

    public function test_hak_baca_modul_dicabut_menghilangkan_baris_setelah_cache_habis(): void
    {
        Carbon::setTestNow(Carbon::now());
        $this->sesiPenuh($this->akuntanA);
        $token = BotNavCodec::data('db', $this->sppgA, (int) $this->akuntanA->telegram_id);
        $this->tekan($this->akuntanA, $token);
        $this->assertStringContainsString('Pencairan bulan ini', $this->teksLayar($this->akuntanA));

        config(['rbac.modules.keuangan.akuntan' => '']);
        Carbon::setTestNow(Carbon::now()->addSeconds(DashboardService::TTL_DETIK + 1));
        $this->tekan($this->akuntanA, $token);

        $teks = $this->teksLayar($this->akuntanA);
        $this->assertStringNotContainsString('Pencairan bulan ini', $teks);
        $this->assertStringNotContainsString('Rp ', $teks);
        $this->assertStringContainsString('Belum ada angka dashboard', $teks);
    }

    public function test_pengguna_nonaktif_dijawab_seragam(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $token = BotNavCodec::data('db', $this->sppgA, (int) $this->kepalaA->telegram_id);
        $this->kepalaA->forceFill(['is_active' => false])->save();

        $this->tekan($this->kepalaA, $token);

        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame(0, $this->jumlahEditData());
    }

    public function test_relawan_unit_distribusi_dan_dapur_berbeda_isi(): void
    {
        $dapur = $this->bukaDashboard($this->relawanA);
        $this->assertStringContainsString('Jadwal masak hari ini', $dapur);
        $this->assertStringNotContainsString('Titik tujuan hari ini', $dapur);

        $relawanDistribusi = $this->relawanDistribusi();
        $this->assertSame(RelawanUnit::Distribusi, $relawanDistribusi->unit);
        $distribusi = $this->bukaDashboard($relawanDistribusi);
        $this->assertStringContainsString('Titik tujuan hari ini', $distribusi);
        $this->assertStringNotContainsString('Jadwal masak hari ini', $distribusi);
    }
}

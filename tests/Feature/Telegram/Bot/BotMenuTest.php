<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotTombol;
use App\Services\Telegram\Bot\Layar\LayarBaca;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Support\Rbac;
use Illuminate\Support\Facades\Http;
use LogicException;
use Mockery\MockInterface;

/**
 * Beranda Bot per peran (R02-A2): tombol disaring dari config/rbac.php lewat BotLayarRegistry sesuai matriks 3.4,
 * baris tetap semua peran, edit di tempat, tombol Kembali, dan aturan sesi penuh. Layar modul diganti LayarUji
 * (layar sungguhan menyusul di B1) agar matriks tombol dapat diuji sekarang.
 */
class BotMenuTest extends BotTestCase
{
    private const TETAP = ['Tanya Agen AI', 'Bantuan', 'Segarkan', 'Buka Mini App'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangRegistri();
    }

    private function pasangRegistri(): void
    {
        $this->app->instance(BotLayarRegistry::class, new BotLayarRegistry([...BotLayarRegistry::layarBawaan(), ...LayarUji::untukMenu()]));
    }

    /** @return list<string> label tombol utama (tanpa baris tetap) pada layar terakhir */
    private function labelUtama(User $user): array
    {
        $label = [];

        foreach ($this->tombolLayar((int) $user->telegram_id) as $t) {
            if (! in_array($t['text'], self::TETAP, true)) {
                $label[] = $t['text'];
            }
        }

        return $label;
    }

    /** @return list<string> */
    private function berandaUntuk(User $user): array
    {
        $this->sesiPenuh($user);
        $this->ketik($user, '/menu');

        return $this->labelUtama($user);
    }

    private function relawanDistribusi(): User
    {
        return $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
    }

    private function relawanTanpaUnit(): User
    {
        $user = $this->userOf(Role::Relawan, $this->sppgA);
        $user->forceFill(['unit' => null])->saveQuietly();

        return $user->fresh();
    }

    // ------------------------------------------------------------------ registri

    public function test_matriks_menu_konsisten_dengan_rbac_dan_perintah_punya_tombol(): void
    {
        $ada = [];

        foreach (BotLayarRegistry::MENU as $profil => $entri) {
            $this->assertNotEmpty($entri, $profil);

            foreach ($entri as [$id, $label, $modul, $hak]) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{0,7}$/', $id, "{$profil}/{$id}");
                $this->assertNotSame('', $label);
                $ada[$id] = true;

                if (in_array($hak, [BotLayarRegistry::HAK_PILIH, BotLayarRegistry::HAK_PUTUSKAN, BotLayarRegistry::HAK_DASHBOARD], true)) {
                    $this->assertNull($modul);

                    continue;
                }

                $this->assertTrue(Rbac::hasModule((string) $modul), "{$profil}/{$id} menunjuk modul tak dikenal");
                $this->assertContains($hak, [LayarBaca::HAK_BACA, LayarBaca::HAK_BACA_MILIK_SENDIRI, LayarBaca::HAK_AGREGAT]);
            }
        }

        foreach (BotLayarRegistry::PERINTAH as $perintah => $id) {
            $this->assertArrayHasKey($id, $ada, "/{$perintah} tidak punya tombol di matriks");
        }

        $this->assertEqualsCanonicalizing(['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'relawan.dapur', 'relawan.distribusi', 'relawan.tanpa_unit', 'investor'], array_keys(BotLayarRegistry::MENU));
    }

    public function test_registri_menolak_layar_yang_bertentangan_dengan_matriks_menu(): void
    {
        $this->expectException(LogicException::class);

        new BotLayarRegistry([...BotLayarRegistry::layarBawaan(), new LayarUji('jd', 'mutu')]);
    }

    // ------------------------------------------------------------------ matriks tombol per peran

    public function test_matriks_beranda_per_peran_sesuai_rbac(): void
    {
        $this->assertSame(
            ['Jadwal', 'Distribusi', 'Armada', 'Rekap Harian', 'Keluhan', 'Keuangan', 'Stok', 'Mutu', 'Log Produksi', 'Titik Layanan', 'Tim', 'Notifikasi Perjalanan', 'Mulai Perjalanan'],
            $this->berandaUntuk($this->kepalaA),
        );
        $this->assertSame(
            ['Menu dan Gizi', 'Persetujuan Menu', 'Mutu', 'Jadwal', 'Keluhan', 'Rekap', 'Stok', 'Penerima Manfaat', 'Log Produksi'],
            $this->berandaUntuk($this->ahliGiziA),
        );
        $this->assertSame(['Pencairan', 'Pengeluaran', 'Anggaran', 'Stok', 'Pemasok', 'Pembelian Bahan', 'Armada', 'Rekap', 'Catat pengeluaran', 'Catat pencairan'], $this->berandaUntuk($this->akuntanA));
        $this->assertSame(
            ['Stok', 'Stok Menipis', 'Kedaluwarsa', 'Pemasok', 'Pembelian Bahan', 'Menu', 'Jadwal', 'Log Produksi', 'Catat mutasi stok'],
            $this->berandaUntuk($this->stafGudangA),
        );
        $this->assertSame(['Menunggu keputusan', 'Rekap', 'Anggaran', 'Persetujuan Menu'], $this->berandaUntuk($this->investorC));
    }

    public function test_matriks_beranda_relawan_per_unit(): void
    {
        $this->assertSame(['Jadwal Produksi', 'Log Produksi', 'Menu Hari Ini', 'Mutu', 'Buat Keluhan', 'Profil Saya'], $this->berandaUntuk($this->relawanA));
        $this->assertSame(['Jadwal Distribusi', 'Bukti Distribusi', 'Mulai Perjalanan', 'Armada', 'Menu Hari Ini', 'Buat Keluhan', 'Profil Saya'], $this->berandaUntuk($this->relawanDistribusi()));
        $this->assertSame(['Menu Hari Ini', 'Mutu', 'Armada', 'Buat Keluhan', 'Profil Saya'], $this->berandaUntuk($this->relawanTanpaUnit()));
    }

    public function test_investor_banyak_sppg_pilih_dulu_baru_tombol_data_dan_admin_tanpa_tombol_tulis(): void
    {
        // Investor A+B: sebelum memilih hanya Menunggu keputusan + Pilih SPPG, tanpa ringkasan/tombol data.
        $this->assertSame(['Menunggu keputusan', 'Pilih SPPG'], $this->berandaUntuk($this->investorAB));
        $this->assertStringNotContainsString('Ringkasan hari ini', $this->teksLayar($this->investorAB));
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Pilih SPPG'));
        $this->assertStringContainsString('Pilih SPPG', $this->teksLayar($this->investorAB));
        $this->assertTrue($this->adaTombol($this->investorAB, 'SPPG A'));
        $this->assertTrue($this->adaTombol($this->investorAB, 'SPPG B'));
        $this->assertFalse($this->adaTombol($this->investorAB, 'SPPG C'), 'Hanya SPPG miliknya');
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'SPPG A'));
        $this->assertSame(['Menunggu keputusan', 'Rekap', 'Anggaran', 'Persetujuan Menu', 'Pilih SPPG'], $this->labelUtama($this->investorAB));
        $this->assertStringContainsString('Ringkasan hari ini', $this->teksLayar($this->investorAB));

        // Admin: awalnya hanya Pilih SPPG; setelah memilih tombol baca, tanpa satu pun tombol aksi tulis (b1).
        $this->assertSame(['Pilih SPPG'], $this->berandaUntuk($this->admin));
        $this->tekan($this->admin, $this->dataTombol($this->admin, 'Pilih SPPG'));
        $this->assertTrue($this->adaTombol($this->admin, 'SPPG C'), 'Admin melihat semua SPPG aktif');
        $this->tekan($this->admin, $this->dataTombol($this->admin, 'SPPG B'));
        $this->assertSame(
            ['Pilih SPPG', 'Rekap', 'Keluhan', 'Pengguna', 'Titik Layanan', 'Stok', 'Distribusi', 'Keuangan', 'Jadwal', 'Mutu'],
            $this->labelUtama($this->admin),
        );

        foreach ($this->tombolLayar((int) $this->admin->telegram_id) as $t) {
            $this->assertStringStartsNotWith('b1.', (string) ($t['callback_data'] ?? ''));
        }

        $this->assertSame(0, BotAksi::query()->where('user_id', $this->admin->id)->count(), 'Admin tidak pernah menerbitkan token aksi');
        $this->assertFalse($this->adaTombol($this->admin, 'Menunggu keputusan'));

        // Tombol data admin membuka layar SPPG yang dipilih (B), bukan SPPG lain.
        $this->tekan($this->admin, $this->dataTombol($this->admin, 'Rekap'));
        $this->assertStringStartsWith("LAYAR-rk SPPG-{$this->sppgB} ", $this->teksLayar($this->admin));
    }

    public function test_hanya_investor_punya_tombol_keputusan_dan_keputusan_tetap_lewat_menu(): void
    {
        foreach ([$this->kepalaA, $this->stafGudangA, $this->akuntanA, $this->ahliGiziA] as $user) {
            $this->berandaUntuk($user);
            $this->assertFalse($this->adaTombol($user, 'Menunggu keputusan'), $user->role->value);
        }

        $this->berandaUntuk($this->investorC);
        $this->assertStringStartsWith('b1.', $this->dataTombol($this->investorC, 'Menunggu keputusan'));
        $this->assertFalse($this->adaTombol($this->investorC, 'Pilih SPPG'), 'Satu SPPG: tanpa pemilih');
    }

    public function test_tombol_tanpa_hak_tidak_muncul_dan_mengikuti_perubahan_rbac(): void
    {
        $label = $this->berandaUntuk($this->stafGudangA);
        $this->assertNotContains('Keuangan', $label);
        $this->assertNotContains('Pengguna', $label);
        $this->assertNotContains('Keluhan', $label);

        $this->assertContains('Keluhan', $this->berandaUntuk($this->kepalaA));
        config(['rbac.modules.keluhan.kepala_sppg' => '']);
        $this->ketik($this->kepalaA, '/menu');
        $this->assertNotContains('Keluhan', $this->labelUtama($this->kepalaA));
    }

    public function test_tombol_layar_yang_belum_terdaftar_tidak_tampil(): void
    {
        $this->app->instance(BotLayarRegistry::class, new BotLayarRegistry(BotLayarRegistry::layarBawaan()));

        $this->assertSame([], $this->berandaUntuk($this->kepalaA));
        $this->assertSame([], $this->berandaUntuk($this->akuntanA));
        $this->assertSame(['Menunggu keputusan'], $this->berandaUntuk($this->investorC));
        $this->assertSame(['Pilih SPPG'], $this->berandaUntuk($this->admin), 'Layar pemilih adalah layar bawaan, tombol data tetap hilang');
    }

    // ------------------------------------------------------------------ baris tetap, kolom, edit di tempat

    public function test_baris_tetap_ada_untuk_semua_peran(): void
    {
        $semua = [$this->admin, $this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA, $this->relawanDistribusi(), $this->relawanTanpaUnit(), $this->investorAB, $this->investorC];

        foreach ($semua as $user) {
            $this->berandaUntuk($user);
            $baris = $this->markupOf($this->layar((int) $user->telegram_id))['inline_keyboard'];
            $akhir = array_slice($baris, -2);
            $nama = $user->role->value.($user->unit?->value ?? '');

            $this->assertSame(['Tanya Agen AI', 'Bantuan'], array_column($akhir[0], 'text'), $nama);
            $this->assertSame(['Segarkan', 'Buka Mini App'], array_column($akhir[1], 'text'), $nama);
            $this->assertSame('https://sigap.test/miniapp/', $akhir[1][1]['web_app']['url'], $nama);
            $this->assertSame('true', $this->layar((int) $user->telegram_id)['protect_content'], 'Pesan beranda terlindungi');

            foreach ($baris as $b) {
                $this->assertLessThanOrEqual(3, count($b));

                foreach ($b as $t) {
                    $this->assertLessThan(64, strlen((string) ($t['callback_data'] ?? '')));
                }
            }
        }
    }

    public function test_tanya_agen_ai_hanya_bila_ada_tool_untuk_peran(): void
    {
        $this->mock(ToolRegistry::class, fn (MockInterface $m) => $m->shouldReceive('forUser')->andReturn([]));

        $this->berandaUntuk($this->kepalaA);
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Tanya Agen AI'));
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Bantuan'));
    }

    public function test_tanya_agen_ai_menampilkan_petunjuk_dan_contoh_sesuai_peran(): void
    {
        $this->berandaUntuk($this->akuntanA);
        $this->tekan($this->akuntanA, $this->dataTombol($this->akuntanA, 'Tanya Agen AI'));

        $teks = $this->teksLayar($this->akuntanA);
        $this->assertStringContainsString('Tanya Agen AI', $teks);
        $this->assertStringContainsString('Pengeluaran bulan ini', $teks);
        $this->assertStringNotContainsString('titik layanan', $teks, 'Contoh hanya dari tool yang tersedia untuk akuntan');
        $this->assertLessThanOrEqual(3, substr_count($teks, "\n- "));
        $this->assertTrue($this->adaTombol($this->akuntanA, 'Kembali'));
    }

    public function test_susunan_kolom_dua_atau_tiga_menurut_panjang_label(): void
    {
        $t = fn (string ...$label) => array_map(fn (string $l) => ['text' => $l, 'callback_data' => 'x'], $label);

        $baris = BotTombol::susun($t('Jadwal', 'Mutu', 'Stok', 'Rekap', 'Armada'));
        $this->assertSame([3, 2], array_map('count', $baris));

        $baris = BotTombol::susun($t('Rekap', 'Titik Layanan', 'Mutu', 'Notifikasi Perjalanan', 'Stok'));
        $this->assertSame([2, 2, 1], array_map('count', $baris), 'Label panjang: maksimal 2 kolom');

        $this->assertSame([], BotTombol::susun([null]));

        // Beranda kepala: baris yang memuat label panjang maksimal 2 kolom.
        $this->berandaUntuk($this->kepalaA);

        foreach ($this->markupOf($this->layar((int) $this->kepalaA->telegram_id))['inline_keyboard'] as $baris) {
            $panjang = collect($baris)->contains(fn ($b) => mb_strlen($b['text']) > BotTombol::LEBAR_PENDEK);
            $this->assertLessThanOrEqual($panjang ? 2 : 3, count($baris));
        }
    }

    public function test_navigasi_diedit_di_tempat_dengan_tombol_kembali(): void
    {
        $this->berandaUntuk($this->kepalaA);
        $uid = (int) $this->kepalaA->telegram_id;
        $this->assertCount(1, $this->messagesTo($uid));

        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Bantuan'));
        $this->assertStringContainsString('Cara memakai SiGAP SPPG', $this->teksLayar($this->kepalaA));
        $this->assertSame(1, $this->telegramCalls('editMessageText')->count());

        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Kembali'));
        $this->assertStringContainsString('Menu SiGAP', $this->teksLayar($this->kepalaA));
        $this->assertSame(2, $this->telegramCalls('editMessageText')->count());
        $this->assertCount(1, $this->messagesTo($uid), 'Tidak ada pesan baru: semua diedit di tempat');
    }

    public function test_segarkan_beranda_mengedit_dan_tanpa_perubahan_tidak_menggandakan_pesan(): void
    {
        $this->berandaUntuk($this->kepalaA);
        $uid = (int) $this->kepalaA->telegram_id;

        $segarkan = $this->dataTombol($this->kepalaA, 'Segarkan');
        $this->tekan($this->kepalaA, $segarkan);
        $this->assertSame(1, $this->telegramCalls('editMessageText')->count());

        $this->fakeTelegram(['api.telegram.org/*/editMessageText' => Http::response([
            'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same',
        ], 400)]);
        $this->tekan($this->kepalaA, $segarkan);

        $this->assertSame(BotCallbackHandler::SUDAH_TERBARU, $this->jawabanTerakhir());
        $this->assertCount(0, $this->messagesTo($uid), 'Tidak mengirim pesan baru');
    }

    public function test_menu_tidak_memuat_nama_pengguna_atau_data_pribadi(): void
    {
        $this->berandaUntuk($this->kepalaA);

        $teks = $this->teksLayar($this->kepalaA).json_encode($this->tombolLayar((int) $this->kepalaA->telegram_id));
        $this->assertStringNotContainsString((string) $this->kepalaA->name, $teks);
        $this->assertStringContainsString('SPPG A', $teks, 'Nama SPPG sendiri boleh tampil di beranda');
        $this->assertStringNotContainsString('SPPG B', $teks);
    }

    // ------------------------------------------------------------------ tanpa sesi penuh

    public function test_menu_tanpa_sesi_penuh_hanya_petunjuk_masuk(): void
    {
        $this->ketik($this->investorAB, '/menu');

        $balasan = $this->messagesTo((int) $this->investorAB->telegram_id)->sole();
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $balasan['text']);
        $tombol = $this->tombolLayar((int) $this->investorAB->telegram_id);
        $this->assertCount(1, $tombol);
        $this->assertSame('Masuk lewat Mini App', $tombol[0]['text']);
        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_menu_setelah_sesi_dicabut_kembali_petunjuk_masuk(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $this->ketik($this->akuntanA, '/menu');
        $this->assertNotEmpty($this->labelUtama($this->akuntanA));

        app(SesiService::class)->keluarSemua($this->akuntanA);
        $this->ketik($this->akuntanA, '/menu');

        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->layar((int) $this->akuntanA->telegram_id)['text']);
        $this->assertSame(['Masuk lewat Mini App'], array_column($this->tombolLayar((int) $this->akuntanA->telegram_id), 'text'));
    }

    public function test_start_dan_bantuan_tanpa_sesi_hanya_tombol_masuk_dan_dengan_sesi_bantuan_bertombol_kembali(): void
    {
        $uid = (int) $this->ahliGiziA->telegram_id;

        $this->ketik($this->ahliGiziA, '/start');
        $this->ketik($this->ahliGiziA, '/bantuan');
        foreach ($this->messagesTo($uid) as $kirim) {
            $this->assertCount(1, $this->markupOf($kirim)['inline_keyboard'], 'tanpa sesi: satu baris (Masuk lewat Mini App)');
        }

        $this->sesiPenuh($this->ahliGiziA);
        $this->ketik($this->ahliGiziA, '/bantuan');
        $this->assertStringContainsString('Cara memakai SiGAP SPPG', $this->teksLayar($this->ahliGiziA));
        $this->assertTrue($this->adaTombol($this->ahliGiziA, 'Kembali'));
        $this->assertSame('true', $this->layar($uid)['protect_content']);
    }

    public function test_start_dengan_sesi_menyambut_dengan_ringkasan_dan_beranda(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->ketik($this->kepalaA, '/start');

        $teks = $this->teksLayar($this->kepalaA);
        $this->assertStringStartsWith('Selamat datang kembali, <b>', $teks);
        $this->assertStringContainsString('Kepala SPPG', $teks);
        $this->assertStringContainsString('Ringkasan hari ini', $teks);
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Jadwal'));
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Buka Mini App'));
        $this->assertSame('true', $this->layar((int) $this->kepalaA->telegram_id)['protect_content']);
    }

    public function test_pengguna_nonaktif_dan_tidak_terdaftar_tidak_mendapat_menu(): void
    {
        $this->forgetActor();
        $nonaktif = User::factory()->role(Role::StafGudang)->inactive()->create(['sppg_id' => $this->sppgA]);

        $this->ketik($nonaktif, '/menu');
        $this->assertStringContainsString('nonaktif', (string) $this->layar((int) $nonaktif->telegram_id)['text']);
        $this->assertSame([], $this->labelUtama($nonaktif));

        $this->kirim($this->privateUpdate(999777, ['text' => '/menu']));
        $this->assertStringContainsString('belum terdaftar', (string) $this->layar(999777)['text']);
        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_menu_di_grup_diabaikan(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->kirim($this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => '/menu'], 'group'));

        $this->assertCount(0, $this->telegramCalls('sendMessage'));
    }
}

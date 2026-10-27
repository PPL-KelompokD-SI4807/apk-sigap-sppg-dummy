<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\Role;
use App\Models\BotAksi;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavigasi;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\Bot\Layar\LayarAksi;
use App\Services\Telegram\Bot\Layar\LayarAlurDraf;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Alur draf di bot (R-02 PR-3, C2): tombol/petunjuk "Catat pengeluaran", "Catat pencairan", "Catat mutasi stok",
 * "Buat Keluhan". Bot TIDAK menulis data; hanya petunjuk baca-saja + contoh kalimat + tombol "Buka formulir" (web_app ke
 * rute formulir Mini App yang sudah ada). Konfirmasi draf tetap di Mini App.
 */
class BotAlurDrafTest extends LayarBacaTestCase
{
    /** @var array<string, array{0: string, 1: list<string>}> id layar => [label tombol beranda, profil pengguna yang boleh] */
    private const ALUR = [
        'dpk' => ['Catat pengeluaran', ['akuntanA']],
        'dpc' => ['Catat pencairan', ['akuntanA']],
        'dms' => ['Catat mutasi stok', ['stafGudangA']],
        'dkl' => ['Buat Keluhan', ['relawanA', 'relawanDistribusi', 'relawanTanpaUnit']],
    ];

    private const PERAN = ['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorAB', 'investorC'];

    /** @return iterable<string, array{string}> */
    public static function peran(): iterable
    {
        foreach (self::PERAN as $p) {
            yield $p => [$p];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->isiData($this->sppgA, 'Alfa');
        $this->isiData($this->sppgB, 'Beta');
    }

    // ------------------------------------------------------------------ matriks tombol

    #[DataProvider('peran')]
    public function test_matriks_tombol_beranda_dan_layar_per_peran(string $peran): void
    {
        $user = $this->pengguna($peran);
        $this->sesiPenuh($user);
        $sppg = $this->sppgDari($peran);

        foreach (self::ALUR as $id => [$label, $boleh]) {
            $this->ketik($user, '/menu');

            if ($peran === 'investorAB' || $user->isAdmin()) {
                $this->tekan($user, $this->dataTombol($user, 'Pilih SPPG'));
                $this->tekan($user, $this->dataTombol($user, 'SPPG A'));
            }

            $ada = in_array($peran, $boleh, true);
            $this->assertSame($ada, $this->adaTombol($user, $label), "{$peran}: tombol '{$label}' di beranda");

            $teks = $this->buka($user, $id, $sppg);
            $ada ? $this->assertNotNull($teks, "{$peran} harus bisa membuka {$id}") : $this->assertNull($teks, "{$peran} TIDAK boleh membuka {$id}");
        }
    }

    public function test_relawan_hanya_buat_keluhan_dan_hanya_bila_rbac_mengizinkan(): void
    {
        foreach (['relawanA', 'relawanDistribusi', 'relawanTanpaUnit'] as $peran) {
            $this->assertTrue((new LayarAlurDraf('dkl'))->tampilUntuk($this->pengguna($peran)), $peran);
        }

        // Hak C keluhan relawan dicabut di matriks RBAC saat ini -> tombol hilang dan layar ditolak.
        config(['rbac.modules.keluhan.relawan' => 'R']);
        $this->sesiPenuh($this->relawanA);
        $this->ketik($this->relawanA, '/menu');

        $this->assertFalse($this->adaTombol($this->relawanA, 'Buat Keluhan'));
        $this->assertNull($this->buka($this->relawanA, 'dkl'));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
    }

    public function test_tool_tulis_tidak_tersedia_menghilangkan_tombol_dan_menolak_layar(): void
    {
        $this->sesiPenuh($this->akuntanA, $this->stafGudangA, $this->relawanA);

        // Setiap tool tulis dimatikan satu per satu (ai.tools.disabled); tombol terkait hilang, lainnya tetap.
        $tool = ['dpk' => 'buat_pengeluaran', 'dpc' => 'buat_pencairan_dana', 'dms' => 'catat_mutasi_stok', 'dkl' => 'buat_keluhan'];
        $pemilik = ['dpk' => $this->akuntanA, 'dpc' => $this->akuntanA, 'dms' => $this->stafGudangA, 'dkl' => $this->relawanA];

        foreach ($tool as $id => $nama) {
            $this->assertArrayHasKey($nama, app(ToolRegistry::class)->forUser($pemilik[$id]), "tool {$nama} tersedia secara bawaan");
            $this->assertSame($nama, (new LayarAlurDraf($id))->namaTool());
        }

        foreach ($tool as $id => $nama) {
            config(['ai.tools.disabled' => [$nama]]);
            app()->forgetInstance(ToolRegistry::class);
            $user = $pemilik[$id];

            $this->assertArrayNotHasKey($nama, app(ToolRegistry::class)->forUser($user));
            $this->ketik($user, '/menu');
            $this->assertFalse($this->adaTombol($user, self::ALUR[$id][0]), "{$id}: tombol hilang bila tool tulis tak tersedia");
            $this->assertNull($this->buka($user, $id), "{$id}: layar ditolak");
        }

        config(['ai.tools.disabled' => []]);
        app()->forgetInstance(ToolRegistry::class);
        $this->ketik($this->akuntanA, '/menu');
        $this->assertTrue($this->adaTombol($this->akuntanA, 'Catat pengeluaran'));
    }

    // ------------------------------------------------------------------ isi petunjuk

    public function test_petunjuk_berisi_contoh_kalimat_tanpa_data_bisnis_dan_tanpa_nama_sppg(): void
    {
        $pengguna = ['dpk' => $this->akuntanA, 'dpc' => $this->akuntanA, 'dms' => $this->stafGudangA, 'dkl' => $this->relawanA];
        $this->sesiPenuh($this->akuntanA, $this->stafGudangA, $this->relawanA);
        $dilarang = ['SPPG A', 'SPPG B', 'Alfa', 'Beta', 'URAIAN-RAHASIA', 'BATCH-', 'Dana Alfa', 'SD ', 'B 1234', 'Siti', $this->akuntanA->name, $this->stafGudangA->name, $this->relawanA->name];

        foreach ($pengguna as $id => $user) {
            $teks = (string) $this->buka($user, $id);
            $this->assertNotSame('', $teks, $id);
            $this->assertStringContainsString('draf', $teks);
            $this->assertStringContainsString('Periksa &amp; Simpan', $teks);
            $this->assertStringContainsString('Contoh:', $teks);

            foreach (LayarAlurDraf::ALUR[$id]['contoh'] as $contoh) {
                $this->assertStringContainsString(htmlspecialchars($contoh, ENT_QUOTES), $teks, $id);
            }

            foreach ($dilarang as $s) {
                $this->assertStringNotContainsString((string) $s, $teks, "{$id}: memuat '{$s}'");
            }

            $this->assertTanpaTerlarang($teks, $id);
            // Judul tanpa nama SPPG (susun namaSppg: false): hanya judul layar.
            $this->assertMatchesRegularExpression('/^<b>[^<]+<\/b>\n/', $teks, $id);
        }
    }

    public function test_layar_menyaring_html_dan_dilindungi_dari_forward(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $hasil = app(BotNavigasi::class)->buka($this->akuntanA, 'dpk', $this->sppgA, new KonteksLayar((int) $this->akuntanA->telegram_id, (int) $this->akuntanA->telegram_id));

        $this->assertTrue($hasil->berhasil());
        $this->assertTrue($hasil->layar->lindungi, 'protect_content');
        $this->assertTrue($hasil->layar->html);
        // Semua tanda < > di luar tag <b>/<i> harus ter-escape: tidak ada tag lain.
        $this->assertSame([], array_diff(array_unique(array_map('strtolower', array_column($this->tag($hasil->layar->teks), 0))), ['<b>', '</b>', '<i>', '</i>']));
    }

    /** @return list<array{0: string}> */
    private function tag(string $teks): array
    {
        preg_match_all('/<[^>]*>/', $teks, $m, PREG_SET_ORDER);

        return $m;
    }

    // ------------------------------------------------------------------ tombol "Buka formulir"

    public function test_tombol_buka_formulir_memakai_rute_formulir_mini_app_yang_terdaftar(): void
    {
        $this->sesiPenuh($this->akuntanA, $this->stafGudangA, $this->relawanA);
        $pengguna = ['dpk' => $this->akuntanA, 'dpc' => $this->akuntanA, 'dms' => $this->stafGudangA, 'dkl' => $this->relawanA];
        $pola = '/^\/[a-z0-9\/_-]{1,80}$/'; // POLA_AMAN resources/miniapp/core/deeplink.js
        $this->assertStringContainsString('POLA_AMAN = /^\/[a-z0-9/_-]{1,80}$/', $this->sumber('core/deeplink.js'));

        foreach ($pengguna as $id => $user) {
            $this->buka($user, $id);
            $web = collect($this->tombolLayar((int) $user->telegram_id))->first(fn (array $t) => isset($t['web_app']));
            $rute = LayarAlurDraf::ALUR[$id]['rute'];

            if ($rute === null) {
                $this->assertNull($web, "{$id}: belum ada rute formulir -> hanya petunjuk");

                continue;
            }

            $this->assertNotNull($web, "{$id}: tombol Buka formulir");
            $this->assertSame('Buka formulir', $web['text']);
            $this->assertMatchesRegularExpression($pola, $rute);
            $this->assertSame('https://sigap.test/miniapp/?'.http_build_query(['r' => $rute]).'#'.$rute, $web['web_app']['url']);
            $this->assertTrue($this->ruteTerdaftar($rute), "{$id}: rute {$rute} tidak terdaftar di Mini App");
        }
    }

    public function test_rute_formulir_mutasi_stok_belum_ada_tanpa_id_sehingga_hanya_petunjuk(): void
    {
        // Satu-satunya rute mutasi di Mini App membutuhkan :id bahan baku, jadi tidak dapat dibuka via ?r= dari bot.
        // Kebutuhan frontend R02-D: rute formulir mutasi tanpa id (mis. pemilih bahan) bila ingin tombol Buka formulir.
        $this->assertNull(LayarAlurDraf::ALUR['dms']['rute']);
        $this->assertStringContainsString("route('/bahan-baku/:id/mutasi'", $this->sumber('modules/bahan-baku/index.js'));
        $this->assertFalse($this->ruteTerdaftar('/bahan-baku/mutasi/baru'));
    }

    public function test_helper_rute_terdaftar_menolak_rute_yang_tidak_ada(): void
    {
        $this->assertFalse($this->ruteTerdaftar('/keuangan/tidak-ada/baru'));
        $this->assertFalse($this->ruteTerdaftar('/pengeluaran/baru'));
    }

    private function sumber(string $berkas): string
    {
        return (string) file_get_contents(base_path('resources/miniapp/'.$berkas));
    }

    /** Rute formulir `<slug>/baru` modul CRUD (defineCrudModule mendaftarkan `${base}/baru`) terdaftar di sumber Mini App. */
    private function ruteTerdaftar(string $rute): bool
    {
        if (! preg_match('#^/(.+)/baru$#', $rute, $m)) {
            return false;
        }

        $slug = $m[1];
        $crud = $this->sumber('components/crud-module.js');

        if (! str_contains($crud, 'route(`${base}/baru`') || ! str_contains($crud, 'const base = `/${slug}`')) {
            return false;
        }

        foreach (glob(base_path('resources/miniapp/modules/*/index.js')) ?: [] as $berkas) {
            $isi = (string) file_get_contents($berkas);

            if (str_contains($isi, 'defineCrudModule') && preg_match("/slug:\\s*'".preg_quote($slug, '/')."'/", $isi) === 1) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ hak dicek ulang tiap klik

    public function test_klik_lintas_peran_ditolak_tanpa_data(): void
    {
        $kasus = [
            ['admin', 'dpk'], ['admin', 'dkl'], ['kepalaA', 'dpk'], ['kepalaA', 'dkl'], ['kepalaA', 'dms'], ['ahliGiziA', 'dkl'],
            ['akuntanA', 'dms'], ['akuntanA', 'dkl'], ['stafGudangA', 'dpk'], ['stafGudangA', 'dpc'], ['stafGudangA', 'dkl'],
            ['relawanA', 'dpk'], ['relawanA', 'dpc'], ['relawanA', 'dms'], ['investorAB', 'dpk'], ['investorAB', 'dkl'], ['investorC', 'dkl'],
        ];

        foreach ($kasus as [$peran, $id]) {
            $user = $this->pengguna($peran);
            $this->sesiPenuh($user);
            $this->assertNull($this->buka($user, $id, $this->sppgDari($peran)), "{$peran} x {$id}");
            $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir(), "{$peran} x {$id}");
            $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_HAK, $this->teksLayar($user), "{$peran} x {$id}");
        }

        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_klik_lintas_sppg_ditolak(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $this->assertNull($this->buka($this->akuntanA, 'dpk', $this->sppgB));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
    }

    public function test_peran_dicabut_di_antara_render_dan_klik_edit_netral(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $data = $this->n1($this->akuntanA, 'dpk', $this->sppgA);
        $this->tekan($this->akuntanA, $data);
        $this->assertStringContainsString('Catat Pengeluaran', $this->teksLayar($this->akuntanA));

        // Peran berubah langsung di basis data (sesi masih utuh): klik ulang dihitung dari peran SAAT INI.
        DB::table('users')->where('id', $this->akuntanA->id)->update(['role' => Role::AhliGizi->value]);
        $this->assertTrue(app(SesiService::class)->punyaSesiPenuh($this->akuntanA->fresh()));
        $this->tekan($this->akuntanA, $data);

        $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_HAK, $this->teksLayar($this->akuntanA));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame([], $this->tombolLayar((int) $this->akuntanA->telegram_id));
    }

    public function test_hak_c_dicabut_di_matriks_rbac_antara_render_dan_klik_edit_netral(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $data = $this->n1($this->akuntanA, 'dpc', $this->sppgA);
        $this->tekan($this->akuntanA, $data);
        $this->assertStringContainsString('Catat Pencairan', $this->teksLayar($this->akuntanA));

        config(['rbac.modules.keuangan.akuntan' => 'R']);
        $this->tekan($this->akuntanA, $data);

        $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_HAK, $this->teksLayar($this->akuntanA));
        $this->assertSame([], $this->tombolLayar((int) $this->akuntanA->telegram_id));
    }

    public function test_pengguna_nonaktif_dijawab_seragam(): void
    {
        $this->sesiPenuh($this->stafGudangA);
        $data = $this->n1($this->stafGudangA, 'dms', $this->sppgA);
        DB::table('users')->where('id', $this->stafGudangA->id)->update(['is_active' => false]);

        $this->tekan($this->stafGudangA, $data);

        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame(0, $this->telegramCalls('editMessageText')->count());
    }

    // ------------------------------------------------------------------ sesi tidak penuh (aturan 7)

    #[DataProvider('peran')]
    public function test_tanpa_sesi_penuh_hanya_tombol_masuk_untuk_semua_layar_alur_draf(string $peran): void
    {
        $user = $this->pengguna($peran);
        $uid = (int) $user->telegram_id;
        $sppg = $this->sppgDari($peran);

        foreach (['tanpa_token', 'dicabut'] as $varian) {
            if ($varian === 'dicabut') {
                $this->sesiPenuh($user);
                $token = $this->n1($user, 'dpk', $sppg);
                app(SesiService::class)->keluarSemua($user);
            }

            $this->assertFalse(app(SesiService::class)->punyaSesiPenuh($user->fresh()));

            foreach (LayarAlurDraf::semuaId() as $id) {
                $this->tekan($user, $this->n1($user, $id, $sppg));
                $this->tekan($user, $this->n1($user, $id, $sppg));
            }

            if ($varian === 'dicabut') {
                $this->tekan($user, $token);
            }
        }

        $metode = collect(Http::recorded())->map(fn (array $p) => basename((string) parse_url($p[0]->url(), PHP_URL_PATH)))->unique()->values()->all();
        $this->assertEmpty(array_diff($metode, ['sendMessage', 'answerCallbackQuery', 'editMessageText']), implode(',', $metode));

        foreach ($this->telegramCalls('editMessageText') as $edit) {
            $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_SESI, $edit['text']);
            $this->assertSame([], $this->markupOf($edit)['inline_keyboard'] ?? []);
        }

        $pesan = $this->messagesTo($uid);
        $this->assertNotEmpty($pesan);
        $pesan->each(function ($p): void {
            $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $p['text']);
            $this->assertSame([[['text' => TelegramUpdateHandler::LABEL_MASUK, 'web_app' => ['url' => 'https://sigap.test/miniapp/']]]], $this->markupOf($p)['inline_keyboard']);
        });
        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_tanpa_sesi_penuh_beranda_dan_menu_tidak_menampilkan_tombol_alur_draf(): void
    {
        foreach (['akuntanA', 'stafGudangA', 'relawanA'] as $peran) {
            $user = $this->pengguna($peran);
            $this->ketik($user, '/menu');

            foreach (self::ALUR as [$label]) {
                $this->assertFalse($this->adaTombol($user, $label), "{$peran}: {$label}");
            }
        }
    }

    // ------------------------------------------------------------------ tanpa penulisan

    public function test_klik_alur_draf_tidak_menulis_apa_pun(): void
    {
        $semuaTabel = fn (): array => collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
            ->mapWithKeys(fn ($t) => [$t->name => DB::table($t->name)->count()])->all();
        $distribusi = $this->relawanDistribusi();
        $this->sesiPenuh($this->akuntanA, $this->stafGudangA, $this->relawanA, $distribusi);

        Queue::fake();
        Bus::fake();
        Notification::fake();
        Mail::fake();
        $events = array_map(fn (string $f) => 'App\\Events\\'.basename($f, '.php'), glob(app_path('Events/*.php')) ?: []);
        $this->assertNotEmpty($events);
        Event::fake($events);

        $nav = app(BotNavigasi::class);
        $sebelum = $semuaTabel();

        foreach ([[$this->akuntanA, ['dpk', 'dpc']], [$this->stafGudangA, ['dms']], [$this->relawanA, ['dkl']], [$distribusi, ['dkl']]] as [$user, $ids]) {
            foreach ($ids as $id) {
                for ($i = 0; $i < 2; $i++) { // klik ulang / Segarkan
                    $hasil = $nav->buka($user, $id, $user->sppg_id, new KonteksLayar((int) $user->telegram_id, (int) $user->telegram_id));
                    $this->assertTrue($hasil->berhasil(), "{$user->role->value}/{$id}");
                }
            }
        }

        $this->assertSame($sebelum, $semuaTabel(), 'Tidak ada perubahan tabel mana pun (domain, ai_drafts, audit, bot_aksi)');
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Event::assertNothingDispatched();
    }

    public function test_layar_alur_draf_tidak_menerbitkan_tombol_aksi_b1_atau_menyentuh_draf(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $this->buka($this->akuntanA, 'dpk');

        foreach ($this->tombolLayar((int) $this->akuntanA->telegram_id) as $t) {
            $this->assertStringStartsNotWith('b1.', (string) ($t['callback_data'] ?? ''));
            $this->assertArrayNotHasKey('url', $t);
        }

        $this->assertSame(0, BotAksi::query()->count());
        $this->assertSame(0, DB::table('ai_drafts')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('sumber', 'bot')->count());
    }

    // ------------------------------------------------------------------ registri

    public function test_layar_alur_draf_adalah_layar_aksi_dan_terdaftar_sesuai_matriks(): void
    {
        $registri = app(BotLayarRegistry::class);

        foreach (LayarAlurDraf::semuaId() as $id) {
            $layar = $registri->cari($id);
            $this->assertInstanceOf(LayarAksi::class, $layar);
            $this->assertInstanceOf(LayarAlurDraf::class, $layar);
            $this->assertSame('R', $layar->hak());
        }
    }

    public function test_alur_draf_tidak_dikenal_ditolak(): void
    {
        $this->expectException(\LogicException::class);

        new LayarAlurDraf('zzz');
    }
}

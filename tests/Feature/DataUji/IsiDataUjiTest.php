<?php

namespace Tests\Feature\DataUji;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\BahanBaku;
use App\Models\FeedbackKeluhan;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\DataUji\PengisiDataUji;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IsiDataUjiTest extends TestCase
{
    use RefreshDatabase;

    private const KODE = 'SPPG-UJI-01';

    private function sppgUjiId(): int
    {
        return (int) DB::table('sppgs')->where('kode', self::KODE)->value('id');
    }

    /** @return array<string, int> */
    private function hitungSemuaTabel(): array
    {
        $hasil = [];

        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'migrations'") as $t) {
            $hasil[$t->name] = DB::table($t->name)->count();
        }

        return $hasil;
    }

    public function test_mode_kering_tidak_menulis_apa_pun(): void
    {
        $sebelum = $this->hitungSemuaTabel();

        $this->artisan('sigap:isi-data-uji')
            ->expectsOutputToContain('MODE KERING')
            ->assertSuccessful();

        $this->assertSame($sebelum, $this->hitungSemuaTabel());
        $this->assertSame(0, Sppg::query()->count());
    }

    public function test_isi_dengan_confirm_membuat_data_koheren_tanpa_pengguna_dan_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $penggunaSebelum = User::query()->withoutGlobalScopes()->count();
        $auditSebelum = AuditLog::query()->withoutGlobalScopes()->count();

        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();

        $id = $this->sppgUjiId();
        $this->assertGreaterThan(0, $id);
        $this->assertStringEndsWith('(Uji)', (string) DB::table('sppgs')->where('id', $id)->value('nama'));

        $jumlah = fn (string $tabel): int => DB::table($tabel)->where('sppg_id', $id)->count();
        $this->assertSame(3, $jumlah('titik_layanans'));
        $this->assertSame(18, $jumlah('penerima_manfaats'));
        $this->assertSame(2, $jumlah('pemasoks'));
        $this->assertSame(10, $jumlah('bahan_bakus'));
        $this->assertSame(3, $jumlah('menus'));
        $this->assertSame(3, $jumlah('standar_gizis'));
        $this->assertSame(3, $jumlah('armadas'));
        $this->assertSame(3, $jumlah('pengemudis'));
        $this->assertSame(7, $jumlah('jadwal_produksis'));
        $this->assertSame(7, $jumlah('log_produksis'));
        $this->assertSame(21, $jumlah('jadwal_distribusis'));
        $this->assertSame(18, $jumlah('bukti_distribusis'));
        $this->assertSame(6, $jumlah('rekap_harians'));
        $this->assertSame(3, $jumlah('feedback_keluhans'));
        $this->assertSame(1, $jumlah('pengajuan_menus'));

        // Stok minimum, kedaluwarsa, kritis.
        $this->assertSame(2, BahanBaku::query()->withoutGlobalScopes()->where('sppg_id', $id)->whereColumn('stok', '<=', 'stok_minimum')->count());
        $this->assertGreaterThanOrEqual(2, BahanBaku::query()->withoutGlobalScopes()->where('sppg_id', $id)
            ->whereBetween('kedaluwarsa_terdekat', [now()->toDateString(), now()->addDays(3)->toDateString()])->count());
        $this->assertSame(1, FeedbackKeluhan::query()->withoutGlobalScopes()->where('sppg_id', $id)->where('prioritas', 'kritis')->where('status', 'baru')->count());

        // Saldo mutasi sama dengan stok tercatat.
        foreach (DB::table('bahan_bakus')->where('sppg_id', $id)->get() as $b) {
            $saldo = (float) DB::table('mutasi_stoks')->where('bahan_baku_id', $b->id)->where('jenis', 'masuk')->sum('jumlah')
                - (float) DB::table('mutasi_stoks')->where('bahan_baku_id', $b->id)->where('jenis', 'keluar')->sum('jumlah');
            $this->assertEqualsWithDelta($saldo, (float) $b->stok, 0.001, $b->nama);
        }

        // Anggaran: pengeluaran disetujui > 80% dari pencairan dan belum 100%; ada yang menunggu; tanpa nota.
        $pencairan = (float) DB::table('pencairan_danas')->where('sppg_id', $id)->sum('nominal');
        $disetujui = (float) Pengeluaran::query()->withoutGlobalScopes()->where('sppg_id', $id)->where('status_persetujuan', 'disetujui')->sum('nominal');
        $this->assertGreaterThan(0.8 * $pencairan, $disetujui);
        $this->assertLessThan($pencairan, $disetujui);
        $this->assertSame(1, Pengeluaran::query()->withoutGlobalScopes()->where('sppg_id', $id)->where('status_persetujuan', 'diajukan')->count());
        $this->assertSame(0, DB::table('pengeluarans')->where('sppg_id', $id)->whereNotNull('nota_path')->count());
        $this->assertSame(0, DB::table('peringatan_anggarans')->where('sppg_id', $id)->count());

        // Hari ini: distribusi tiga status dan notifikasi perjalanan berjalan dengan estimasi tiba.
        $hariIni = now(config('operasional.timezone'))->toDateString();
        $this->assertSame(3, DB::table('jadwal_distribusis')->where('sppg_id', $id)->where('tanggal_distribusi', $hariIni)->count());
        $this->assertSame(1, DB::table('notifikasi_perjalanans')->where('sppg_id', $id)->where('status_perjalanan', 'dalam_perjalanan')->whereNotNull('estimasi_tiba')->count());

        // Nama, telepon, tanpa NIK, tanpa pengguna/ID Telegram palsu, tanpa audit palsu.
        foreach (['titik_layanans' => 'nama', 'pemasoks' => 'nama', 'pengemudis' => 'nama', 'menus' => 'nama_menu', 'penerima_manfaats' => 'nama'] as $tabel => $kolom) {
            foreach (DB::table($tabel)->where('sppg_id', $id)->pluck($kolom) as $nama) {
                $this->assertStringEndsWith('(Uji)', $nama, $tabel);
            }
        }
        $this->assertSame(0, DB::table('pemasoks')->where('sppg_id', $id)->whereNotNull('kontak')->where('kontak', 'not like', '0812-0000-%')->count());
        $this->assertSame(0, DB::table('titik_layanans')->where('sppg_id', $id)->whereNotNull('pic_telegram_chat_id')->count());
        $this->assertSame($penggunaSebelum, User::query()->withoutGlobalScopes()->count());
        $this->assertSame($auditSebelum, AuditLog::query()->withoutGlobalScopes()->count());
        $this->assertSame($admin->getKey(), User::query()->withoutGlobalScopes()->sole()->getKey());
    }

    public function test_hitungan_mode_kering_sama_dengan_hasil_penulisan(): void
    {
        $rencana = app(PengisiDataUji::class)->isi(false);
        $this->assertSame(0, Sppg::query()->count());

        $nyata = app(PengisiDataUji::class)->isi(true);

        $this->assertSame($rencana, $nyata);
        $this->assertSame($nyata['bahan_bakus'], DB::table('bahan_bakus')->count());
    }

    public function test_idempoten_dijalankan_dua_kali_tidak_menggandakan(): void
    {
        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();
        $setelahPertama = $this->hitungSemuaTabel();

        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])
            ->expectsOutputToContain('sudah ada; dilewati')
            ->assertSuccessful();

        $this->assertSame($setelahPertama, $this->hitungSemuaTabel());
        $this->assertSame(1, Sppg::query()->where('kode', self::KODE)->count());
    }

    public function test_hapus_tanpa_confirm_hanya_menghitung(): void
    {
        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();
        $sebelum = $this->hitungSemuaTabel();

        $this->artisan('sigap:isi-data-uji', ['--hapus' => true])
            ->expectsOutputToContain('MODE KERING')
            ->assertSuccessful();

        $this->assertSame($sebelum, $this->hitungSemuaTabel());
    }

    public function test_hapus_dengan_confirm_menghapus_hanya_data_uji(): void
    {
        $admin = User::factory()->admin()->create();
        $lain = Sppg::factory()->create();
        $kepalaLain = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $lain->id]);
        BahanBaku::factory()->create(['sppg_id' => $lain->id]);
        Pengeluaran::factory()->create(['sppg_id' => $lain->id]);
        FeedbackKeluhan::factory()->create(['sppg_id' => $lain->id]);

        $sebelumUji = $this->hitungSemuaTabel();
        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();
        $this->assertNotSame($sebelumUji, $this->hitungSemuaTabel());

        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--confirm' => true])->assertSuccessful();

        // Seluruh tabel kembali persis seperti sebelum pengisian (data non-uji utuh, termasuk pengguna).
        $this->assertSame($sebelumUji, $this->hitungSemuaTabel());
        $this->assertSame(0, DB::table('sppgs')->where('kode', self::KODE)->count());
        $this->assertNotNull(User::query()->withoutGlobalScopes()->find($admin->getKey()));
        $this->assertNotNull(User::query()->withoutGlobalScopes()->find($kepalaLain->getKey()));
        $this->assertSame($lain->id, (int) User::query()->withoutGlobalScopes()->find($kepalaLain->getKey())->sppg_id);
        $this->assertSame(1, DB::table('bahan_bakus')->where('sppg_id', $lain->id)->count());

        // Dapat diisi ulang setelah dihapus.
        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();
        $this->assertSame(1, Sppg::query()->where('kode', self::KODE)->count());
    }

    public function test_hapus_menolak_bila_masih_ada_pengguna_tertaut_kecuali_dilepas(): void
    {
        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();
        $id = $this->sppgUjiId();
        $penguji = User::factory()->role(Role::Akuntan)->create(['sppg_id' => $id]);
        $this->beriSesiPenuh($penguji);
        $lain = User::factory()->role(Role::Akuntan)->create(['sppg_id' => Sppg::factory()->create()->id]);
        $this->beriSesiPenuh($lain);

        $sebelum = $this->hitungSemuaTabel();
        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--confirm' => true])
            ->expectsOutputToContain('Masih ada 1 pengguna')
            ->assertFailed();
        $this->assertSame($sebelum, $this->hitungSemuaTabel());

        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--confirm' => true, '--lepas-pengguna' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('sppgs')->where('kode', self::KODE)->count());
        $akun = User::query()->withoutGlobalScopes()->find($penguji->getKey());
        $this->assertNotNull($akun);
        $this->assertNull($akun->sppg_id);
        // sppg_id dilepas lewat query builder: sesi lama tetap dicabut seketika dan tercatat di audit.
        $this->assertSame(0, $akun->tokens()->count());
        $audit = DB::table('audit_logs')->where('event', 'auth.sesi_dicabut_otomatis')->where('auditable_id', $penguji->id)->sole();
        $this->assertSame(['pemicu' => 'sppg_uji_dihapus', 'jumlah_token' => 1, 'kolom' => ['sppg_id']], json_decode($audit->new_values, true));
        // Akun SPPG lain tidak tersentuh.
        $this->assertSame(1, $lain->tokens()->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'auth.sesi_dicabut_otomatis')->where('auditable_id', $lain->id)->count());
    }

    public function test_hapus_menolak_sppg_berkode_uji_tanpa_sufiks_nama(): void
    {
        Sppg::factory()->create(['kode' => self::KODE, 'nama' => 'SPPG Sungguhan']);
        $sebelum = $this->hitungSemuaTabel();

        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--confirm' => true])
            ->expectsOutputToContain('bukan data uji')
            ->assertFailed();

        $this->assertSame($sebelum, $this->hitungSemuaTabel());
    }

    public function test_produksi_ditolak_kecuali_izinkan_produksi(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])
            ->expectsOutputToContain('APP_ENV=production')
            ->assertFailed();
        $this->assertSame(0, Sppg::query()->count());

        // Mode kering tetap boleh di produksi.
        $this->artisan('sigap:isi-data-uji')->assertSuccessful();
        $this->assertSame(0, Sppg::query()->count());

        $this->artisan('sigap:isi-data-uji', ['--confirm' => true, '--izinkan-produksi' => true])->assertSuccessful();
        $this->assertSame(1, Sppg::query()->where('kode', self::KODE)->count());

        // Hapus di produksi juga wajib --izinkan-produksi (selain --confirm).
        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--confirm' => true])
            ->expectsOutputToContain('--izinkan-produksi')
            ->assertFailed();
        $this->assertSame(1, Sppg::query()->where('kode', self::KODE)->count());

        // --izinkan-produksi tanpa --confirm tetap mode kering.
        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--izinkan-produksi' => true])->assertSuccessful();
        $this->assertSame(1, Sppg::query()->where('kode', self::KODE)->count());

        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--confirm' => true, '--izinkan-produksi' => true])->assertSuccessful();
        $this->assertSame(0, Sppg::query()->where('kode', self::KODE)->count());
    }

    public function test_di_luar_produksi_tidak_perlu_izinkan_produksi(): void
    {
        $this->assertFalse($this->app->environment('production'));

        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();
        $this->artisan('sigap:isi-data-uji', ['--hapus' => true, '--confirm' => true])->assertSuccessful();
    }

    public function test_dashboard_memuat_angka_data_uji_untuk_admin_dan_kepala(): void
    {
        $this->artisan('sigap:isi-data-uji', ['--confirm' => true])->assertSuccessful();
        $id = $this->sppgUjiId();

        Sanctum::actingAs(User::factory()->admin()->create(), [SesiService::ABILITY_PENUH]);
        $admin = $this->getJson('/api/v1/dashboard?sppg_id='.$id)->assertOk()->json('data.widgets.operasional_lintas_sppg.total');
        $this->assertSame(1000, $admin['target_porsi']);
        $this->assertSame(2, $admin['stok_di_bawah_minimum']);
        $this->assertSame(1, $admin['keluhan_kritis']);

        // Akun penguji dibuat hanya di dalam tes ini (perintah tidak pernah membuat pengguna).
        Sanctum::actingAs(User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $id]), [SesiService::ABILITY_PENUH]);
        $teks = json_encode($this->getJson('/api/v1/dashboard')->assertOk()->json());

        $this->assertStringContainsString('SDN Uji 1 (Uji)', $teks);
        $this->assertStringContainsString('"kritis_terbuka":1', $teks);
        $this->assertStringContainsString('"di_bawah_minimum":2', $teks);
    }

    public function test_kode_sumber_tidak_memakai_faker_atau_factory(): void
    {
        $berkas = array_merge(
            glob(app_path('Services/DataUji/*.php')) ?: [],
            [app_path('Console/Commands/IsiDataUji.php')],
        );
        $this->assertCount(4, $berkas);

        foreach ($berkas as $path) {
            $isi = (string) file_get_contents($path);
            foreach (['fake(', 'Factory', 'Faker', 'rand(', 'random'] as $terlarang) {
                $this->assertStringNotContainsString($terlarang, $isi, basename($path).' memuat '.$terlarang);
            }
        }
    }
}

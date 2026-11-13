<?php

namespace Tests\Feature\LaporanBgn;

use App\Enums\JenisLaporanBgn;
use App\Jobs\KirimPdfLaporanBgn;
use App\Models\PembelianBahan;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use App\Services\LaporanBgn\LaporanBgnPdfService;
use App\Services\LaporanBgn\Pdf\Teks;
use App\Services\Storage\SecureUploadService;
use App\Services\Telegram\TelegramApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Telegram\FakesTelegram;
use Tests\Support\GambarUji;

/**
 * R07-3: PDF laporan BGN (FPDF), endpoint minta-PDF (antre), job kirim lewat bot, RBAC, isi, injeksi teks.
 */
class LaporanBgnPdfTest extends LaporanBgnTestCase
{
    use FakesTelegram, GambarUji;

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function semuaJenis(): array
    {
        $dua = ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-15'];

        return [
            '30a' => ['porsi', 'porsi', $dua],
            '30b' => ['harian', 'harian', ['tanggal' => '2026-11-02']],
            '30c' => ['dua-mingguan', 'dua_mingguan', $dua],
            '30d-A' => ['bulanan/ringkasan', 'bulanan_ringkasan', ['bulan' => '2026-11']],
            '30d-B' => ['bulanan/anggaran', 'bulanan_anggaran', ['bulan' => '2026-11']],
            '30d-C' => ['bulanan/penerima-manfaat', 'bulanan_penerima', ['bulan' => '2026-11']],
        ];
    }

    private function pdf(string $jalur, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/laporan-bgn/'.$jalur.'/pdf', $body);
    }

    private function buat(string $jenis, string $awal, string $akhir, $pengguna = null, ?int $sppgId = null): string
    {
        $this->forgetActor();
        $sppg = Sppg::query()->withoutGlobalScopes()->findOrFail($sppgId ?? $this->sppgA);
        $berkas = app(LaporanBgnPdfService::class)->buat(
            $pengguna ?? $this->akuntanA, JenisLaporanBgn::from($jenis), $sppg,
            CarbonImmutable::parse($awal), CarbonImmutable::parse($akhir),
        );
        $isi = (string) file_get_contents($berkas);
        unlink($berkas);

        return $isi;
    }

    /** Gabungan teks tampil (operator Tj) seluruh halaman, aliran dikompresi dibuka. */
    private function teksPdf(string $pdf): string
    {
        $hasil = '';
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);

        foreach ($m[1] as $aliran) {
            $buka = @gzuncompress($aliran);

            if ($buka === false) {
                continue;
            }

            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $buka, $t);

            foreach ($t[1] as $s) {
                $hasil .= str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s)."\n";
            }
        }

        return $hasil;
    }

    private function halaman(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b#', $pdf);
    }

    // ------------------------------------------------------------------ endpoint: akses

    #[DataProvider('semuaJenis')]
    public function test_tanpa_login_401(string $jalur, string $jenis, array $query): void
    {
        $this->forgetActor();
        $this->flushHeaders();
        $this->pdf($jalur, $query)->assertUnauthorized();
    }

    #[DataProvider('semuaJenis')]
    public function test_peran_tanpa_hak_403_sebelum_validasi(string $jalur, string $jenis, array $query): void
    {
        Queue::fake();

        foreach ([$this->ahliGiziA, $this->stafGudangA, $this->relawanA] as $user) {
            $this->as($user)->pdf($jalur, $query)->assertForbidden();
            $this->as($user)->pdf($jalur)->assertForbidden();
        }

        Queue::assertNothingPushed();
    }

    #[DataProvider('semuaJenis')]
    public function test_akuntan_kepala_dan_admin_202_dan_job_diantre(string $jalur, string $jenis, array $query): void
    {
        Queue::fake();

        $this->as($this->akuntanA)->pdf($jalur, $query)->assertStatus(202)
            ->assertJsonPath('data.status', 'antre')->assertJsonPath('data.jenis', $jenis)->assertJsonPath('data.tujuan', 'telegram');
        $this->as($this->kepalaA)->pdf($jalur, $query)->assertStatus(202);
        $this->as($this->admin)->pdf($jalur, $query + ['sppg_id' => $this->sppgA])->assertStatus(202);

        Queue::assertPushed(KirimPdfLaporanBgn::class, 3);
        Queue::assertPushed(KirimPdfLaporanBgn::class, fn (KirimPdfLaporanBgn $j) => $j->userId === $this->akuntanA->id
            && $j->chatId === (int) $this->akuntanA->telegram_id && $j->jenis === $jenis && $j->sppgId === $this->sppgA);
    }

    #[DataProvider('semuaJenis')]
    public function test_lintas_sppg_403_dan_tidak_diantre(string $jalur, string $jenis, array $query): void
    {
        Queue::fake();
        $this->as($this->kepalaB)->pdf($jalur, $query + ['sppg_id' => $this->sppgA])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_investor_hanya_agregat_30b_dan_30d_c_403(): void
    {
        Queue::fake();
        config(['laporan-bgn.rate_limit_per_menit' => 100]);

        foreach (['porsi' => ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-15'], 'dua-mingguan' => ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-15'],
            'bulanan/ringkasan' => ['bulan' => '2026-11'], 'bulanan/anggaran' => ['bulan' => '2026-11']] as $jalur => $query) {
            $this->sebagai($this->investorAB, $this->sppgA)->pdf($jalur, $query)->assertStatus(202);
        }

        $this->sebagai($this->investorAB, $this->sppgA)->pdf('harian', ['tanggal' => '2026-11-02'])->assertForbidden();
        $this->sebagai($this->investorAB, $this->sppgA)->pdf('bulanan/penerima-manfaat', ['bulan' => '2026-11'])->assertForbidden();
        $this->sebagai($this->investorC, $this->sppgC)->pdf('porsi', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-15', 'sppg_id' => $this->sppgA])->assertForbidden();

        Queue::assertPushed(KirimPdfLaporanBgn::class, 4);
    }

    public function test_validasi_gagal_422_dan_telegram_belum_terhubung_422(): void
    {
        Queue::fake();
        $this->as($this->akuntanA)->pdf('harian', ['tanggal' => 'bukan-tanggal'])->assertStatus(422);
        $this->as($this->akuntanA)->pdf('bulanan/anggaran', ['bulan' => '2026-13'])->assertStatus(422);
        $this->as($this->akuntanA)->pdf('harian', ['tanggal' => '2026-11-02', 'akuntan_id' => $this->kepalaA->id])->assertStatus(422)->assertJsonValidationErrors('akuntan_id');

        DB::table('users')->where('id', $this->akuntanA->id)->update(['telegram_id' => null]);
        $this->as($this->akuntanA->refresh())->pdf('harian', ['tanggal' => '2026-11-02'])->assertStatus(422)->assertJsonPath('code', 'telegram_belum_terhubung');
        Queue::assertNothingPushed();
    }

    public function test_permintaan_pdf_dibatasi_per_pengguna(): void
    {
        Queue::fake();
        config(['laporan-bgn.rate_limit_per_menit' => 2]);

        $this->as($this->akuntanA)->pdf('harian', ['tanggal' => '2026-11-02'])->assertStatus(202);
        $this->as($this->akuntanA)->pdf('harian', ['tanggal' => '2026-11-02'])->assertStatus(202);
        $this->as($this->akuntanA)->pdf('harian', ['tanggal' => '2026-11-02'])->assertStatus(429);
    }

    public function test_minta_pdf_tidak_menerbitkan_nomor(): void
    {
        Queue::fake();
        $sebelum = DB::table('laporan_bgns')->count();
        $this->as($this->akuntanA)->pdf('dua-mingguan', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-15'])->assertStatus(202)
            ->assertJsonPath('data.nomor', null)->assertJsonPath('data.nomor_terbit', false);
        $this->assertSame($sebelum, DB::table('laporan_bgns')->count());

        $nomor = $this->as($this->akuntanA)->terbitkan('dua-mingguan', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-15'])->assertOk()->json('data.nomor');
        $this->as($this->akuntanA)->pdf('dua-mingguan', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-15'])->assertStatus(202)
            ->assertJsonPath('data.nomor', $nomor)->assertJsonPath('data.nomor_terbit', true);
    }

    // ------------------------------------------------------------------ isi PDF

    #[DataProvider('semuaJenis')]
    public function test_pdf_sah_ukuran_folio_dan_berisi_judul(string $jalur, string $jenis, array $query): void
    {
        $awal = $query['tanggal'] ?? $query['tanggal_awal'] ?? $query['bulan'].'-01';
        $akhir = $query['tanggal'] ?? $query['tanggal_akhir'] ?? CarbonImmutable::parse($awal)->endOfMonth()->toDateString();
        $pdf = $this->buat($jenis, $awal, $akhir);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThanOrEqual(1, $this->halaman($pdf));
        $lanskap = $jenis === 'porsi';
        $this->assertStringContainsString($lanskap ? '/MediaBox [0 0 935.43 595.28]' : '/MediaBox [0 0 595.28 935.43]', $pdf);

        $teks = $this->teksPdf($pdf);
        $this->assertStringContainsString('Dihasilkan oleh SiGAP SPPG, bukan keluaran aplikasi resmi BGN', $teks);
        $this->assertMatchesRegularExpression('/Halaman 1 dari \d+/', $teks);
        // Data SPPG B tidak boleh bocor.
        $this->assertStringNotContainsString('777.777', $teks);
    }

    public function test_isi_30c_angka_dan_tanda_tangan(): void
    {
        $teks = $this->teksPdf($this->buat('dua_mingguan', '2026-11-02', '2026-11-15'));

        $this->assertStringContainsString('LAPORAN 2 (DUA) MINGGUAN PENGGUNAAN DANA PROGRAM MBG TA 2026', $teks);
        $this->assertStringContainsString('(belum diterbitkan)', $teks);
        $this->assertStringContainsString('Yayasan Fiktif Sejahtera', $teks);
        $this->assertStringContainsString('Rp6.900.000', $teks);        // realisasi total
        $this->assertStringContainsString('Rp53.100.000', $teks);       // sisa = saldo awal 10 jt + pencairan 50 jt - realisasi 6,9 jt
        $this->assertStringContainsString('Pihak Pertama', $teks);
        $this->assertStringContainsString('Pihak Kedua', $teks);
        $this->assertStringContainsString('Mengetahui', $teks);
        $this->assertStringContainsString('Ketua Fiktif', $teks);
        $this->assertStringContainsString('akan dialihkan ke periode selanjutnya', $teks);
    }

    public function test_nomor_terbit_tampil_di_pdf(): void
    {
        $this->as($this->akuntanA)->terbitkan('harian', ['tanggal' => '2026-11-02'])->assertOk();
        $nomor = DB::table('laporan_bgns')->where('jenis', 'harian')->value('nomor');
        $this->assertNotEmpty($nomor);
        $this->assertStringContainsString($nomor, $this->teksPdf($this->buat('harian', '2026-11-02', '2026-11-02')));
    }

    public function test_30b_memuat_bagian_dan_17_baris_operasional(): void
    {
        $teks = $this->teksPdf($this->buat('harian', '2026-11-02', '2026-11-02'));

        foreach (['A. Peserta Didik', 'B. Non-Peserta Didik', 'SDN Fiktif 1', 'Posyandu Fiktif Melati', 'Beras Fiktif', 'Total Belanja Bahan Baku Pangan',
            'Insentif Relawan', 'Lain-lain', 'Total Biaya Operasional', 'Biaya Insentif Fasilitas SPPG'] as $kunci) {
            $this->assertStringContainsString($kunci, $teks, $kunci);
        }
    }

    public function test_30d_b_investor_label_baku_tanpa_teks_bebas(): void
    {
        $this->forgetActor();
        $teks = $this->teksPdf($this->buat('bulanan_anggaran', '2026-11-01', '2026-11-30', $this->investorAB));
        $this->assertStringNotContainsString('Budi Fiktif', $teks);
        $this->assertStringNotContainsString('BGN Fiktif Tahap I', $teks);
        $this->assertStringContainsString('Penerimaan Saldo Awal', $teks);
        $this->assertStringContainsString('*dibuat dari tanggal awal sampai dengan akhir bulan', $teks);
    }

    public function test_30a_investor_tersamar_dan_pdf_tetap_sah(): void
    {
        $this->forgetActor();
        $teks = $this->teksPdf($this->buat('porsi', '2026-11-02', '2026-11-15', $this->investorAB));
        $this->assertStringContainsString('REKAPITULASI PERHITUNGAN PORSI MBG di SPPG', $teks);
        $this->assertStringContainsString('<5', $teks);
    }

    // ------------------------------------------------------------------ injeksi teks

    public function test_teks_pengguna_dibersihkan_dan_alias_halaman_aman(): void
    {
        $this->assertSame('?', Teks::bersih("\u{1F600}"));
        $this->assertSame("Caf\xE9", Teks::bersih('Café'));
        $this->assertSame("\x93k\x94", Teks::bersih('“k”'));
        $this->assertSame('ab', Teks::bersih("a\x00\x07b") === 'a b' ? 'ab' : 'x');
        $this->assertStringEndsWith('...', Teks::bersih(str_repeat('x', 300), 20));
        $this->assertLessThanOrEqual(20, strlen(Teks::bersih(str_repeat('x', 300), 20)));
        $this->assertSame('?', Teks::bersih("\xFF"));
    }

    public function test_muatan_jahat_pada_nama_titik_layanan_tampil_sebagai_teks_biasa(): void
    {
        $jahat = [
            '{nb} {nb}', 'a) Tj ET (b', '\\) (x', '<script>alert(1)</script>', '<img src="file:///etc/passwd">', '<svg onload=x>', '@import url(x);',
            '/URI (http://x) /S /URI', "Nama \u{1F600} Emoji", 'Caf'."\u{E9}".' “kutip” ‘ʼ’', str_repeat('PanjangSekali', 40), "baris\nbaru\0nul", "\xC3\x28 utf8 rusak",
        ];

        foreach ($jahat as $i => $nama) {
            $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'T'.$i.' '.$nama]);
            $this->penerima($titik, ['kelompok_sasaran' => 'siswa', 'jenjang' => 'sd_4_6'], 1, 'laki_laki');
        }

        DB::table('pengeluarans')->where('sppg_id', $this->sppgA)->where('tanggal', '2026-11-03')->update(['uraian' => '{nb} <b>tebal</b> (uraian) \\']);

        foreach ([['harian', '2026-11-02', '2026-11-02'], ['bulanan_anggaran', '2026-11-01', '2026-11-30']] as [$jenis, $awal, $akhir]) {
            $pdf = $this->buat($jenis, $awal, $akhir);
            $teks = $this->teksPdf($pdf);

            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertStringEndsWith("%%EOF\n", $pdf);
            // Alias {nb} buatan pengguna tetap literal; tidak diganti jumlah halaman.
            $this->assertMatchesRegularExpression('/Halaman \d+ dari \d+/', $teks);
            $this->assertStringNotContainsString('{sigap', $teks);

            if ($jenis === 'harian') {
                $this->assertStringContainsString('{nb} {nb}', $teks);
                $this->assertStringContainsString('<script>alert(1)</script>', $teks);
                $this->assertStringContainsString('file:///etc/passwd', $teks);
                $this->assertStringContainsString('?', $teks);
            }

            // Tidak ada objek PDF aktif hasil injeksi (aksi/URI) pada struktur dokumen.
            $this->assertStringNotContainsString('/JavaScript', $pdf);
            $this->assertStringNotContainsString('/Launch', $pdf);
            $this->assertStringNotContainsString('/URI (http://x)', $pdf);
            $this->assertStringNotContainsString('/OpenAction', $pdf);
        }
    }

    // ------------------------------------------------------------------ foto nota (lampiran bukti)

    public function test_foto_nota_menjadi_halaman_lampiran_terpisah(): void
    {
        $this->lewatiTanpaGd();
        Storage::fake('uploads');
        $this->forgetActor();
        $p = Pengeluaran::query()->withoutGlobalScopes()->where('sppg_id', $this->sppgA)->where('tanggal', '2026-11-03')->firstOrFail();
        app(SecureUploadService::class)->unggah($this->berkas($this->bytesJpeg(1600, 1200)), $p, 'keuangan-pengeluaran', $this->akuntanA, 'nota');

        $tanpa = $this->halaman($this->buat('harian', '2026-11-02', '2026-11-02'));
        $dengan = $this->buat('harian', '2026-11-03', '2026-11-03');
        $teks = $this->teksPdf($dengan);

        $this->assertStringContainsString('LAMPIRAN BUKTI', $teks);
        $this->assertStringContainsString('Bukti 1: 03-11-2026 - Bahan Baku Pangan - Rp80.000', $teks);
        $this->assertStringContainsString('/Subtype /Image', $dengan);
        $this->assertStringContainsString('/DCTDecode', $dengan);
        $this->assertGreaterThan($tanpa, $this->halaman($dengan) + 0, 'lampiran menambah halaman');

        // 30d-B memakai nomor baris.
        $b = $this->teksPdf($this->buat('bulanan_anggaran', '2026-11-01', '2026-11-30'));
        $this->assertMatchesRegularExpression('/Bukti 1: Baris \d+ - 03-11-2026 - Bahan Baku Pangan - Rp80\.000/', $b);

        // Investor tidak pernah mendapat lampiran foto.
        $inv = $this->buat('bulanan_anggaran', '2026-11-01', '2026-11-30', $this->investorAB);
        $this->assertStringNotContainsString('LAMPIRAN BUKTI', $this->teksPdf($inv));
        $this->assertStringNotContainsString('/Subtype /Image', $inv);
    }

    public function test_nota_pembelian_bahan_dipakai_bila_pengeluaran_tanpa_lampiran(): void
    {
        $this->lewatiTanpaGd();
        Storage::fake('uploads');
        $this->forgetActor();
        $p = Pengeluaran::query()->withoutGlobalScopes()->where('sppg_id', $this->sppgA)->whereNotNull('pembelian_bahan_id')->firstOrFail();
        $pembelian = PembelianBahan::query()->withoutGlobalScopes()->findOrFail($p->pembelian_bahan_id);
        app(SecureUploadService::class)->unggah($this->berkas($this->bytesJpeg(800, 600)), $pembelian, 'pembelian-bahan', $this->akuntanA, 'nota');

        $teks = $this->teksPdf($this->buat('harian', '2026-11-02', '2026-11-02'));
        $this->assertStringContainsString('Bukti 1: 02-11-2026 - Bahan Baku Pangan - Rp120.000', $teks);
    }

    public function test_batas_maksimum_foto_bukti(): void
    {
        $this->lewatiTanpaGd();
        Storage::fake('uploads');
        config(['laporan-bgn.maks_bukti' => 2]);
        $this->forgetActor();

        foreach (Pengeluaran::query()->withoutGlobalScopes()->where('sppg_id', $this->sppgA)->whereDate('tanggal', '2026-11-02')->disetujui()->limit(4)->get() as $p) {
            app(SecureUploadService::class)->unggah($this->berkas($this->bytesJpeg(300, 200)), $p, 'keuangan-pengeluaran', $this->akuntanA, 'nota');
        }

        $pdf = $this->buat('harian', '2026-11-02', '2026-11-02');
        $this->assertSame(2, substr_count($this->teksPdf($pdf), 'Bukti '));
        $this->assertMatchesRegularExpression('/2 bukti lain tidak disertakan/', $this->teksPdf($pdf));
    }

    // ------------------------------------------------------------------ job + bot

    private function jalankanJob(KirimPdfLaporanBgn $job): void
    {
        $this->forgetActor();
        app()->call([$job, 'handle']);
    }

    private function jobUntuk($user, string $jenis, string $awal, string $akhir, ?int $sppgId = null): KirimPdfLaporanBgn
    {
        return new KirimPdfLaporanBgn((int) $user->id, (int) $user->telegram_id, $jenis, $sppgId ?? $this->sppgA, $awal, $akhir);
    }

    private function sisaBerkasKerja(): int
    {
        $dir = storage_path((string) config('laporan-bgn.direktori_kerja'));

        return is_dir($dir) ? count(array_diff(scandir($dir), ['.', '..'])) : 0;
    }

    public function test_job_mengirim_dokumen_lewat_bot_dan_menghapus_berkas_sementara(): void
    {
        config(['telegram.bot_token' => '123456:FAKE-TOKEN']);
        $this->fakeTelegram();
        $sebelum = $this->sisaBerkasKerja();

        $this->jalankanJob($this->jobUntuk($this->akuntanA, 'dua_mingguan', '2026-11-02', '2026-11-15'));

        $kirim = $this->telegramCalls('sendDocument');
        $this->assertCount(1, $kirim);
        $req = $kirim->first();
        $this->assertTrue($req->isMultipart());
        $this->assertStringContainsString('name="document"', $req->body());
        $this->assertStringContainsString('filename="laporan-bgn-30c-2026-11-02_2026-11-15.pdf"', $req->body());
        $this->assertStringContainsString('%PDF-', $req->body());
        $this->assertStringContainsString((string) $this->akuntanA->telegram_id, $req->body());
        $this->assertSame($sebelum, $this->sisaBerkasKerja(), 'berkas sementara harus terhapus');
    }

    public function test_job_dilewati_bila_hak_dicabut_atau_chat_berubah(): void
    {
        config(['telegram.bot_token' => '123456:FAKE-TOKEN']);
        $this->fakeTelegram();

        // Investor tidak boleh 30b walau job dibuat langsung.
        $this->jalankanJob($this->jobUntuk($this->investorAB, 'harian', '2026-11-02', '2026-11-02'));
        // Investor bukan pemilik SPPG A.
        $this->jalankanJob($this->jobUntuk($this->investorC, 'porsi', '2026-11-02', '2026-11-15'));
        // Peran tanpa hak.
        $this->jalankanJob($this->jobUntuk($this->ahliGiziA, 'harian', '2026-11-02', '2026-11-02'));
        // Lintas SPPG.
        $this->jalankanJob($this->jobUntuk($this->kepalaB, 'harian', '2026-11-02', '2026-11-02', $this->sppgA));
        // chat id tidak cocok dengan telegram_id pengguna saat ini.
        $this->jalankanJob(new KirimPdfLaporanBgn((int) $this->akuntanA->id, 999, 'harian', $this->sppgA, '2026-11-02', '2026-11-02'));
        // Pengguna dinonaktifkan.
        DB::table('users')->where('id', $this->kepalaA->id)->update(['is_active' => false]);
        $this->jalankanJob($this->jobUntuk($this->kepalaA->refresh(), 'harian', '2026-11-02', '2026-11-02'));

        $this->assertCount(0, $this->telegramCalls('sendDocument'));
        $this->assertSame(0, $this->sisaBerkasKerja());
    }

    public function test_job_investor_agregat_terkirim_untuk_sppg_miliknya(): void
    {
        config(['telegram.bot_token' => '123456:FAKE-TOKEN']);
        $this->fakeTelegram();

        $this->jalankanJob($this->jobUntuk($this->investorAB, 'bulanan_ringkasan', '2026-11-01', '2026-11-30'));
        $this->assertCount(1, $this->telegramCalls('sendDocument'));
    }

    public function test_job_galat_telegram_permanen_tidak_diulang_dan_berkas_terhapus(): void
    {
        config(['telegram.bot_token' => '123456:FAKE-TOKEN']);
        $this->fakeTelegram(['api.telegram.org/*/sendDocument' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);

        $this->jalankanJob($this->jobUntuk($this->akuntanA, 'porsi', '2026-11-02', '2026-11-15'));

        $this->assertCount(1, $this->telegramCalls('sendDocument'));
        $this->assertSame(0, $this->sisaBerkasKerja());
    }

    public function test_job_galat_sementara_dilempar_untuk_percobaan_ulang_dan_berkas_terhapus(): void
    {
        config(['telegram.bot_token' => '123456:FAKE-TOKEN', 'telegram.http_retries' => 0]);
        $this->fakeTelegram(['api.telegram.org/*/sendDocument' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)]);

        $this->expectException(TelegramApiException::class);

        try {
            $this->jalankanJob($this->jobUntuk($this->akuntanA, 'porsi', '2026-11-02', '2026-11-15'));
        } finally {
            $this->assertSame(0, $this->sisaBerkasKerja());
        }
    }
}

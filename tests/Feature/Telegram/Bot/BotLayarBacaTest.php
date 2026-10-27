<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\LogProduksi;
use App\Models\Pengeluaran;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Catalog\RecordPresenter;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotLayarService;
use App\Services\Telegram\Bot\Layar\LayarAksi;
use App\Services\Telegram\Bot\Layar\LayarDaftar;
use LogicException;

/**
 * Layar baca nyata (R02-B1): isi per modul, whitelist kolom, privasi (3.5), penyamaran agregat, relawan milik sendiri,
 * admin baca-saja tanpa tombol aksi, dan layar tetap jalan tanpa konfigurasi LLM / tool AI dinonaktifkan.
 */
class BotLayarBacaTest extends LayarBacaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->isiData($this->sppgA, 'Alfa');
        $this->isiData($this->sppgB, 'Beta', 999_000);
        $this->penerima($this->sppgA, 3);
        $this->sesiPenuh($this->admin, $this->kepalaA, $this->kepalaB, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA, $this->investorAB, $this->investorC);
    }

    // ------------------------------------------------------------------ isi per layar (kepala SPPG A)

    public function test_jadwal_dan_distribusi_hari_ini_hanya_sppg_sendiri_dan_pengemudi_dimasker(): void
    {
        $jadwal = $this->buka($this->kepalaA, 'jd');
        $this->assertStringContainsString('Jadwal Hari Ini', $jadwal);
        $this->assertStringContainsString('Menu Alfa', $jadwal);
        $this->assertStringContainsString('500 porsi', $jadwal);
        $this->assertStringContainsString('SD Alfa Uji', $jadwal);
        $this->assertStringContainsString('B 1234 Alfa', $jadwal);
        $this->assertStringContainsString('pengemudi B.S.', $jadwal, 'Pengemudi hanya inisial');
        $this->assertStringNotContainsString('Beta', $jadwal);
        $this->assertTanpaTerlarang($jadwal, 'jd');

        $distribusi = $this->buka($this->kepalaA, 'ds');
        $this->assertStringContainsString('SD Alfa Uji', $distribusi);
        $this->assertStringContainsString('240 porsi diterima', $distribusi);
        $this->assertStringNotContainsString('Beta', $distribusi);
        $this->assertTanpaTerlarang($distribusi, 'ds');
    }

    public function test_armada_plat_status_dan_pengemudi_hanya_inisial(): void
    {
        $teks = $this->buka($this->kepalaA, 'ar');

        $this->assertStringContainsString('B 1234 Alfa', $teks);
        $this->assertStringContainsString('B.S. #', $teks);
        $this->assertStringNotContainsString('Beta', $teks);
        $this->assertTanpaTerlarang($teks, 'ar');
    }

    public function test_keluhan_tersamar_dan_terpotong(): void
    {
        $teks = $this->buka($this->kepalaA, 'kl');

        $this->assertStringContainsString('Keluhan Terbuka', $teks);
        $this->assertStringContainsString('[KRITIS]', $teks);
        $this->assertStringContainsString('SD Alfa Uji', $teks);
        $this->assertStringContainsString('[disamarkan]', $teks, 'Nomor telepon pada teks bebas disamarkan');
        $this->assertStringNotContainsString('Budi', $teks, 'Nama pada teks bebas menjadi inisial');
        $this->assertTanpaTerlarang($teks, 'kl');
    }

    public function test_keuangan_tanpa_uraian_dan_nota_dan_hanya_sppg_sendiri(): void
    {
        $pengeluaran = $this->buka($this->akuntanA, 'pk');
        $this->assertStringContainsString('Rp 123.000', $pengeluaran);
        $this->assertStringContainsString('Energi', $pengeluaran);
        $this->assertStringNotContainsString('Rp 999.000', $pengeluaran, 'Pengeluaran SPPG B');
        $this->assertTanpaTerlarang($pengeluaran, 'pk');

        $pencairan = $this->buka($this->akuntanA, 'pc');
        $this->assertStringContainsString('Dana Alfa', $pencairan);
        $this->assertStringNotContainsString('Dana Beta', $pencairan);
        $this->assertStringContainsString('Rp 10.000.000', $pencairan);
    }

    public function test_anggaran_dan_keuangan_menampilkan_ambang_80_dan_100_persen(): void
    {
        // Pencairan 10.000.000; pengeluaran disetujui 123.000 -> aman. Naikkan ke 8.500.000 -> peringatan 80%.
        $aman = $this->buka($this->kepalaA, 'ag');
        $this->assertStringContainsString('Aman', $aman);
        $this->assertStringContainsString('Ambang 80%: belum', $aman);

        $this->forgetActor();
        Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'nominal' => 8_400_000, 'kategori' => 'energi']);
        $peringatan = $this->buka($this->kepalaA, 'ag');
        $this->assertStringContainsString('PERINGATAN', $peringatan);
        $this->assertStringContainsString('Ambang 80%: TERCAPAI', $peringatan);
        $this->assertStringContainsString('ambang 100%: belum', $peringatan);
        $this->assertStringContainsString('Rp 8.523.000', $peringatan);

        Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'nominal' => 2_000_000, 'kategori' => 'energi']);
        $lewat = $this->buka($this->kepalaA, 'ku');
        $this->assertStringContainsString('MELEBIHI', $lewat);
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Pencairan'));
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Pengeluaran'));
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Anggaran'));
    }

    public function test_stok_menipis_dan_kedaluwarsa(): void
    {
        $stok = $this->buka($this->stafGudangA, 'st');
        $this->assertStringContainsString('Bahan Alfa Menipis', $stok);
        $this->assertStringContainsString('[MENIPIS]', $stok);
        $this->assertStringNotContainsString('Beta', $stok);

        $menipis = $this->buka($this->stafGudangA, 'sm');
        $this->assertStringContainsString('Bahan Alfa Menipis', $menipis);
        $this->assertStringNotContainsString('Bahan Alfa Lewat', $menipis);

        $kedaluwarsa = $this->buka($this->stafGudangA, 'kd');
        $this->assertStringContainsString('Bahan Alfa Lewat', $kedaluwarsa);
        $this->assertStringContainsString('[SUDAH LEWAT]', $kedaluwarsa);
        $this->assertStringNotContainsString('Bahan Alfa Menipis', $kedaluwarsa);
    }

    public function test_rekap_mutu_log_titik_tim_perjalanan_menu_dan_pemasok(): void
    {
        $rekap = $this->buka($this->kepalaA, 'rk');
        $this->assertStringContainsString('Rekap Harian', $rekap);
        $this->assertStringContainsString('Produksi: 1 jadwal, target 500 porsi', $rekap);
        $this->assertStringContainsString('realisasi 480', $rekap);
        $this->assertStringContainsString('Distribusi: 1 jadwal', $rekap);
        $this->assertStringContainsString('Keluhan: 1 baru hari ini (1 kritis)', $rekap);
        $this->assertStringContainsString('Rekap tersimpan terakhir', $rekap);
        $this->assertStringNotContainsString('target 1.200', $rekap, 'Bukan ringkasan JSON rekap lama');

        $this->assertStringContainsString('suhu 71,5 C', $this->buka($this->kepalaA, 'mt'));
        $this->assertStringContainsString('Batch BATCH-Alfa', $this->buka($this->kepalaA, 'lg'));

        $titik = $this->buka($this->kepalaA, 'tl');
        $this->assertStringContainsString('SD Alfa Uji', $titik);
        $this->assertStringContainsString('PIC S.R.', $titik);
        $this->assertStringNotContainsString('SD Beta', $titik);
        $this->assertTanpaTerlarang($titik, 'tl');

        $tim = $this->buka($this->kepalaA, 'tm');
        $this->assertStringContainsString('Kepala SPPG: 1', $tim);
        $this->assertStringContainsString('Akuntan: 1', $tim);
        $this->assertStringNotContainsString($this->kepalaA->name, $tim, 'Tim hanya hitungan, tanpa nama');

        if ($this->kepalaA->phone) {
            $this->assertStringNotContainsString((string) $this->kepalaA->phone, $tim);
        }

        $this->assertStringContainsString('SD Alfa Uji', $this->buka($this->kepalaA, 'np'));
        $this->assertStringContainsString('Menu Alfa', $this->buka($this->kepalaA, 'mg'));
        $this->assertStringContainsString('Menu Alfa', $this->buka($this->kepalaA, 'mh'));
        $this->assertStringContainsString('Pemasok Alfa', $this->buka($this->akuntanA, 'pe'));
        $this->assertStringContainsString('Beras Alfa', $this->buka($this->akuntanA, 'pb'));
    }

    public function test_persetujuan_menu_tanpa_catatan_keputusan(): void
    {
        foreach ([[$this->ahliGiziA, $this->sppgA], [$this->investorC, $this->sppgC]] as [$user, $sppg]) {
            if ($sppg === $this->sppgC) {
                $this->isiData($this->sppgC, 'Gamma');
            }

            $teks = $this->buka($user, 'pj', $sppg);
            $this->assertStringContainsString('Persetujuan Menu', $teks);
            $this->assertStringContainsString('Menu Ajuan '.($sppg === $this->sppgA ? 'Alfa' : 'Gamma'), $teks);
            $this->assertStringContainsString('Menunggu persetujuan', str_replace('Diajukan', 'Menunggu persetujuan', $teks));
            $this->assertStringNotContainsString('CATATAN-KEPUTUSAN-RAHASIA', $teks);
            $this->assertStringNotContainsString('Beta', $teks);
        }
    }

    // ------------------------------------------------------------------ penerima manfaat agregat tersamar

    public function test_penerima_manfaat_agregat_tersamar_untuk_semua_peran_termasuk_ahli_gizi_dan_admin(): void
    {
        // SPPG A: 3 siswa aktif (< k=5) -> semua sel tersamar.
        foreach ([[$this->ahliGiziA, $this->sppgA], [$this->kepalaA, $this->sppgA], [$this->admin, $this->sppgA], [$this->investorC, $this->sppgC]] as [$user, $sppg]) {
            if ($sppg === $this->sppgC) {
                $this->penerima($this->sppgC, 3);
            }

            $teks = $this->buka($user, 'pn', $sppg);
            $this->assertStringContainsString('Penerima Manfaat (agregat)', $teks, $user->role->value);
            $this->assertStringContainsString('Penerima aktif: &lt;5', $teks, $user->role->value.' (peran ber-hak R penuh tetap tersamar di chat)');
            $this->assertStringNotContainsString('Penerima aktif: 3', $teks);
        }
    }

    public function test_penerima_manfaat_di_atas_ambang_menampilkan_angka_tanpa_data_pribadi(): void
    {
        $this->penerima($this->sppgA, 9);
        $teks = $this->buka($this->ahliGiziA, 'pn');

        $this->assertStringContainsString('Penerima aktif: 12', $teks);
        $this->assertStringContainsString('Siswa: 12', $teks);
        $this->assertStringNotContainsString('Alergi', $teks);
        $this->assertStringNotContainsString('SD Alfa', $teks, 'Tanpa rincian per titik layanan');
    }

    // ------------------------------------------------------------------ relawan: milik sendiri, hari ini

    public function test_relawan_dapur_hanya_log_milik_sendiri_hari_ini(): void
    {
        $lain = $this->userOf(Role::Relawan, $this->sppgA, $this->relawanA->unit);
        $this->sesiPenuh($lain);
        $this->forgetActor();
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanA->id, 'kode_batch' => 'MILIK-SAYA']);
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $lain->id, 'kode_batch' => 'MILIK-LAIN']);
        $kemarin = LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanA->id, 'kode_batch' => 'MILIK-KEMARIN']);
        $kemarin->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $teks = $this->buka($this->relawanA, 'lg');

        $this->assertStringContainsString('Batch MILIK-SAYA', $teks);
        $this->assertStringNotContainsString('MILIK-LAIN', $teks);
        $this->assertStringNotContainsString('MILIK-KEMARIN', $teks, 'Hanya hari ini');
        $this->assertStringNotContainsString('BATCH-Alfa', $teks, 'Log pencatat lain (kepala/seeder) tidak tampil');

        // Kepala melihat log SPPG hari ini (bukan hanya miliknya).
        $kepala = $this->buka($this->kepalaA, 'lg');
        $this->assertStringContainsString('MILIK-LAIN', $kepala);
        $this->assertStringContainsString('Batch BATCH-Alfa', $kepala);
    }

    public function test_relawan_distribusi_tidak_melihat_seksi_produksi_dan_profil_tanpa_kontak(): void
    {
        $dist = $this->relawanDistribusi();
        $this->sesiPenuh($dist);

        $jadwal = $this->buka($dist, 'jd');
        $this->assertStringContainsString('Distribusi', $jadwal);
        $this->assertStringNotContainsString('Menu Alfa', $jadwal, 'Jadwal produksi bukan unit distribusi');

        $profil = $this->buka($dist, 'pf');
        $this->assertStringContainsString('Profil Saya', $profil);
        $this->assertStringContainsString('Unit: ', $profil);
        $this->assertStringContainsString('SPPG: SPPG A', $profil);

        foreach (array_filter([$dist->phone, $dist->username, $dist->email ?? null]) as $kontak) {
            $this->assertStringNotContainsString((string) $kontak, $profil);
        }
    }

    // ------------------------------------------------------------------ admin baca-saja

    public function test_admin_hanya_melihat_sppg_terpilih_tanpa_tombol_aksi(): void
    {
        foreach ($this->idLayarData() as $id) {
            // Layar aksi Mulai Perjalanan (PR-3): admin tidak punya hak C, layar ditolak tanpa data dan tanpa token.
            if (app(BotLayarRegistry::class)->cari($id) instanceof LayarAksi) {
                $this->assertNull($this->buka($this->admin, $id, $this->sppgA), "admin tidak boleh membuka layar aksi {$id}");

                continue;
            }

            $a = $this->buka($this->admin, $id, $this->sppgA);
            $this->assertNotNull($a, "admin layar {$id}");
            $this->assertStringNotContainsString('Beta', $a, "admin/{$id}/A bocor SPPG B");

            foreach ($this->tombolLayar((int) $this->admin->telegram_id) as $t) {
                $this->assertStringStartsNotWith('b1.', (string) ($t['callback_data'] ?? ''), "admin/{$id}");
            }

            $b = $this->buka($this->admin, $id, $this->sppgB);
            $this->assertStringNotContainsString('Alfa', (string) $b, "admin/{$id}/B bocor SPPG A");
        }

        $this->assertSame(0, BotAksi::query()->count(), 'Admin tidak pernah menerbitkan token aksi');
        $this->assertStringContainsString('SD Beta Uji', $this->buka($this->admin, 'tl', $this->sppgB));
        $this->assertStringNotContainsString('SD Alfa Uji', $this->buka($this->admin, 'tl', $this->sppgB));
        $this->assertStringContainsString('Menu Ajuan Beta', $this->buka($this->admin, 'pj', $this->sppgB), 'Sumber di luar katalog tetap dipersempit');
        $this->assertStringNotContainsString('Menu Ajuan Alfa', $this->buka($this->admin, 'pj', $this->sppgB));
    }

    // ------------------------------------------------------------------ whitelist dan privasi menyeluruh

    public function test_tidak_ada_layar_mana_pun_membocorkan_data_terlarang(): void
    {
        $peran = ['kepalaA' => $this->sppgA, 'akuntanA' => $this->sppgA, 'ahliGiziA' => $this->sppgA, 'stafGudangA' => $this->sppgA, 'admin' => $this->sppgA, 'investorAB' => $this->sppgA];

        foreach ($peran as $nama => $sppg) {
            $user = $this->{$nama};

            foreach ($this->idLayarData() as $id) {
                $teks = $this->buka($user, $id, $sppg);

                if ($teks !== null) {
                    $this->assertTanpaTerlarang($teks, "{$nama}/{$id}");
                    $this->assertStringNotContainsString('CATATAN-KEPUTUSAN-RAHASIA', $teks);
                    $this->assertStringNotContainsString('Beta', $teks, "{$nama}/{$id}");
                }
            }
        }
    }

    public function test_whitelist_kolom_tiap_seksi_ada_di_spec_dan_tidak_sensitif(): void
    {
        $dilarang = ['uraian', 'catatan', 'catatan_keputusan', 'nota', 'no_hp', 'no_sim', 'pic_telepon', 'kontak', 'alamat', 'latitude', 'longitude', 'tindak_lanjut', 'catatan_selisih', 'kendala', 'telepon'];
        $layanan = app(BotLayarService::class);
        $user = $this->kepalaA;
        $periksa = 0;

        foreach (app(BotLayarRegistry::class)->semua() as $layar) {
            if (! $layar instanceof LayarDaftar) {
                continue;
            }

            foreach ($layar->seksi() as $seksi) {
                foreach ($seksi->kolom as $kolom) {
                    $this->assertSame(0, preg_match(RecordPresenter::FORBIDDEN, $kolom), "{$layar->id()}/{$kolom} sensitif");
                    $this->assertNotContains($kolom, $dilarang, "{$layar->id()}/{$kolom} dilarang di chat");
                }

                // Kolom whitelist harus ada di spec (gagal keras bila tidak); memanggil sumber memvalidasinya.
                $layanan->daftar($user, $seksi->sumber, $seksi->argumenUntuk($user), $seksi->kolom);
                $periksa++;
            }
        }

        $this->assertGreaterThanOrEqual(20, $periksa);
    }

    public function test_adapter_hanya_meneruskan_kolom_whitelist_dan_menolak_kolom_tak_dikenal(): void
    {
        $layanan = app(BotLayarService::class);
        $hasil = $layanan->daftar($this->akuntanA, 'pengeluaran', [], ['tanggal', 'nominal']);

        $this->assertSame(['tanggal', 'nominal'], array_keys($hasil['baris'][0]), 'uraian ada di payload tool tetapi tidak diteruskan');
        $this->assertNull($layanan->daftar($this->stafGudangA, 'pengeluaran', [], ['nominal']), 'Tool tidak tersedia untuk peran tanpa hak keuangan');

        $this->expectException(LogicException::class);
        $layanan->daftar($this->akuntanA, 'pengeluaran', [], ['nominal', 'kolom_tidak_ada']);
    }

    public function test_filter_milik_saya_pada_tool_log_produksi_hanya_log_pengguna(): void
    {
        $this->forgetActor();
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanA->id, 'kode_batch' => 'PUNYA-A']);
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->kepalaA->id, 'kode_batch' => 'PUNYA-K']);

        $ambil = fn (array $argumen): array => ActingUser::run($this->kepalaA, fn () => app(BotLayarService::class)->daftar($this->kepalaA, 'log_produksi', $argumen, ['kode_batch']));
        $semua = $ambil([]);
        $milik = $ambil(['milik_saya' => true]);

        $this->assertCount(2 + 1, $semua['baris'], 'Tanpa filter: semua log SPPG (seeder + 2)');
        $this->assertSame([['kode_batch' => 'PUNYA-K']], $milik['baris']);
    }

    // ------------------------------------------------------------------ tanpa LLM

    public function test_layar_tetap_jalan_tanpa_konfigurasi_llm_dan_dengan_tool_ai_dinonaktifkan(): void
    {
        config([
            'ai.provider' => '',
            'ai.tools.disabled' => ['cari_jadwal_produksi', 'cari_jadwal_distribusi', 'cari_bahan_baku', 'cari_keluhan', 'cari_pengeluaran', 'cari_pencairan_dana', 'daftar_pengajuan_menu', 'cari_titik_layanan', 'cari_armada', 'cari_rekap_harian'],
        ]);

        foreach (['jd', 'st', 'kl', 'pk', 'pc', 'ar', 'rk', 'tl', 'pj'] as $id) {
            $this->assertNotNull($this->buka($this->kepalaA, $id), "layar {$id} harus tetap jalan");
        }

        $this->assertStringContainsString('Menu Alfa', $this->buka($this->kepalaA, 'jd'));
    }
}

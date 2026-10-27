<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\BuktiDistribusi;
use App\Models\User;
use App\Services\Telegram\Bot\BotLayarRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Audit R02-B2: isi layar baca untuk ahli gizi, staf gudang, dan relawan per unit (dapur, distribusi, tanpa unit),
 * serta jaminan tombol tulis (Buat Keluhan, Mulai Perjalanan) belum tampil di PR ini. Hak per layar ada di
 * BotLayarBacaMatriksTest; di sini yang diuji adalah isi dan batas privasinya.
 */
class BotLayarPerPeranB2Test extends LayarBacaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->isiData($this->sppgA, 'Alfa');
        $this->isiData($this->sppgB, 'Beta');
    }

    /** @return list<string> */
    private function labelBeranda(User $user): array
    {
        $this->sesiPenuh($user);
        $this->ketik($user, '/menu');

        return array_map(fn (array $t): string => (string) $t['text'], $this->tombolLayar((int) $user->telegram_id));
    }

    /** @return iterable<string, array{string}> */
    public static function peran(): iterable
    {
        foreach (['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorC'] as $p) {
            yield $p => [$p];
        }
    }

    #[DataProvider('peran')]
    public function test_tombol_tulis_belum_tampil_di_beranda_peran_mana_pun(string $peran): void
    {
        $label = implode(' | ', $this->labelBeranda($this->pengguna($peran)));

        foreach (['Buat Keluhan', 'Mulai Perjalanan', 'Catat', 'Picu', 'Kirim ulang', 'Tindak lanjut'] as $tulis) {
            $this->assertStringNotContainsString($tulis, $label, "{$peran}: tombol tulis '{$tulis}' belum boleh ada");
        }
    }

    public function test_setiap_layar_beranda_relawan_dan_gudang_terdaftar_di_registri(): void
    {
        $registri = app(BotLayarRegistry::class);

        foreach (['ahli_gizi', 'staf_gudang', 'relawan.dapur', 'relawan.distribusi', 'relawan.tanpa_unit'] as $profil) {
            foreach (BotLayarRegistry::MENU[$profil] as [$id]) {
                $this->assertNotNull($registri->cari($id), "{$profil}: layar {$id} belum terdaftar");
            }
        }
    }

    // ------------------------------------------------------------------ ahli gizi

    public function test_ahli_gizi_membuka_layar_barisnya_dengan_data_sppg_sendiri(): void
    {
        $this->sesiPenuh($this->ahliGiziA);
        $this->penerima($this->sppgA, 6);

        $this->assertStringContainsString('Menu Alfa', (string) $this->buka($this->ahliGiziA, 'mg'));
        $this->assertStringContainsString('71,5', str_replace('.', ',', (string) $this->buka($this->ahliGiziA, 'mt')));
        $this->assertStringContainsString('Bahan Alfa', (string) $this->buka($this->ahliGiziA, 'st'));
        $this->assertStringContainsString('Batch BATCH-Alfa', (string) $this->buka($this->ahliGiziA, 'lg'));
        $this->assertNotNull($this->buka($this->ahliGiziA, 'jd'));
        $this->assertNotNull($this->buka($this->ahliGiziA, 'kl'));
        $this->assertNotNull($this->buka($this->ahliGiziA, 'rk'));

        $penerima = (string) $this->buka($this->ahliGiziA, 'pn');
        $this->assertStringNotContainsString('Beta', $penerima);
        $this->assertTanpaTerlarang($penerima, 'ahli gizi / penerima');

        $this->assertNull($this->buka($this->ahliGiziA, 'ku'), 'Keuangan bukan hak ahli gizi');
    }

    // ------------------------------------------------------------------ staf gudang

    public function test_staf_gudang_stok_menipis_kedaluwarsa_pemasok_menu_jadwal_log(): void
    {
        $this->sesiPenuh($this->stafGudangA);

        $menipis = (string) $this->buka($this->stafGudangA, 'sm');
        $this->assertStringContainsString('Bahan Alfa Menipis', $menipis);
        $this->assertStringNotContainsString('Bahan Beta', $menipis);

        $lewat = (string) $this->buka($this->stafGudangA, 'kd');
        $this->assertStringContainsString('Bahan Alfa Lewat', $lewat);
        $this->assertStringContainsString('SUDAH LEWAT', $lewat);

        $pemasok = (string) $this->buka($this->stafGudangA, 'pe');
        $this->assertStringContainsString('Pemasok Alfa', $pemasok);
        $this->assertStringNotContainsString('Pemasok Beta', $pemasok);
        $this->assertTanpaTerlarang($pemasok, 'gudang / pemasok');

        $this->assertStringContainsString('Menu Alfa', (string) $this->buka($this->stafGudangA, 'mg'));
        $this->assertNotNull($this->buka($this->stafGudangA, 'jd'));
        $this->assertStringContainsString('Batch BATCH-Alfa', (string) $this->buka($this->stafGudangA, 'lg'), 'Staf gudang melihat log SPPG (bukan hanya milik sendiri)');

        $this->assertNull($this->buka($this->stafGudangA, 'mt'), 'Mutu bukan hak staf gudang');
        $this->assertNull($this->buka($this->stafGudangA, 'ar'));
    }

    // ------------------------------------------------------------------ relawan per unit

    public function test_relawan_dapur_jadwal_hanya_produksi_menu_hari_ini_dan_mutu_lihat(): void
    {
        $this->sesiPenuh($this->relawanA);

        $jadwal = (string) $this->buka($this->relawanA, 'jd');
        $this->assertStringContainsString('Produksi', $jadwal);
        $this->assertStringContainsString('Menu Alfa', $jadwal);
        $this->assertStringNotContainsString('SD Alfa Uji', $jadwal, 'Jadwal distribusi bukan unit dapur');
        $this->assertStringNotContainsString('Budi', $jadwal);

        $this->assertStringContainsString('Menu Alfa', (string) $this->buka($this->relawanA, 'mh'));
        $this->assertNotNull($this->buka($this->relawanA, 'mt'));
        $this->assertNotNull($this->buka($this->relawanA, 'pf'));

        foreach (['ds', 'st', 'pe', 'rk', 'ku', 'tm'] as $id) {
            $this->assertNull($this->buka($this->relawanA, $id), "Relawan dapur tidak boleh membuka {$id}");
        }
    }

    public function test_relawan_distribusi_bukti_armada_dan_menu_tanpa_data_pribadi(): void
    {
        $dist = $this->relawanDistribusi();
        $this->sesiPenuh($dist);

        // Policy view bukti distribusi: relawan hanya melihat bukti buatannya sendiri.
        $this->forgetActor();
        BuktiDistribusi::query()->where('sppg_id', $this->sppgA)->update(['porsi_diterima' => 777, 'dicatat_oleh' => $dist->id]);

        $bukti = (string) $this->buka($dist, 'ds');
        $this->assertStringContainsString('SD Alfa Uji', $bukti);
        $this->assertStringContainsString('777', $bukti);
        $this->assertTanpaTerlarang($bukti, 'distribusi / bukti');

        $armada = (string) $this->buka($dist, 'ar');
        $this->assertStringContainsString('B 1234 Alfa', $armada);
        $this->assertStringNotContainsString('Budi Santoso', $armada, 'Pengemudi hanya inisial');
        $this->assertTanpaTerlarang($armada, 'distribusi / armada');

        $this->assertStringContainsString('Menu Alfa', (string) $this->buka($dist, 'mh'));
        $this->assertStringNotContainsString('Beta', (string) $this->buka($dist, 'jd'));

        foreach (['lg', 'st', 'rk'] as $id) {
            $this->assertNull($this->buka($dist, $id), "Relawan distribusi tidak boleh membuka {$id}");
        }
    }

    public function test_relawan_tanpa_unit_perilaku_aman_hanya_menu_mutu_armada_profil(): void
    {
        $tanpa = $this->relawanTanpaUnit();
        $this->sesiPenuh($tanpa);

        $this->assertSame(['Menu Hari Ini', 'Mutu', 'Armada', 'Profil Saya'], array_slice($this->labelBeranda($tanpa), 0, 4));

        foreach (['mh', 'mt', 'ar', 'pf'] as $id) {
            $this->assertNotNull($this->buka($tanpa, $id), "Relawan tanpa unit harus bisa membuka {$id}");
        }

        foreach (['jd', 'lg', 'ds', 'st', 'rk'] as $id) {
            $this->assertNull($this->buka($tanpa, $id), "Relawan tanpa unit tidak boleh membuka {$id}");
        }
    }
}

<?php

namespace Tests\Feature\Investor;

use App\Models\PenerimaManfaat;
use App\Models\TitikLayanan;
use App\Services\PenerimaManfaat\AgregatPenerimaService;

/**
 * R06a-S-02 (ambang sel kecil k = 5 + suppression sekunder untuk peran agregat saja) dan
 * R06a-S-04 (titik layanan terhapus tidak tampil; nama titik boleh dilihat investor). Data fiktif.
 */
class AgregatAmbangTest extends InvestorTestCase
{
    private const URL = '/api/v1/penerima-manfaat/agregat';

    private function penerima(TitikLayanan $titik, string $kelompok, int $jumlah, bool $aktif = true): void
    {
        PenerimaManfaat::factory()->count($jumlah)->create([
            'sppg_id' => $titik->sppg_id, 'titik_layanan_id' => $titik->id, 'kelompok_sasaran' => $kelompok, 'is_active' => $aktif,
        ]);
    }

    private function titik(string $nama, ?int $sppgId = null): TitikLayanan
    {
        return TitikLayanan::factory()->create(['sppg_id' => $sppgId ?? $this->sppgA, 'nama' => $nama]);
    }

    /** @return array<string, array{jumlah: ?int, penanda: ?string}> */
    private function sel(array $baris, string $kunci): array
    {
        return collect($baris)->mapWithKeys(fn ($s) => [$s[$kunci] => ['jumlah' => $s['jumlah'], 'penanda' => $s['penanda']]])->all();
    }

    // ---------------------------------------------------------------- S-02 lewat HTTP

    public function test_investor_sel_kecil_dan_pelengkap_disamarkan_kepala_melihat_angka_asli(): void
    {
        $sd = $this->titik('SDN Fiktif Satu');
        $posyandu = $this->titik('Posyandu Fiktif Mawar');
        $pos2 = $this->titik('Posyandu Fiktif Kecil');
        $this->penerima($sd, 'siswa', 20);
        $this->penerima($posyandu, 'balita', 6);
        $this->penerima($pos2, 'ibu_hamil', 2);
        $this->penerima($sd, 'siswa', 1, aktif: false); // nonaktif = 1 -> total ikut disamarkan

        $res = $this->sebagai($this->investorAB, $this->sppgA)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.penyamaran', ['diterapkan' => true, 'ambang_k' => 5, 'penanda' => '<5'])
            ->assertJsonPath('data.aktif', 28)
            ->assertJsonPath('data.aktif_penanda', null)
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.total_penanda', '<5');

        // Kelompok: ibu_hamil 2 (primer) + balita 6 (pelengkap terkecil); nol tetap terlihat.
        $this->assertSame([
            'siswa' => ['jumlah' => 20, 'penanda' => null],
            'balita' => ['jumlah' => null, 'penanda' => '<5'],
            'ibu_hamil' => ['jumlah' => null, 'penanda' => '<5'],
            'ibu_menyusui' => ['jumlah' => 0, 'penanda' => null],
        ], $this->sel($res->json('data.per_kelompok_sasaran'), 'kelompok_sasaran'));

        // Titik: nama boleh terlihat (keputusan S-04); posyandu kecil 2 (primer) + posyandu 6 (pelengkap).
        $this->assertSame([
            'Posyandu Fiktif Kecil' => ['jumlah' => null, 'penanda' => '<5'],
            'Posyandu Fiktif Mawar' => ['jumlah' => null, 'penanda' => '<5'],
            'SDN Fiktif Satu' => ['jumlah' => 20, 'penanda' => null],
        ], $this->sel($res->json('data.per_titik_layanan'), 'nama'));

        // Sel tersamar tidak dapat dihitung dari aktif - sel terlihat (minimal dua sel per tabel).
        foreach (['per_kelompok_sasaran', 'per_titik_layanan'] as $tabel) {
            $this->assertGreaterThanOrEqual(2, collect($res->json("data.{$tabel}"))->whereNull('jumlah')->count(), $tabel);
        }

        // Tidak ada tabel silang titik x kelompok.
        $this->assertSame(['sppg_id', 'total', 'total_penanda', 'aktif', 'aktif_penanda', 'penyamaran', 'per_kelompok_sasaran', 'per_titik_layanan'], array_keys($res->json('data')));
        $this->assertSame(['titik_layanan_id', 'nama', 'jumlah', 'penanda'], array_keys($res->json('data.per_titik_layanan.0')));

        // Kepala SPPG dan Ahli Gizi (hak R penuh): angka asli, tanpa penyamaran.
        foreach ([$this->kepalaA, $this->ahliGiziA] as $peran) {
            $this->sebagai($peran)->getJson(self::URL)->assertOk()
                ->assertJsonPath('data.penyamaran.diterapkan', false)
                ->assertJsonPath('data.total', 29)
                ->assertJsonPath('data.aktif', 28)
                ->assertJsonPath('data.per_kelompok_sasaran.2', ['kelompok_sasaran' => 'ibu_hamil', 'label' => 'Ibu Hamil', 'jumlah' => 2, 'penanda' => null]);
        }

        $this->sebagai($this->admin)->getJson(self::URL.'?sppg_id='.$this->sppgA)->assertOk()
            ->assertJsonPath('data.penyamaran.diterapkan', false)->assertJsonPath('data.total', 29);
    }

    public function test_sppg_sangat_kecil_seluruh_rincian_disamarkan_termasuk_nol(): void
    {
        $this->penerima($this->titik('Posyandu Fiktif Tunggal'), 'ibu_hamil', 3);

        $res = $this->sebagai($this->investorAB, $this->sppgA)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.aktif', null)
            ->assertJsonPath('data.aktif_penanda', '<5');

        $this->assertSame([null], array_values(array_unique(array_column($res->json('data.per_kelompok_sasaran'), 'jumlah'))));
        $this->assertSame([null], array_values(array_unique(array_column($res->json('data.per_titik_layanan'), 'jumlah'))));
        $this->assertStringNotContainsString('"jumlah":3', $res->getContent());
        $this->assertStringNotContainsString('"jumlah":0', $res->getContent(), 'Nol pun disamarkan agar tidak terungkap "semua penerima = ibu hamil".');
    }

    public function test_sppg_kosong_tidak_disamarkan(): void
    {
        $this->sebagai($this->investorAB, $this->sppgB)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.aktif', 0)
            ->assertJsonPath('data.per_kelompok_sasaran.0.jumlah', 0)
            ->assertJsonPath('data.per_titik_layanan', []);
    }

    public function test_ambang_dapat_diatur_di_config(): void
    {
        config(['rbac.agregat_ambang.k' => 3]);
        $titik = $this->titik('SDN Fiktif Dua');
        $this->penerima($titik, 'siswa', 10);
        $this->penerima($titik, 'balita', 3);
        $this->penerima($titik, 'ibu_hamil', 2);

        $res = $this->sebagai($this->investorAB, $this->sppgA)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.penyamaran.ambang_k', 3)
            ->assertJsonPath('data.penyamaran.penanda', '<3');

        $sel = $this->sel($res->json('data.per_kelompok_sasaran'), 'kelompok_sasaran');
        $this->assertSame(10, $sel['siswa']['jumlah']);
        $this->assertNull($sel['balita']['jumlah'], 'balita (3) menjadi sel pelengkap ibu_hamil (2).');
        $this->assertSame('<3', $sel['ibu_hamil']['penanda']);
    }

    // ---------------------------------------------------------------- S-02 aturan (tanpa HTTP)

    public function test_aturan_topeng_tabel(): void
    {
        // Satu primer -> pelengkap sel positif terkecil.
        $this->assertSame([[false, true, true, false], false], AgregatPenerimaService::topengTabel([20, 6, 2, 0], 5));
        // Dua primer berjumlah < k -> tambah pelengkap sampai jumlah tersamar >= k.
        $this->assertSame([[true, false, true, true], false], AgregatPenerimaService::topengTabel([100, 0, 2, 1], 5));
        // Dua primer berjumlah >= k -> cukup.
        $this->assertSame([[false, true, true], false], AgregatPenerimaService::topengTabel([50, 4, 3], 5));
        // Tanpa sel kecil -> tidak ada yang disamarkan.
        $this->assertSame([[false, false, false], false], AgregatPenerimaService::topengTabel([50, 5, 0], 5));
        // Tak ada pelengkap: marginal diketahui -> marginal (aktif) wajib disamarkan; tidak diketahui -> tidak.
        $this->assertSame([[true, false], true], AgregatPenerimaService::topengTabel([3, 0], 5));
        $this->assertSame([[true, false], false], AgregatPenerimaService::topengTabel([3, 0], 5, marginalDiketahui: false));
    }

    public function test_nonaktif_kecil_menyamarkan_total_dan_aktif_tetap_terlihat(): void
    {
        $data = [
            'sppg_id' => 1, 'total' => 52, 'aktif' => 50,
            'per_kelompok_sasaran' => [['kelompok_sasaran' => 'siswa', 'label' => 'Siswa', 'jumlah' => 50]],
            'per_titik_layanan' => [['titik_layanan_id' => 1, 'nama' => 'SDN Fiktif', 'jumlah' => 50]],
        ];

        $hasil = AgregatPenerimaService::samarkan($data, 5);

        $this->assertNull($hasil['total']);
        $this->assertSame('<5', $hasil['total_penanda']);
        $this->assertSame(50, $hasil['aktif']);
        $this->assertSame(50, $hasil['per_kelompok_sasaran'][0]['jumlah']);
    }

    // ---------------------------------------------------------------- S-04

    public function test_titik_layanan_terhapus_tidak_tampil_di_agregat(): void
    {
        $hidup = $this->titik('SDN Fiktif Hidup');
        $mati = $this->titik('SDN Fiktif Sudah Dihapus');
        $this->penerima($hidup, 'siswa', 6);
        $this->penerima($mati, 'siswa', 7);
        $mati->delete();

        foreach ([[$this->kepalaA, null], [$this->investorAB, $this->sppgA]] as [$peran, $header]) {
            $res = $this->sebagai($peran, $header)->getJson(self::URL)->assertOk()
                ->assertJsonCount(1, 'data.per_titik_layanan')
                ->assertJsonPath('data.per_titik_layanan.0.nama', 'SDN Fiktif Hidup')
                ->assertJsonPath('data.per_titik_layanan.0.jumlah', 6)
                ->assertJsonPath('data.aktif', 13);

            $this->assertStringNotContainsString('Sudah Dihapus', $res->getContent());
        }
    }
}

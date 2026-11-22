<?php

namespace Tests\Feature\PetaHak;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Mengunci keluaran GET /auth/me -> user (peta hak) per peran: `unit_scope`, `aksi`, `modules`, `permissions`, `keputusan`,
 * `agregat`, `baca_saja`. Dua jangkar:
 *  1. Snapshot golden `tests/Fixtures/peta-hak/auth-me.json` (kontrak server; perubahan hak WAJIB disengaja: perbarui snapshot
 *     dengan `SIGAP_TULIS_SNAPSHOT=1 php artisan test --filter=PetaHakAuthMeKontrakTest` lalu tinjau diff-nya).
 *  2. Fixture frontend `resources/miniapp/tests/fixtures/peta.js` (ditulis tangan oleh frontend-dev). Dibaca lewat Node
 *     (dilewati bila `node` tidak ada); gagal bila server berbeda dari fixture sehingga klien tidak diuji terhadap peta palsu.
 */
class PetaHakAuthMeKontrakTest extends PetaHakTestCase
{
    private const SNAPSHOT = __DIR__.'/../../Fixtures/peta-hak/auth-me.json';

    private const FIXTURE_JS = __DIR__.'/../../../resources/miniapp/tests/fixtures/peta.js';

    private const KUNCI = ['role', 'unit', 'baca_saja', 'modules', 'permissions', 'keputusan', 'agregat', 'unit_scope', 'aksi'];

    /** Nama profil tes PHP (PetaHakTestCase::profil) -> nama profil fixture JS. */
    private const PEMETAAN = [
        'admin' => 'admin', 'kepala_sppg' => 'kepala_sppg', 'ahli_gizi' => 'ahli_gizi', 'akuntan' => 'akuntan',
        'staf_gudang' => 'staf_gudang', 'relawan.dapur' => 'relawan_dapur', 'relawan.distribusi' => 'relawan_distribusi',
        'relawan.tanpa_unit' => 'relawan_tanpa_unit', 'investor' => 'investor',
    ];

    /** @return array<string, array<string, mixed>> nama fixture JS => bagian /auth/me yang dikunci (kanonik: kunci diurutkan) */
    private function keluaranServer(): array
    {
        $hasil = [];

        foreach ($this->profil() as $nama => $user) {
            $json = $this->masuk($user)->getJson('/api/v1/auth/me')->assertOk()->json('user');
            $hasil[self::PEMETAAN[$nama]] = $this->kanonik(array_intersect_key($json, array_flip(self::KUNCI)));
        }

        return $hasil;
    }

    /**
     * Urutan `modules` dan kunci objek tidak bermakna: diurutkan. Daftar lain (keputusan, agregat) tetap.
     *
     * @param  array<string, mixed>  $bagian
     * @return array<string, mixed>
     */
    private function kanonik(array $bagian): array
    {
        sort($bagian['modules']);
        ksort($bagian['permissions']);
        ksort($bagian['unit_scope']);
        ksort($bagian['aksi']);
        ksort($bagian);

        return $bagian;
    }

    public function test_keluaran_auth_me_per_peran_sama_dengan_snapshot(): void
    {
        $server = $this->keluaranServer();

        if (getenv('SIGAP_TULIS_SNAPSHOT') === '1') {
            // unit_scope kosong ditulis sebagai objek `{}` (bentuk di kawat), bukan `[]`.
            $tulis = array_map(fn (array $b) => ['unit_scope' => (object) $b['unit_scope']] + $b, $server);
            file_put_contents(self::SNAPSHOT, json_encode($tulis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestSkipped('Snapshot ditulis ulang; tinjau diff lalu jalankan ulang tanpa SIGAP_TULIS_SNAPSHOT.');
        }

        $mentahSnapshot = (string) file_get_contents(self::SNAPSHOT);
        $snapshot = json_decode($mentahSnapshot, true, flags: JSON_THROW_ON_ERROR);
        // Snapshot sendiri menyimpan bentuk kawat: unit_scope kosong = `{}`.
        $this->assertStringNotContainsString('"unit_scope": []', $mentahSnapshot);
        $this->assertStringContainsString('"unit_scope": {}', $mentahSnapshot);

        $this->assertSame(array_keys(self::PEMETAAN), array_keys($this->profil()), 'daftar profil berubah');
        $this->assertEqualsCanonicalizing(array_values(self::PEMETAAN), array_keys($snapshot));

        foreach ($server as $nama => $bagian) {
            $this->assertSame($this->kanonik($snapshot[$nama]), $bagian, "auth/me {$nama} berbeda dari snapshot");
        }
    }

    public function test_unit_scope_selalu_objek_json_dan_peta_sesuai_peran(): void
    {
        $server = $this->keluaranServer();

        $this->assertSame(['jadwal' => 'produksi'], $server['relawan_dapur']['unit_scope']);
        $this->assertSame(['jadwal' => 'distribusi'], $server['relawan_distribusi']['unit_scope']);

        foreach (['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'relawan_tanpa_unit', 'investor'] as $nama) {
            $this->assertSame([], $server[$nama]['unit_scope'], $nama);
        }

        // Bentuk di kawat: `unit_scope` selalu OBJEK JSON (`{}`, bukan `[]`); decode assoc tidak membedakannya.
        foreach ($this->profil() as $nama => $user) {
            $mentah = $this->masuk($user)->getJson('/api/v1/auth/me')->assertOk()->getContent();
            $harapan = in_array($nama, ['relawan.dapur', 'relawan.distribusi'], true) ? '"unit_scope":{"jadwal":"' : '"unit_scope":{}';
            $this->assertStringContainsString($harapan, $mentah, "{$nama}: unit_scope harus objek JSON");
            $this->assertStringNotContainsString('"unit_scope":[]', $mentah, $nama);
        }

        // Relawan tanpa unit tidak mendapat hak jadwal/bukti/log sama sekali; dashboard tidak tersedia.
        $this->assertArrayNotHasKey('jadwal', $server['relawan_tanpa_unit']['permissions']);
        $this->assertArrayNotHasKey('bukti-distribusi', $server['relawan_tanpa_unit']['permissions']);
        $this->assertFalse($server['relawan_tanpa_unit']['aksi']['dashboard']['tersedia']);
        $this->assertTrue($server['investor']['baca_saja']);
    }

    public function test_server_sama_dengan_fixture_frontend_peta_js(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null || ! is_file(self::FIXTURE_JS)) {
            $this->markTestSkipped('node atau fixture peta.js tidak tersedia.');
        }

        // Impor dinamis lewat URL file:// agar jalur berkas Windows aman.
        $jalur = ltrim(str_replace(chr(92), '/', (string) realpath(self::FIXTURE_JS)), '/');
        $proses = new Process([$node, '--input-type=module', '-e',
            'const m = await import(process.argv[1]); console.log(JSON.stringify(m.PROFIL));',
            'file:///'.$jalur], null, null, null, 30);
        $proses->run();
        $this->assertTrue($proses->isSuccessful(), 'gagal memuat fixture: '.$proses->getErrorOutput());

        $fixture = json_decode($proses->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $server = $this->keluaranServer();

        $this->assertEqualsCanonicalizing(array_keys($server), array_keys($fixture), 'profil fixture JS berbeda dari profil server');

        foreach ($server as $nama => $bagian) {
            $this->assertSame(
                $bagian,
                $this->kanonik(array_intersect_key($fixture[$nama], array_flip(self::KUNCI))),
                "fixture peta.js profil {$nama} berbeda dari keluaran server /auth/me",
            );
        }
    }
}

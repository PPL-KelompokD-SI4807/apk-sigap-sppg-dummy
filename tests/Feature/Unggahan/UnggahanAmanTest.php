<?php

namespace Tests\Feature\Unggahan;

use App\Enums\Role;
use App\Models\Lampiran;
use App\Models\Pengeluaran;
use App\Services\Storage\KetatPemrosesGambar;
use App\Services\Storage\SecureUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GambarUji;
use Tests\Support\SkenarioOperasional;
use Tests\TestCase;

/** TUGAS 0: SecureUploadService + /api/v1/lampiran (NFR-06). */
class UnggahanAmanTest extends TestCase
{
    use GambarUji, RefreshDatabase, SkenarioOperasional;

    private const POLA_PATH = '#^uploads/%d/keuangan-pengeluaran/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.%s$#';

    private int $sppgA;

    private int $sppgB;

    private Pengeluaran $pengeluaran;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
        $this->pengeluaran = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA]);
    }

    private function unggah(mixed $berkas, ?int $id = null): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/lampiran', [
            'modul' => 'keuangan-pengeluaran',
            'lampiranable_id' => $id ?? $this->pengeluaran->id,
            'kategori' => 'nota',
            'berkas' => $berkas,
        ], ['Accept' => 'application/json']);
    }

    private function sebagai(Role $role, ?int $sppg = null): void
    {
        Sanctum::actingAs($this->pengguna($role, $sppg ?? $this->sppgA));
    }

    private function assertTidakAdaBerkas(): void
    {
        $this->assertSame(0, Lampiran::query()->withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_gambar_valid_tersimpan_privat_dengan_hash_ukuran_dan_pengunggah(): void
    {
        $akuntan = $this->pengguna(Role::Akuntan, $this->sppgA);
        Sanctum::actingAs($akuntan);

        $res = $this->unggah($this->fotoJpeg())
            ->assertCreated()
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.kategori', 'nota')
            ->assertJsonPath('data.uploaded_by', $akuntan->id)
            ->assertJsonMissingPath('data.path');

        $lampiran = Lampiran::query()->findOrFail($res->json('data.id'));
        $this->assertMatchesRegularExpression(sprintf(self::POLA_PATH, $this->sppgA, 'jpg'), $lampiran->path);
        $isi = Storage::disk('uploads')->get($lampiran->path);
        $this->assertSame(hash('sha256', $isi), $lampiran->sha256);
        $this->assertSame(strlen($isi), $lampiran->ukuran);
        $this->assertSame($res->json('data.url'), '/api/v1/lampiran/'.$lampiran->id);
        $this->assertSame(Pengeluaran::class, $lampiran->lampiranable_type);
        $this->assertFalse(Storage::disk('public')->exists($lampiran->path));
    }

    public function test_berkas_php_bertopeng_jpg_ditolak(): void
    {
        $this->sebagai(Role::Akuntan);

        foreach ([
            '<?php system($_GET["c"]); ?>',
            "GIF89a<?php echo 'x'; ?>",
            "\xFF\xD8\xFF\xE0<?php phpinfo(); ?>",
        ] as $isi) {
            $this->unggah($this->berkas($isi, 'shell.jpg', 'image/jpeg'))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('berkas');
        }

        $this->assertTidakAdaBerkas();
    }

    public function test_polyglot_jpeg_dengan_payload_ditolak(): void
    {
        $this->sebagai(Role::Akuntan);
        $jpeg = $this->bytesJpeg(16, 16);
        $komentar = "\xFF\xFE".pack('n', 2 + strlen('<?php eval($_POST[1]); ?>')).'<?php eval($_POST[1]); ?>';

        foreach ([
            $jpeg.'<?php system($_GET[1]); ?>',
            substr($jpeg, 0, 2).$komentar.substr($jpeg, 2),
            $jpeg.'<script>alert(1)</script>',
            $jpeg."PK\x03\x04\x14\x00\x00\x00shell.php",
        ] as $isi) {
            $this->unggah($this->berkas($isi))->assertUnprocessable()->assertJsonValidationErrors('berkas');
        }

        $this->assertTidakAdaBerkas();
    }

    public function test_ekstensi_ganda_ekstensi_dari_isi_bukan_nama(): void
    {
        $this->sebagai(Role::Akuntan);

        foreach (['foto.php.jpg', 'foto.jpg.php', 'foto.phtml', 'foto'] as $nama) {
            $id = $this->unggah($this->berkas($this->bytesJpeg(), $nama, 'application/x-php'))->assertCreated()->json('data.id');
            $path = Lampiran::query()->findOrFail($id)->path;
            $this->assertMatchesRegularExpression(sprintf(self::POLA_PATH, $this->sppgA, 'jpg'), $path);
            $this->assertStringNotContainsString('php', $path);
        }

        $this->unggah($this->berkas('<?php echo 1;', 'foto.php.jpg'))->assertUnprocessable();
    }

    public function test_mime_klien_palsu_diabaikan(): void
    {
        $this->sebagai(Role::Akuntan);

        $this->unggah($this->berkas("bukan gambar\n", 'nota.png', 'image/png'))
            ->assertUnprocessable()->assertJsonValidationErrors('berkas');
        $this->unggah($this->berkas('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'nota.jpg', 'image/jpeg'))
            ->assertUnprocessable();
        $this->unggah($this->berkas('%PDF-1.7 fiktif', 'nota.webp', 'image/webp'))->assertUnprocessable();

        $id = $this->unggah($this->berkas($this->bytesPng(), 'nota.jpg', 'image/jpeg'))->assertCreated()
            ->assertJsonPath('data.mime', 'image/png')->json('data.id');
        $this->assertStringEndsWith('.png', Lampiran::query()->findOrFail($id)->path);

        $this->unggah($this->berkas($this->bytesWebp(), 'nota.jpg'))->assertCreated()->assertJsonPath('data.mime', 'image/webp');
    }

    public function test_berkas_terlalu_besar_dan_nol_byte_ditolak(): void
    {
        $this->sebagai(Role::Akuntan);

        $besar = $this->bytesJpeg().str_repeat("\0", 5 * 1024 * 1024);
        $this->unggah($this->berkas($besar))->assertUnprocessable()->assertJsonValidationErrors('berkas');
        $this->unggah($this->berkas(''))->assertUnprocessable()->assertJsonValidationErrors('berkas');

        // Batas di service juga berlaku (bukan hanya Form Request).
        config(['unggahan.maks_byte' => 100]);

        try {
            app(SecureUploadService::class)->siapkan($this->berkas($this->bytesJpeg(64, 48)));
            $this->fail('Seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('maksimal', $e->errors()['berkas'][0]);
        }

        $this->assertTidakAdaBerkas();
    }

    public function test_nama_berkas_traversal_tidak_dipakai(): void
    {
        $this->sebagai(Role::Akuntan);

        foreach (['../../../../etc/passwd.jpg', '..\\..\\windows\\win.ini.jpg', "foto\0.php.jpg", '/var/www/public/x.jpg'] as $nama) {
            $id = $this->unggah($this->berkas($this->bytesJpeg(), $nama))->assertCreated()->json('data.id');
            $path = Lampiran::query()->findOrFail($id)->path;
            $this->assertMatchesRegularExpression(sprintf(self::POLA_PATH, $this->sppgA, 'jpg'), $path);
        }

        foreach (Storage::disk('uploads')->allFiles() as $f) {
            $this->assertStringStartsWith("uploads/{$this->sppgA}/keuangan-pengeluaran/", $f);
        }
    }

    public function test_dimensi_melebihi_batas_ditolak(): void
    {
        $this->lewatiTanpaGd();
        $this->sebagai(Role::Akuntan);

        $this->unggah($this->berkas($this->bytesPng(8001, 1), 'lebar.png'))
            ->assertUnprocessable()->assertJsonPath('errors.berkas.0', 'Sisi terpanjang gambar maksimal 8000 px.');
        $this->assertTidakAdaBerkas();
    }

    public function test_encode_ulang_membuang_exif_dan_memperkecil_ke_1600(): void
    {
        $this->lewatiTanpaGd();
        $this->sebagai(Role::Akuntan);

        $asli = $this->bytesJpegDenganExif(2000, 1000);
        $this->assertStringContainsString('SIGAP-GPS-FIKTIF', $asli);

        $id = $this->unggah($this->berkas($asli))->assertCreated()->json('data.id');
        $isi = Storage::disk('uploads')->get(Lampiran::query()->findOrFail($id)->path);

        $this->assertStringNotContainsString('SIGAP-GPS-FIKTIF', $isi);
        $this->assertStringNotContainsString('Exif', $isi);
        [$w, $h] = getimagesizefromstring($isi);
        $this->assertSame([1600, 800], [$w, $h]);
    }

    public function test_mode_ketat_tanpa_gd_menolak_metadata_dan_trailer(): void
    {
        $service = new SecureUploadService(new KetatPemrosesGambar);

        $bersih = $service->siapkan($this->berkas($this->bytesJpegBersih()));
        $this->assertFalse($bersih->diencodeUlang);
        $this->assertSame('image/jpeg', $bersih->mime);
        $this->assertSame('image/png', $service->siapkan($this->berkas($this->bytesPng(), 'a.png'))->mime);

        $png = $this->bytesPng();
        $teks = 'tEXt'.'Comment'."\0".'fiktif';
        $chunk = pack('N', strlen($teks) - 4).$teks.pack('N', crc32($teks));
        $pngTeks = substr($png, 0, 33).$chunk.substr($png, 33);

        foreach ([
            $this->bytesJpegDenganExif(4, 3),
            $this->bytesJpeg(),               // memuat segmen komentar (COM)
            $this->bytesJpegBersih()."\x00\x01trailer",
            $pngTeks,
            $png.'trailer',
        ] as $isi) {
            try {
                $service->siapkan($this->berkas($isi));
                $this->fail('Mode ketat seharusnya menolak berkas ini.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('berkas', $e->errors());
            }
        }
    }

    public function test_sajikan_lampiran_dengan_header_aman(): void
    {
        $akuntan = $this->pengguna(Role::Akuntan, $this->sppgA);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        Sanctum::actingAs($akuntan);
        $id = $this->unggah($this->fotoJpeg('rahasia nama asli.jpg'))->json('data.id');
        $isi = Storage::disk('uploads')->get(Lampiran::query()->findOrFail($id)->path);

        Sanctum::actingAs($kepala);
        $res = $this->get("/api/v1/lampiran/{$id}")->assertOk();

        $this->assertSame($isi, $res->getContent());
        $res->assertHeader('Content-Type', 'image/jpeg');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Content-Disposition', 'inline; filename="lampiran.jpg"');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('rahasia', (string) $res->headers->get('Content-Disposition'));

        Sanctum::actingAs($admin);
        $this->get("/api/v1/lampiran/{$id}")->assertOk();
    }

    public function test_sajikan_lampiran_rbac_lintas_sppg_dan_tanpa_login(): void
    {
        $akuntanB = $this->pengguna(Role::Akuntan, $this->sppgB);
        $gudang = $this->pengguna(Role::StafGudang, $this->sppgA);
        $gizi = $this->pengguna(Role::AhliGizi, $this->sppgA);
        $this->sebagai(Role::Akuntan);
        $id = $this->unggah($this->fotoJpeg())->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/lampiran/{$id}")->assertUnauthorized();

        Sanctum::actingAs($akuntanB);
        $this->getJson("/api/v1/lampiran/{$id}")->assertNotFound();

        Sanctum::actingAs($gudang);
        $this->getJson("/api/v1/lampiran/{$id}")->assertForbidden();

        Sanctum::actingAs($gizi);
        $this->getJson("/api/v1/lampiran/{$id}")->assertForbidden();
    }

    public function test_lampiran_induk_terhapus_atau_berkas_diubah_tidak_disajikan(): void
    {
        $this->sebagai(Role::Akuntan);
        $id = $this->unggah($this->fotoJpeg())->json('data.id');
        $lampiran = Lampiran::query()->findOrFail($id);

        Storage::disk('uploads')->put($lampiran->path, $this->bytesPng());
        $this->getJson("/api/v1/lampiran/{$id}")->assertNotFound();

        $id2 = $this->unggah($this->fotoJpeg())->json('data.id');
        $this->getJson("/api/v1/lampiran/{$id2}")->assertOk();
        $this->pengeluaran->delete();
        $this->getJson("/api/v1/lampiran/{$id2}")->assertNotFound();
    }

    public function test_unggah_rbac_induk_lintas_sppg_dan_tanpa_login(): void
    {
        $lain = Pengeluaran::factory()->create(['sppg_id' => $this->sppgB]);

        $this->unggah($this->fotoJpeg())->assertUnauthorized();
        $pengguna = array_map(fn (Role $r) => $this->pengguna($r, $this->sppgA), [Role::KepalaSppg, Role::Admin, Role::StafGudang, Role::AhliGizi]);
        $akuntan = $this->pengguna(Role::Akuntan, $this->sppgA);

        foreach ($pengguna as $u) {
            Sanctum::actingAs($u);
            $this->unggah($this->fotoJpeg())->assertForbidden();
        }

        Sanctum::actingAs($akuntan);
        $this->unggah($this->fotoJpeg(), $lain->id)->assertNotFound();
        $this->post('/api/v1/lampiran', ['modul' => 'users', 'lampiranable_id' => 1, 'berkas' => $this->fotoJpeg()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('modul');

        $this->assertTidakAdaBerkas();
    }

    public function test_rate_limit_unggah(): void
    {
        config(['unggahan.rate_limit_per_menit' => 2]);
        $this->sebagai(Role::Akuntan);

        $this->unggah($this->fotoJpeg())->assertCreated();
        $this->unggah($this->fotoJpeg())->assertCreated();
        $this->unggah($this->fotoJpeg())->assertStatus(429);
    }
}

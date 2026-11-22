<?php

namespace Tests\Feature\Ai;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\AiInteraction;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Ai\Evaluasi\PenilaiKasus;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Llm\NullLlmClient;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * sigap:ai-evaluasi (NFR-11) dengan LlmClient palsu: tanpa jaringan, tanpa data tersisa, galat 429 dicatat per kasus.
 */
class AiEvaluasiTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    /** @var list<string> */
    private array $sementara = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
    }

    protected function tearDown(): void
    {
        foreach ($this->sementara as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function kasusUji(): array
    {
        return [
            ['id' => 'K001', 'modul' => 'keluhan', 'peran' => 'kepala_sppg', 'pesan' => 'Tampilkan keluhan yang berstatus kritis.', 'tool' => 'cari_keluhan', 'argumen' => ['kritis' => true]],
            ['id' => 'K002', 'modul' => 'titik-layanan', 'peran' => 'kepala_sppg', 'pesan' => 'Tambah titik layanan baru bernama SDN Harapan 3, jenis sekolah, jumlah sasaran 240.', 'tool' => 'buat_titik_layanan', 'argumen' => ['nama' => 'SDN Harapan 3', 'jenis' => 'sekolah', 'jumlah_sasaran' => 240]],
            ['id' => 'K003', 'modul' => 'keluhan', 'peran' => 'ahli_gizi', 'pesan' => 'Tampilkan keluhan yang statusnya baru.', 'tool' => 'cari_keluhan', 'argumen' => ['status' => 'baru']],
            ['id' => 'K004', 'modul' => 'armada', 'peran' => 'relawan', 'unit' => 'distribusi', 'pesan' => 'Cari pengemudi bernama Bima.', 'tool' => 'cari_pengemudi', 'argumen' => ['q' => 'Bima']],
        ];
    }

    private function berkasKasus(?array $kasus = null): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sigap-eval-');
        file_put_contents($path, json_encode($kasus ?? $this->kasusUji(), JSON_UNESCAPED_UNICODE));
        $this->sementara[] = $path;

        return $path;
    }

    /** @return array{0: int, 1: string, 2: array<string, mixed>} kode, output, isi berkas hasil */
    private function jalankan(array $opsi = []): array
    {
        $kode = Artisan::call('sigap:ai-evaluasi', ['--jeda' => 0] + $opsi);
        $output = Artisan::output();
        $isi = [];

        if (preg_match('/Hasil disimpan: (.+\.json)/', $output, $m) === 1) {
            $this->sementara[] = $m[1];
            $isi = json_decode((string) file_get_contents($m[1]), true);
        }

        return [$kode, $output, $isi];
    }

    /** Skrip LLM palsu untuk kasusUji(): K001 benar penuh, K002 tool benar 2 dari 3 kolom, K003 tool salah, K004 galat 429. */
    private function skripUji(): void
    {
        $this->llm
            ->push(LlmResponse::toolCall('cari_keluhan', ['kritis' => true]))
            ->push(LlmResponse::toolCall('buat_titik_layanan', ['nama' => ' sdn harapan 3 ', 'jenis' => 'sekolah', 'jumlah_sasaran' => 241]))
            ->push(LlmResponse::toolCall('cari_sppg', ['q' => 'baru']))
            ->push(LlmException::fromStatus(429));
    }

    public function test_hitungan_akurasi_tool_dan_kolom_serta_file_hasil_tersimpan(): void
    {
        $this->skripUji();

        [$kode, $output, $isi] = $this->jalankan(['--berkas' => $this->berkasKasus()]);

        $this->assertSame(0, $kode, $output);
        $this->assertNotSame([], $isi, 'Berkas hasil harus tersimpan di storage/app/ai-eval.');
        $this->assertCount(4, $isi['kasus']);
        $this->assertCount(4, $isi['hasil']);

        $hasil = collect($isi['hasil'])->keyBy('id');
        $this->assertTrue($hasil['K001']['tool_benar']);
        $this->assertSame([1, 1], [$hasil['K001']['kolom_cocok'], $hasil['K001']['kolom_total']]);
        $this->assertTrue($hasil['K002']['tool_benar']);
        $this->assertSame([2, 3], [$hasil['K002']['kolom_cocok'], $hasil['K002']['kolom_total']]);
        $this->assertFalse($hasil['K003']['tool_benar']);
        $this->assertSame('cari_sppg', $hasil['K003']['tool_dipilih']);
        $this->assertSame(0, $hasil['K003']['kolom_cocok']);

        // K004 = galat: tidak ikut penyebut akurasi. Dinilai 3 kasus, 2 tool benar; kolom cocok 3 dari 5.
        $total = $isi['ringkasan']['total'];
        $this->assertSame(4, $total['kasus']);
        $this->assertSame(3, $total['dinilai']);
        $this->assertSame(1, $total['galat']);
        $this->assertSame(2, $total['tool_benar']);
        $this->assertSame(66.7, $total['akurasi_tool']);
        $this->assertSame([3, 5], [$total['kolom_cocok'], $total['kolom_total']]);
        $this->assertSame(60.0, $total['akurasi_kolom']);
        $this->assertSame(2, $isi['ringkasan']['per_modul']['keluhan']['kasus']);

        $this->assertStringContainsString('TOTAL', $output);
        $this->assertStringContainsString('titik-layanan', $output);
    }

    public function test_mulai_dan_batas_memilih_sebagian_kasus(): void
    {
        $this->llm
            ->push(LlmResponse::toolCall('buat_titik_layanan', ['nama' => 'SDN Harapan 3', 'jenis' => 'sekolah', 'jumlah_sasaran' => 240]))
            ->push(LlmResponse::toolCall('cari_keluhan', ['status' => 'baru']));

        [$kode, , $isi] = $this->jalankan(['--berkas' => $this->berkasKasus(), '--mulai' => 2, '--batas' => 2]);

        $this->assertSame(0, $kode);
        $this->assertSame(['K002', 'K003'], array_column($isi['hasil'], 'id'));
        $this->assertSame(['K002', 'K003'], array_column($isi['kasus'], 'id'));
        $this->assertSame(2, $isi['ringkasan']['mulai']);
        $this->assertSame(2, $isi['ringkasan']['batas']);
        $this->assertSame(2, $isi['ringkasan']['total']['tool_benar']);
        $this->assertCount(2, $this->llm->calls, 'Hanya dua kasus yang diproses; iterasi ringkasan tidak ke penyedia.');
    }

    public function test_tidak_ada_data_tersimpan_permanen_walau_draf_dibuat(): void
    {
        $this->skripUji();
        $sebelum = [AiDraft::query()->count(), AiInteraction::query()->count(), User::query()->count(), Sppg::query()->count()];

        [$kode, , $isi] = $this->jalankan(['--berkas' => $this->berkasKasus()]);

        $this->assertSame(0, $kode);
        $this->assertSame($sebelum, [AiDraft::query()->count(), AiInteraction::query()->count(), User::query()->count(), Sppg::query()->count()]);
        $this->assertTrue($isi['ringkasan']['data_tidak_berubah']);

        // Pipeline benar-benar menghasilkan AiDraft pada K002 (tool tulis = draf saja), lalu draf ikut di-rollback.
        $k002 = collect($isi['hasil'])->firstWhere('id', 'K002');
        $this->assertGreaterThanOrEqual(1, $k002['draf_dibuat_lalu_dibatalkan']);
        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('titik_layanans')->count(), 'Aksi tulis tidak pernah dieksekusi.');
    }

    public function test_429_dicatat_sebagai_galat_tanpa_menghentikan_run(): void
    {
        $this->llm
            ->push(LlmException::fromStatus(429))
            ->push(LlmResponse::toolCall('cari_keluhan', ['status' => 'baru']));

        [$kode, , $isi] = $this->jalankan(['--berkas' => $this->berkasKasus(), '--mulai' => 1, '--batas' => 3]);

        $this->assertSame(0, $kode);
        $hasil = collect($isi['hasil'])->keyBy('id');
        $this->assertSame('galat', $hasil['K001']['status']);
        $this->assertNotNull($hasil['K001']['galat']);
        $this->assertStringContainsString('429', (string) $hasil['K001']['galat']);
        $this->assertFalse($hasil['K001']['tool_benar']);
        $this->assertSame('ok', $hasil['K002']['status'], 'Run berlanjut ke kasus berikutnya setelah galat.');
        $this->assertSame(1, $isi['ringkasan']['total']['galat']);
    }

    public function test_menolak_bila_llm_tidak_dikonfigurasi(): void
    {
        config(['ai.provider' => '']);
        $this->app->instance(LlmClient::class, new NullLlmClient);

        $kode = Artisan::call('sigap:ai-evaluasi', ['--jeda' => 0, '--berkas' => $this->berkasKasus()]);

        $this->assertSame(1, $kode);
        $this->assertStringContainsString('nonaktif', Artisan::output());
        $this->assertNull($this->llm->calls[0] ?? null);
    }

    public function test_menolak_bila_kunci_llm_kosong_pada_provider_openai_compat(): void
    {
        config(['ai.provider' => 'openai_compat', 'ai.openai_compat.base_url' => 'https://llm.test/v1', 'ai.openai_compat.api_key' => null, 'ai.openai_compat.model' => 'm']);

        $kode = Artisan::call('sigap:ai-evaluasi', ['--jeda' => 0, '--berkas' => $this->berkasKasus()]);

        $this->assertSame(1, $kode);
        $this->assertStringContainsString('LLM_API_KEY', Artisan::output());
    }

    public function test_opsi_dan_berkas_tidak_valid_ditolak(): void
    {
        $this->assertSame(1, Artisan::call('sigap:ai-evaluasi', ['--jeda' => 0, '--mulai' => 0, '--berkas' => $this->berkasKasus()]));
        $this->assertSame(1, Artisan::call('sigap:ai-evaluasi', ['--jeda' => 0, '--berkas' => '/tidak/ada.json']));
        $this->assertSame(1, Artisan::call('sigap:ai-evaluasi', ['--jeda' => 0, '--mulai' => 99, '--berkas' => $this->berkasKasus()]));
        $this->assertCount(0, $this->llm->calls);
    }

    public function test_tidak_pernah_dijadwalkan_otomatis(): void
    {
        $perintah = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

        $this->assertStringNotContainsString('ai-evaluasi', $perintah);
    }

    public function test_penilai_menormalkan_teks_angka_tanggal_jam_dan_boolean(): void
    {
        $this->assertTrue(PenilaiKasus::sama('Tani Makmur', '  tani   makmur '));
        $this->assertTrue(PenilaiKasus::sama(50, '50'));
        $this->assertTrue(PenilaiKasus::sama(3.5, 3.5));
        $this->assertFalse(PenilaiKasus::sama(3.5, 3.6));
        $this->assertTrue(PenilaiKasus::sama('2026-12-10', '2026-12-10T00:00:00'));
        $this->assertFalse(PenilaiKasus::sama('2026-12-10', '10 Desember 2026'));
        $this->assertTrue(PenilaiKasus::sama('08:15', '08:15:00'));
        $this->assertTrue(PenilaiKasus::sama('08:15', '8:15'));
        $this->assertTrue(PenilaiKasus::sama(true, 'true'));
        $this->assertTrue(PenilaiKasus::sama(false, false));
        $this->assertFalse(PenilaiKasus::sama(true, 'false'));
        $this->assertTrue(PenilaiKasus::sama(['nama' => 'Ayam', 'jumlah' => 2], ['nama' => 'ayam', 'jumlah' => 2.0, 'satuan' => 'kg']));
        $this->assertFalse(PenilaiKasus::sama('baru', null));

        $nilai = PenilaiKasus::nilai('cari_keluhan', ['status' => 'baru'], 'cari_sppg', ['status' => 'baru']);
        $this->assertFalse($nilai['tool_benar']);
        $this->assertSame(0, $nilai['kolom_cocok']);
    }

    public function test_berkas_kasus_resmi_100_kasus_valid_terhadap_tool_terdaftar(): void
    {
        $kasus = json_decode((string) file_get_contents(base_path('tests/ai-eval/kasus.json')), true);
        $this->assertIsArray($kasus);
        $this->assertCount(100, $kasus);

        $registry = app(ToolRegistry::class);
        $moduleResmi = array_slice(array_keys((array) config('rbac.modules')), 0, 16);
        $perModul = array_fill_keys($moduleResmi, 0);
        $ids = [];

        foreach ($kasus as $k) {
            $ids[] = $k['id'];
            $this->assertMatchesRegularExpression('/^K\d{3}$/', $k['id']);
            $this->assertArrayHasKey($k['modul'], $perModul, $k['id'].': modul harus salah satu dari 16 fitur.');
            $perModul[$k['modul']]++;
            $this->assertDoesNotMatchRegularExpression('/\d{16}/', $k['pesan'], $k['id'].': tanpa NIK.');

            $role = Role::from($k['peran']);
            $user = new User;
            $user->forceFill([
                'name' => 'Uji', 'role' => $role, 'is_active' => true,
                'unit' => $role === Role::Relawan ? RelawanUnit::from($k['unit']) : null,
                'sppg_id' => $role->tanpaSppgId() ? null : 1,
            ]);

            $tools = $registry->forUser($user);
            $this->assertArrayHasKey($k['tool'], $tools, $k['id'].': tool tidak terdaftar atau peran tanpa hak.');

            $properti = array_keys((array) ($tools[$k['tool']]->parameters()['properties'] ?? []));

            foreach (array_keys($k['argumen']) as $nama) {
                $this->assertContains($nama, $properti, $k['id'].': argumen '.$nama.' tidak ada di skema '.$k['tool']);
            }
        }

        $this->assertCount(100, array_unique($ids));

        foreach ($perModul as $modul => $jumlah) {
            $this->assertGreaterThanOrEqual(5, $jumlah, "Modul {$modul} minimal 5 kasus.");
        }
    }
}

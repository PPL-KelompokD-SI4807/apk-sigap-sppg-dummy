<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Models\BahanBaku;
use App\Models\BotAksi;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Rekap\DailySummaryService;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\Bot\BotRingkasanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

/**
 * Ringkasan hari ini di beranda/sambutan (R02-A2, bagian 3.10 dan 3.5): disaring per peran, hanya SPPG efektif
 * pengguna, hanya hitungan/persentase/status (tanpa data pribadi atau uraian), dan tidak dihitung tanpa sesi penuh.
 */
class BotRingkasanTest extends BotTestCase
{
    private string $hari;

    private string $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(BotLayarRegistry::class, new BotLayarRegistry([...BotLayarRegistry::layarBawaan(), ...LayarUji::untukMenu()]));
        $zona = app(DailySummaryService::class)->zona();
        $this->hari = Carbon::now($zona)->toDateString();
        $this->periode = Carbon::now($zona)->format('Y-m');
        $this->forgetActor();

        // SPPG A: produksi 2 (target 1.000), distribusi 2 (tiba 1), stok menipis 1, keluhan 2 (kritis 1), serapan 85%.
        $this->isi($this->sppgA, produksi: [600, 400], distribusi: 2, menipis: 1, keluhan: 2, kritis: 1, cair: 10_000_000, keluar: 8_500_000);
        // SPPG B: angka sengaja berbeda semua agar kebocoran lintas SPPG terlihat.
        $this->isi($this->sppgB, produksi: [100, 100, 100, 100, 100, 100, 100], distribusi: 3, menipis: 3, keluhan: 5, kritis: 2, cair: 4_000_000, keluar: 1_000_000);
    }

    /** @param list<int> $produksi target porsi per jadwal */
    private function isi(int $sppgId, array $produksi, int $distribusi, int $menipis, int $keluhan, int $kritis, int $cair, int $keluar): void
    {
        $this->forgetActor();
        $jadwal = null;

        foreach ($produksi as $target) {
            $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'tanggal_produksi' => $this->hari, 'target_porsi' => $target]);
        }

        for ($i = 0; $i < $distribusi; $i++) {
            JadwalDistribusi::factory()->create([
                'sppg_id' => $sppgId, 'jadwal_produksi_id' => $jadwal->id, 'tanggal_distribusi' => $this->hari,
                'status' => $i === 0 ? StatusJadwalDistribusi::Diterima : StatusJadwalDistribusi::Siap,
            ]);
        }

        BahanBaku::factory()->count($menipis)->create(['sppg_id' => $sppgId, 'stok' => 1, 'stok_minimum' => 5, 'kedaluwarsa_terdekat' => null]);
        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'stok' => 100, 'stok_minimum' => 5, 'kedaluwarsa_terdekat' => null]);

        FeedbackKeluhan::factory()->count($keluhan - $kritis)->create(['sppg_id' => $sppgId, 'created_at' => $this->hari.' 09:00:00']);
        FeedbackKeluhan::factory()->count($kritis)->kritis()->create(['sppg_id' => $sppgId, 'created_at' => $this->hari.' 10:00:00']);

        PencairanDana::factory()->create(['sppg_id' => $sppgId, 'periode' => $this->periode, 'nominal' => $cair, 'tanggal_cair' => $this->periode.'-01']);
        Pengeluaran::factory()->create(['sppg_id' => $sppgId, 'tanggal' => $this->hari, 'nominal' => $keluar]);
    }

    private function beranda(User $user, ?string $pilih = null): string
    {
        $this->sesiPenuh($user);
        $this->ketik($user, '/menu');

        if ($pilih !== null) {
            $this->tekan($user, $this->dataTombol($user, 'Pilih SPPG'));
            $this->tekan($user, $this->dataTombol($user, $pilih));
        }

        return $this->teksLayar($user);
    }

    public function test_ringkasan_kepala_hanya_memuat_sppg_sendiri(): void
    {
        $a = $this->beranda($this->kepalaA);

        $this->assertStringContainsString('Ringkasan hari ini', $a);
        $this->assertStringContainsString('Jadwal produksi: 2 (target 1.000 porsi)', $a);
        $this->assertStringContainsString('Jadwal distribusi: 2 (tiba 1)', $a);
        $this->assertStringContainsString('Stok: 1 menipis', $a);
        $this->assertStringContainsString('Keluhan terbuka: 2 (kritis baru hari ini: 1)', $a);
        $this->assertStringContainsString("Anggaran {$this->periode}: serapan 85,0% (PERINGATAN, mencapai 80%)", $a);

        $b = $this->beranda($this->kepalaB);
        $this->assertStringContainsString('Jadwal produksi: 7 (target 700 porsi)', $b);
        $this->assertStringContainsString('Stok: 3 menipis', $b);
        $this->assertStringContainsString('Keluhan terbuka: 5 (kritis baru hari ini: 2)', $b);
        $this->assertStringContainsString('serapan 25,0% (aman)', $b);

        foreach (['target 700', '3 menipis', 'terbuka: 5', '25,0%'] as $punyaB) {
            $this->assertStringNotContainsString($punyaB, $a, 'Data SPPG B tidak boleh bocor ke kepala SPPG A');
        }

        foreach (['target 1.000', 'terbuka: 2', '85,0%'] as $punyaA) {
            $this->assertStringNotContainsString($punyaA, $b);
        }
    }

    public function test_ringkasan_disaring_per_peran(): void
    {
        $gudang = $this->beranda($this->stafGudangA);
        $this->assertStringContainsString('Jadwal produksi', $gudang);
        $this->assertStringContainsString('Stok: 1 menipis', $gudang);
        $this->assertStringNotContainsString('Keluhan', $gudang);
        $this->assertStringNotContainsString('Anggaran', $gudang);

        $akuntan = $this->beranda($this->akuntanA);
        $this->assertStringContainsString('Anggaran', $akuntan);
        $this->assertStringContainsString('Stok: 1 menipis', $akuntan);
        $this->assertStringNotContainsString('Jadwal', $akuntan);
        $this->assertStringNotContainsString('Keluhan', $akuntan);

        $gizi = $this->beranda($this->ahliGiziA);
        $this->assertStringContainsString('Jadwal produksi', $gizi);
        $this->assertStringContainsString('Keluhan terbuka: 2', $gizi);
        $this->assertStringNotContainsString('Anggaran', $gizi);
    }

    public function test_ringkasan_relawan_hanya_jadwal_unit_dan_log_milik_sendiri(): void
    {
        $this->forgetActor();
        $kemarin = Carbon::parse($this->hari)->subDay()->toDateString();
        $log = function (User $oleh) use ($kemarin): void {
            $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'tanggal_produksi' => $kemarin]);
            LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_produksi_id' => $jadwal->id, 'dicatat_oleh' => $oleh->id, 'created_at' => $this->hari.' 08:00:00']);
        };
        $log($this->relawanA);
        $log($this->relawanA);
        $log($this->kepalaA);

        $dapur = $this->beranda($this->relawanA);
        $this->assertStringContainsString('Jadwal produksi: 2', $dapur);
        $this->assertStringContainsString('Log produksi saya hari ini: 2', $dapur);
        foreach (['distribusi', 'Stok', 'Keluhan', 'Anggaran'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $dapur);
        }

        $distribusi = $this->beranda($this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi));
        $this->assertStringContainsString('Jadwal distribusi: 2', $distribusi);
        $this->assertStringNotContainsString('Jadwal produksi', $distribusi);
        $this->assertStringNotContainsString('Log produksi saya', $distribusi);

        $tanpaUnit = $this->userOf(Role::Relawan, $this->sppgA);
        $tanpaUnit->forceFill(['unit' => null])->saveQuietly();
        $teks = $this->beranda($tanpaUnit->fresh());
        $this->assertStringNotContainsString('Ringkasan hari ini', $teks, 'Tanpa unit tidak ada bagian ringkasan');
        $this->assertStringContainsString('Menu SiGAP', $teks);
    }

    public function test_ringkasan_investor_antrean_persetujuan_dan_anggaran_tanpa_jadwal(): void
    {
        $this->pengajuanMenu();
        $this->pengeluaranMenunggu();

        $teks = $this->beranda($this->investorAB, 'SPPG A');

        $this->assertStringContainsString('Menunggu keputusan: menu 1, pengeluaran 1', $teks);
        $this->assertStringContainsString('Anggaran', $teks);
        $this->assertStringContainsString('Stok:', $teks);
        $this->assertStringNotContainsString('Jadwal', $teks, 'Investor tidak punya hak jadwal');
        $this->assertStringNotContainsString('Nasi Telur Uji', $teks);
        $this->assertStringNotContainsString('rahasia-uraian', $teks);

        // SPPG C (milik investor C) tidak punya antrean: angka A tidak bocor.
        $c = $this->beranda($this->investorC);
        $this->assertStringContainsString('Menunggu keputusan: menu 0, pengeluaran 0', $c);
    }

    public function test_admin_ringkasan_hanya_setelah_memilih_sppg_dan_hanya_sppg_itu(): void
    {
        $sebelum = $this->beranda($this->admin);
        $this->assertStringNotContainsString('Ringkasan hari ini', $sebelum);
        $this->assertStringContainsString('Pilih SPPG terlebih dahulu', $sebelum);

        $this->tekan($this->admin, $this->dataTombol($this->admin, 'Pilih SPPG'));
        $this->tekan($this->admin, $this->dataTombol($this->admin, 'SPPG B'));
        $teks = $this->teksLayar($this->admin);

        $this->assertStringContainsString('SPPG B', $teks);
        $this->assertStringContainsString('Jadwal produksi: 7 (target 700 porsi)', $teks);
        $this->assertStringNotContainsString('target 1.000', $teks);
    }

    public function test_ringkasan_tidak_memuat_data_pribadi_atau_uraian(): void
    {
        $this->pengajuanMenu();
        $this->pengeluaranMenunggu();
        $semua = [$this->admin, $this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA, $this->investorAB, $this->investorC];

        foreach ($semua as $user) {
            $teks = $this->beranda($user, $user->isAdmin() || $user->is($this->investorAB) ? 'SPPG A' : null);

            foreach ([$user->name, 'rahasia-uraian', 'Gas elpiji', 'Nasi Telur Uji', 'Rp', '@'] as $terlarang) {
                $this->assertStringNotContainsString((string) $terlarang, $teks, "{$user->role->value}: {$terlarang}");
            }

            $this->assertSame(0, preg_match('/\d{8,}/', $teks), 'Tanpa deret angka panjang (telepon/NIK/nominal)');
        }
    }

    public function test_cache_per_sppg_dan_profil_peran_tanpa_lintas_peran(): void
    {
        config(['telegram.ringkasan_cache_detik' => 60]);
        Cache::flush();

        $this->beranda($this->kepalaA);
        $this->beranda($this->akuntanA);

        $kunci = fn (string $profil, int $sppg) => "bot:ringkasan:v1:{$sppg}:{$this->hari}:{$profil}";
        $kepala = Cache::get($kunci('kepala_sppg', $this->sppgA));
        $akuntan = Cache::get($kunci('akuntan', $this->sppgA));

        $this->assertIsArray($kepala);
        $this->assertIsArray($akuntan);
        $this->assertStringContainsString('Keluhan', implode("\n", $kepala));
        $this->assertStringNotContainsString('Keluhan', implode("\n", $akuntan), 'Cache akuntan tidak memuat bagian peran lain');
        $this->assertStringNotContainsString('Jadwal', implode("\n", $akuntan));
        $this->assertNull(Cache::get($kunci('kepala_sppg', $this->sppgB)), 'Kunci terikat SPPG');

        // Data berubah: dalam jendela cache tampilan lama; SPPG B dihitung sendiri (bukan salinan A).
        $this->forgetActor();
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'created_at' => $this->hari.' 11:00:00']);
        $this->assertStringContainsString('Keluhan terbuka: 2', $this->beranda($this->kepalaA));
        $this->assertStringContainsString('Keluhan terbuka: 5', $this->beranda($this->kepalaB));

        Cache::flush();
        $this->assertStringContainsString('Keluhan terbuka: 3', $this->beranda($this->kepalaA));
    }

    public function test_tanpa_sesi_penuh_ringkasan_tidak_dihitung_di_semua_jalur(): void
    {
        $this->mock(BotRingkasanService::class, fn (MockInterface $m) => $m->shouldNotReceive('untuk'));

        foreach ([$this->kepalaA, $this->investorC, $this->admin] as $user) {
            $uid = (int) $user->telegram_id;

            foreach (['/start', '/menu', '/bantuan', '/jadwal'] as $perintah) {
                $this->ketik($user, $perintah);
            }

            $this->tekan($user, BotNavCodec::data(BotLayarRegistry::BERANDA, null, $uid));
            $this->tekan($user, BotNavCodec::data(BotLayarRegistry::BERANDA, $this->sppgA, $uid, time() - 31 * 60));
        }

        $this->assertSame(0, BotAksi::query()->count());
    }
}

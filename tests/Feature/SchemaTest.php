<?php

namespace Tests\Feature;

use App\Enums\HasilMutu;
use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\AiInteraction;
use App\Models\Armada;
use App\Models\AuditLog;
use App\Models\BahanBaku;
use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\Invitation;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\Lampiran;
use App\Models\LogProduksi;
use App\Models\Menu;
use App\Models\MenuBahan;
use App\Models\MutasiStok;
use App\Models\NotifikasiPerjalanan;
use App\Models\Pemasok;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\PenerimaManfaat;
use App\Models\Pengeluaran;
use App\Models\Pengemudi;
use App\Models\RekapHarian;
use App\Models\Sppg;
use App\Models\StandarGizi;
use App\Models\TelegramUpdate;
use App\Models\TitikLayanan;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string}> */
    public static function modelProvider(): array
    {
        $kelas = [
            Sppg::class, TitikLayanan::class, PenerimaManfaat::class, Pemasok::class, BahanBaku::class,
            MutasiStok::class, Menu::class, StandarGizi::class, JadwalProduksi::class, LogProduksi::class,
            PemeriksaanMutu::class, Armada::class, Pengemudi::class, JadwalDistribusi::class,
            NotifikasiPerjalanan::class, BuktiDistribusi::class, PencairanDana::class, Pengeluaran::class,
            FeedbackKeluhan::class, RekapHarian::class, Lampiran::class, AuditLog::class,
            AiInteraction::class, AiDraft::class, Invitation::class, TelegramUpdate::class,
        ];

        return array_combine(array_map(fn ($k) => class_basename($k), $kelas), array_map(fn ($k) => [$k], $kelas));
    }

    public function test_semua_tabel_skema_terbentuk(): void
    {
        $tabel = [
            'sppgs', 'titik_layanans', 'penerima_manfaats', 'pemasoks', 'bahan_bakus', 'mutasi_stoks', 'menus',
            'menu_bahan', 'standar_gizis', 'jadwal_produksis', 'log_produksis', 'pemeriksaan_mutus', 'armadas',
            'pengemudis', 'jadwal_distribusis', 'notifikasi_perjalanans', 'bukti_distribusis', 'pencairan_danas',
            'pengeluarans', 'feedback_keluhans', 'rekap_harians', 'audit_logs', 'ai_interactions', 'ai_drafts',
            'invitations', 'telegram_updates', 'lampirans',
        ];

        foreach ($tabel as $nama) {
            $this->assertTrue(Schema::hasTable($nama), "Tabel {$nama} tidak ada");
        }

        // UU PDP: tidak ada kolom NIK di tabel mana pun.
        foreach (Schema::getTableListing() as $nama) {
            foreach (Schema::getColumnListing($nama) as $kolom) {
                $this->assertStringNotContainsStringIgnoringCase('nik', $kolom, "Kolom NIK terdeteksi di {$nama}");
            }
        }

        // Semua tabel transaksi punya sppg_id.
        foreach (['titik_layanans', 'penerima_manfaats', 'pemasoks', 'bahan_bakus', 'mutasi_stoks', 'menus', 'standar_gizis',
            'jadwal_produksis', 'log_produksis', 'pemeriksaan_mutus', 'armadas', 'pengemudis', 'jadwal_distribusis',
            'notifikasi_perjalanans', 'bukti_distribusis', 'pencairan_danas', 'pengeluarans', 'feedback_keluhans',
            'rekap_harians', 'ai_drafts', 'lampirans'] as $nama) {
            $this->assertTrue(Schema::hasColumn($nama, 'sppg_id'), "{$nama} tanpa sppg_id");
        }
    }

    public function test_users_sppg_id_memiliki_foreign_key_ke_sppgs(): void
    {
        $fk = collect(Schema::getForeignKeys('users'))->first(fn ($f) => $f['columns'] === ['sppg_id']);

        $this->assertNotNull($fk, 'FK users.sppg_id belum ada');
        $this->assertSame('sppgs', $fk['foreign_table']);
        $this->assertSame('set null', $fk['on_delete']);
    }

    /** @param  class-string  $kelas */
    #[DataProvider('modelProvider')]
    public function test_factory_membuat_record_valid(string $kelas): void
    {
        $model = $kelas::factory()->create();

        $this->assertModelExists($model);
        $this->assertNotNull($model->fresh());
    }

    public function test_factory_menu_bahan(): void
    {
        $mb = MenuBahan::factory()->create();

        $this->assertDatabaseHas('menu_bahan', ['menu_id' => $mb->menu_id, 'bahan_baku_id' => $mb->bahan_baku_id]);
        $this->assertSame($mb->menu->sppg_id, BahanBaku::find($mb->bahan_baku_id)->sppg_id);
    }

    public function test_relasi_utama_berfungsi(): void
    {
        $sppg = Sppg::factory()->create();
        $titik = TitikLayanan::factory()->for($sppg)->create();
        $penerima = PenerimaManfaat::factory()->create(['sppg_id' => $sppg->id, 'titik_layanan_id' => $titik->id]);
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $sppg->id]);
        $dist = JadwalDistribusi::factory()->create(['sppg_id' => $sppg->id, 'jadwal_produksi_id' => $jadwal->id, 'titik_layanan_id' => $titik->id]);
        $bukti = BuktiDistribusi::factory()->create(['sppg_id' => $sppg->id, 'jadwal_distribusi_id' => $dist->id]);
        $notif = NotifikasiPerjalanan::factory()->create(['sppg_id' => $sppg->id, 'jadwal_distribusi_id' => $dist->id]);
        $log = LogProduksi::factory()->create(['sppg_id' => $sppg->id, 'jadwal_produksi_id' => $jadwal->id]);
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $sppg->id, 'jadwal_produksi_id' => $jadwal->id, 'titik_layanan_id' => $titik->id]);

        $this->assertTrue($sppg->titikLayanans->contains($titik));
        $this->assertTrue($titik->penerimaManfaats->contains($penerima));
        $this->assertTrue($sppg->jadwalProduksis->contains($jadwal));
        $this->assertTrue($jadwal->jadwalDistribusis->contains($dist));
        $this->assertTrue($jadwal->logProduksi->is($log));
        $this->assertTrue($jadwal->feedbackKeluhans->contains($keluhan));
        $this->assertTrue($dist->buktiDistribusi->is($bukti));
        $this->assertTrue($dist->notifikasiPerjalanans->contains($notif));
        $this->assertTrue($dist->titikLayanan->is($titik));
        $this->assertTrue($titik->jadwalDistribusis->contains($dist));
        $this->assertTrue($bukti->sppg->is($sppg));
        $this->assertTrue($jadwal->menu->jadwalProduksis->contains($jadwal));
    }

    public function test_komposisi_menu_bahan_dan_standar_gizi(): void
    {
        $sppg = Sppg::factory()->create();
        $menu = Menu::factory()->gizi(480, 14, 9, 80, 5)->create(['sppg_id' => $sppg->id]);
        $bahan = BahanBaku::factory()->create(['sppg_id' => $sppg->id]);
        $menu->bahanBakus()->attach($bahan->id, ['gram_per_porsi' => 90]);

        $this->assertSame('90.00', $menu->bahanBakus()->first()->pivot->gram_per_porsi);
        $this->assertTrue($bahan->menus->contains($menu));

        $standar = StandarGizi::factory()->create(['sppg_id' => $sppg->id]);
        $peringatan = $standar->peringatanUntuk($menu);
        $this->assertSame('kurang', $peringatan['energi_kkal']);
        $this->assertSame('kurang', $peringatan['serat_g']);
    }

    public function test_scope_bahan_dan_pencarian(): void
    {
        $sppg = Sppg::factory()->create();
        BahanBaku::factory()->stokMenipis()->create(['sppg_id' => $sppg->id, 'nama' => 'Beras 100%']);
        BahanBaku::factory()->kedaluwarsa(2)->create(['sppg_id' => $sppg->id, 'nama' => 'Tahu Putih']);
        BahanBaku::factory()->create(['sppg_id' => $sppg->id, 'nama' => 'Minyak', 'stok' => 500, 'stok_minimum' => 10, 'kedaluwarsa_terdekat' => now()->addMonths(3)]);

        $this->assertSame(1, BahanBaku::stokMenipis()->count());
        $this->assertSame(1, BahanBaku::akanKedaluwarsa(3)->count());
        $this->assertSame(1, BahanBaku::search('tahu')->count());
        // Karakter khusus LIKE di-escape: "%" hanya cocok dengan literal "%".
        $this->assertSame(1, BahanBaku::search('100%')->count());
        $this->assertSame(0, BahanBaku::search('%zzz')->count());
        $this->assertSame(3, BahanBaku::search('')->count());
    }

    public function test_gerbang_mutu_dan_keluhan_kritis(): void
    {
        $jadwal = JadwalProduksi::factory()->create();
        $this->assertFalse($jadwal->isLayakDistribusi());

        PemeriksaanMutu::factory()->tidakLayak()->create(['sppg_id' => $jadwal->sppg_id, 'jadwal_produksi_id' => $jadwal->id, 'waktu_pemeriksaan' => now()->subHour()]);
        $this->assertFalse($jadwal->isLayakDistribusi());

        PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $jadwal->sppg_id, 'jadwal_produksi_id' => $jadwal->id, 'waktu_pemeriksaan' => now()]);
        $this->assertTrue($jadwal->isLayakDistribusi());
        $this->assertSame(HasilMutu::Layak, $jadwal->pemeriksaanMutuTerbaru()->hasil);

        $kritis = FeedbackKeluhan::factory()->kritis()->create(['sppg_id' => $jadwal->sppg_id]);
        $this->assertSame(PrioritasKeluhan::Kritis, $kritis->prioritas);
        $this->assertSame(PrioritasKeluhan::Kritis, PrioritasKeluhan::dariKategori(KategoriKeluhan::GejalaSakit));
        $this->assertSame(1, FeedbackKeluhan::kritis()->terbuka()->count());
    }

    public function test_pencairan_terserap_80_dan_100_persen(): void
    {
        $p80 = PencairanDana::factory()->terserap80()->create(['nominal' => 100000000]);
        $p100 = PencairanDana::factory()->terserap100()->create(['nominal' => 50000000]);

        $this->assertSame('80000000.00', number_format((float) $p80->totalPengeluaran(), 2, '.', ''));
        $this->assertSame('50000000.00', number_format((float) $p100->totalPengeluaran(), 2, '.', ''));
    }

    public function test_model_teraudit_menulis_audit_log(): void
    {
        $pengeluaran = Pengeluaran::factory()->create(['nominal' => 100000]);
        $pengeluaran->update(['nominal' => 200000]);
        $pengeluaran->delete();

        $events = AuditLog::where('auditable_id', $pengeluaran->id)
            ->where('auditable_type', $pengeluaran->getMorphClass())
            ->orderBy('id')->pluck('event')->all();

        $this->assertSame(['created', 'updated', 'deleted'], $events);
        $this->assertSoftDeleted($pengeluaran);
        $this->assertSame($pengeluaran->sppg_id, AuditLog::first()->sppg_id);
    }

    public function test_ai_draft_uuid_dan_kedaluwarsa(): void
    {
        $draft = AiDraft::factory()->create();
        $this->assertTrue($draft->bisaDikonfirmasi());
        $this->assertSame(StatusAiDraft::Pending, $draft->status);

        $lama = AiDraft::factory()->kedaluwarsa()->create(['sppg_id' => $draft->sppg_id, 'user_id' => $draft->user_id]);
        $this->assertFalse($lama->bisaDikonfirmasi());
        $this->assertSame(1, AiDraft::sudahKedaluwarsa()->count());
        $this->assertNotSame($draft->uuid, $lama->uuid);
    }

    public function test_undangan_dan_idempotensi_telegram_update(): void
    {
        $this->assertTrue(Invitation::factory()->create()->isUsable());
        $this->assertFalse(Invitation::factory()->kedaluwarsa()->create()->isUsable());
        $this->assertFalse(Invitation::factory()->terpakai()->create()->isUsable());
        $this->assertSame(1, Invitation::aktif()->count());

        TelegramUpdate::factory()->create(['update_id' => 42]);
        $this->expectException(QueryException::class);
        TelegramUpdate::factory()->create(['update_id' => 42]);
    }

    public function test_demo_seeder_berjalan_dua_kali_tanpa_duplikasi(): void
    {
        $this->seed(DemoSeeder::class);

        $hitung = fn () => [
            'sppg' => Sppg::count(),
            'users' => User::withoutGlobalScopes()->count(),
            'titik' => TitikLayanan::count(),
            'penerima' => PenerimaManfaat::count(),
            'bahan' => BahanBaku::count(),
            'jadwal' => JadwalProduksi::count(),
            'distribusi' => JadwalDistribusi::count(),
            'bukti' => BuktiDistribusi::count(),
            'keluhan' => FeedbackKeluhan::count(),
            'pengeluaran' => Pengeluaran::count(),
        ];

        $pertama = $hitung();
        $this->seed(DemoSeeder::class);
        $this->assertSame($pertama, $hitung());

        $this->assertSame(2, $pertama['sppg']);
        $this->assertSame(1 + 2 * 7, $pertama['users']); // 1 admin lintas + 7 pengguna per SPPG
        $this->assertSame(8, $pertama['titik']);
        $this->assertSame(2 * 7, $pertama['jadwal']);

        foreach (Sppg::all() as $sppg) {
            $peran = User::withoutGlobalScopes()->where('sppg_id', $sppg->id)->pluck('role')->map->value->unique()->sort()->values()->all();
            $this->assertSame(['admin', 'ahli_gizi', 'akuntan', 'kepala_sppg', 'relawan', 'staf_gudang'], $peran);
            $this->assertLessThanOrEqual($sppg->kapasitas_porsi_harian, JadwalProduksi::where('sppg_id', $sppg->id)->max('target_porsi'));
        }

        // Pencairan 80% (SPPG 1) dan 100% (SPPG 2).
        $persen = PencairanDana::orderBy('sppg_id')->get()->map(fn ($p) => (float) $p->totalPengeluaran() / (float) $p->nominal * 100)->all();
        $this->assertEqualsWithDelta([80.0, 100.0], $persen, 0.001);

        // Satu SPPG punya mutu tidak layak hari ini -> distribusi tertahan.
        $this->assertTrue(PemeriksaanMutu::where('hasil', HasilMutu::TidakLayak->value)->exists());
    }

    public function test_isolasi_sppg_pada_titik_layanan(): void
    {
        $a = Sppg::factory()->create();
        $b = Sppg::factory()->create();
        $titikA = TitikLayanan::factory()->create(['sppg_id' => $a->id]);
        $titikB = TitikLayanan::factory()->create(['sppg_id' => $b->id]);

        $userA = User::factory()->forSppg($a->id)->create();
        $this->actingAs($userA);

        $this->assertSame([$titikA->id], TitikLayanan::pluck('id')->all());
        $this->assertNull(TitikLayanan::find($titikB->id));

        // sppg_id dipaksa mengikuti pengguna walau diisi berbeda.
        $baru = TitikLayanan::create([
            'sppg_id' => $b->id, 'nama' => 'Posyandu X', 'jenis' => 'posyandu', 'alamat' => 'Jl. X',
            'jumlah_sasaran' => 10, 'pic_nama' => 'PIC (Demo)',
        ]);
        $this->assertSame($a->id, $baru->sppg_id);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->assertSame(3, TitikLayanan::count());
    }
}

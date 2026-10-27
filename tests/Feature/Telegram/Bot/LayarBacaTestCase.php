<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Armada;
use App\Models\BahanBaku;
use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\Menu;
use App\Models\NotifikasiPerjalanan;
use App\Models\Pemasok;
use App\Models\PembelianBahan;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\PenerimaManfaat;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\Pengemudi;
use App\Models\RekapHarian;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\Bot\Layar\LayarBaca;

/**
 * Dasar tes layar baca Bot nyata (R02-B1). Registri = layar produksi (BotLayarRegistry bawaan kontainer), bukan LayarUji.
 * Data fiktif per SPPG: SPPG A bertanda "Alfa", SPPG B bertanda "Beta" (tanda berbeda agar kebocoran lintas SPPG terlihat).
 * Penanda privasi yang TIDAK boleh muncul di chat: uraian pengeluaran, nama lengkap pengemudi, telepon, nama PIC.
 */
abstract class LayarBacaTestCase extends BotTestCase
{
    /** Teks yang tidak boleh muncul di layar mana pun. */
    protected const TERLARANG = ['URAIAN-RAHASIA', 'Budi Santoso', '0812-0000', '081234567890', 'Siti Rahma', 'BA-', 'rahasia-nota'];

    protected string $hari;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telegram.callback_rate' => 100000,
            'telegram.callback_gagal_rate' => 100000,
            'telegram.perintah_rate' => 100000,
            'telegram.layar_berat_jeda_detik' => 0,
        ]);
        $this->hari = now(config('operasional.timezone', 'Asia/Jakarta'))->toDateString();
        $this->forgetActor();
    }

    /** Id semua layar data (hak bukan HAK_SESI) pada registri produksi. @return list<string> */
    protected function idLayarData(): array
    {
        $data = array_filter(app(BotLayarRegistry::class)->semua(), fn ($layar) => $layar->hak() !== LayarBaca::HAK_SESI);

        return array_values(array_keys($data));
    }

    protected function n1(User $user, string $layar, ?int $sppg): string
    {
        return BotNavCodec::data($layar, $sppg, (int) $user->telegram_id);
    }

    /** Tekan tombol layar $layar; mengembalikan teks layar bila pesan diedit, atau null bila ditolak/tidak dirender. */
    protected function buka(User $user, string $layar, ?int $sppg = null): ?string
    {
        $sebelum = $this->telegramCalls('editMessageText')->count();
        $this->tekan($user, $this->n1($user, $layar, $sppg ?? ($user->sppg_id === null ? null : (int) $user->sppg_id)));

        if ($this->telegramCalls('editMessageText')->count() <= $sebelum) {
            return null;
        }

        $teks = $this->teksLayar($user);

        // Edit netral (klik ditolak) bukan layar yang dirender.
        return in_array($teks, [BotCallbackHandler::LAYAR_DITUTUP_HAK, BotCallbackHandler::LAYAR_DITUTUP_SESI], true) ? null : $teks;
    }

    protected function relawanDistribusi(): User
    {
        return $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
    }

    protected function relawanTanpaUnit(): User
    {
        $user = $this->userOf(Role::Relawan, $this->sppgA);
        $user->forceFill(['unit' => null])->save();

        return $user->fresh();
    }

    protected function pengguna(string $peran): User
    {
        return match ($peran) {
            'relawanDistribusi' => $this->relawanDistribusi(),
            'relawanTanpaUnit' => $this->relawanTanpaUnit(),
            default => $this->{$peran},
        };
    }

    protected function sppgDari(string $peran): int
    {
        return $peran === 'investorC' ? $this->sppgC : $this->sppgA;
    }

    /**
     * Isi satu SPPG dengan data fiktif lengkap. $tanda dipakai di nama/uraian agar mudah dilacak.
     *
     * @return array<string, mixed> id yang berguna untuk tes
     */
    protected function isiData(int $sppgId, string $tanda, int $nominalPengeluaran = 123_000): array
    {
        $this->forgetActor();

        $menu = Menu::factory()->create(['sppg_id' => $sppgId, 'nama_menu' => "Menu {$tanda}", 'tanggal_sajian' => $this->hari, 'energi_kkal' => 610, 'protein_g' => 22]);
        $produksi = JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'menu_id' => $menu->id, 'tanggal_produksi' => $this->hari, 'target_porsi' => 500]);
        $titik = TitikLayanan::factory()->create(['sppg_id' => $sppgId, 'nama' => "SD {$tanda} Uji", 'pic_nama' => 'Siti Rahma', 'pic_telepon' => '0812-0000-9999']);
        $armada = Armada::factory()->create(['sppg_id' => $sppgId, 'plat_nomor' => "B 1234 {$tanda}"]);
        $pengemudi = Pengemudi::factory()->create(['sppg_id' => $sppgId, 'nama' => 'Budi Santoso', 'no_hp' => '0812-0000-1111']);
        $distribusi = JadwalDistribusi::factory()->create([
            'sppg_id' => $sppgId, 'jadwal_produksi_id' => $produksi->id, 'titik_layanan_id' => $titik->id,
            'armada_id' => $armada->id, 'pengemudi_id' => $pengemudi->id, 'tanggal_distribusi' => $this->hari, 'jumlah_porsi' => 250,
        ]);
        BuktiDistribusi::factory()->create(['sppg_id' => $sppgId, 'jadwal_distribusi_id' => $distribusi->id, 'porsi_diterima' => 240, 'no_berita_acara' => 'BA-000123']);
        NotifikasiPerjalanan::factory()->create(['sppg_id' => $sppgId, 'jadwal_distribusi_id' => $distribusi->id]);

        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'nama' => "Bahan {$tanda} Menipis", 'stok' => 1, 'stok_minimum' => 10, 'kedaluwarsa_terdekat' => now()->addDays(60)->toDateString()]);
        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'nama' => "Bahan {$tanda} Lewat", 'stok' => 100, 'stok_minimum' => 10, 'kedaluwarsa_terdekat' => now()->subDay()->toDateString()]);
        $pemasok = Pemasok::factory()->create(['sppg_id' => $sppgId, 'nama' => "Pemasok {$tanda}"]);

        FeedbackKeluhan::factory()->create([
            'sppg_id' => $sppgId, 'titik_layanan_id' => $titik->id, 'prioritas' => 'kritis', 'status' => 'baru',
            'deskripsi' => 'Sayur lembek, hubungi Budi di 081234567890',
        ]);

        PencairanDana::factory()->create(['sppg_id' => $sppgId, 'nominal' => 10_000_000, 'sumber_dana' => "Dana {$tanda}"]);
        Pengeluaran::factory()->create(['sppg_id' => $sppgId, 'uraian' => "URAIAN-RAHASIA-{$tanda}", 'nominal' => $nominalPengeluaran, 'kategori' => 'energi', 'nota_path' => 'rahasia-nota.jpg']);
        PemeriksaanMutu::factory()->create(['sppg_id' => $sppgId, 'jadwal_produksi_id' => $produksi->id, 'suhu_makanan_c' => 71.5]);
        LogProduksi::factory()->create(['sppg_id' => $sppgId, 'jadwal_produksi_id' => $produksi->id, 'realisasi_porsi' => 480, 'kode_batch' => "BATCH-{$tanda}"]);
        RekapHarian::factory()->create(['sppg_id' => $sppgId]);
        PengajuanMenu::factory()->create([
            'sppg_id' => $sppgId, 'menu_id' => $menu->id, 'catatan_keputusan' => 'CATATAN-KEPUTUSAN-RAHASIA',
            'rincian' => ['menu' => ['nama_menu' => "Menu Ajuan {$tanda}", 'kelompok_sasaran' => 'siswa', 'gizi_per_porsi' => []], 'bahan' => [], 'tanggal_acuan' => $this->hari],
        ]);
        PembelianBahan::factory()->create([
            'sppg_id' => $sppgId, 'pemasok_id' => $pemasok->id, 'jumlah' => 10, 'harga_satuan' => 12_500, 'total' => 125_000,
            'bahan_baku_id' => BahanBaku::factory()->create(['sppg_id' => $sppgId, 'nama' => "Beras {$tanda}", 'satuan' => 'kg'])->id,
        ]);

        return ['titik' => $titik->id, 'jadwal' => $produksi->id];
    }

    protected function penerima(int $sppgId, int $jumlah, string $kelompok = 'siswa'): void
    {
        $this->forgetActor();
        PenerimaManfaat::factory()->count($jumlah)->create(['sppg_id' => $sppgId, 'kelompok_sasaran' => $kelompok, 'is_active' => true]);
    }

    protected function assertTanpaTerlarang(string $teks, string $konteks = ''): void
    {
        foreach (self::TERLARANG as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $teks, "{$konteks}: bocor '{$terlarang}'");
        }
    }
}

<?php

namespace Tests\Feature\Persetujuan;

use App\Models\BahanBaku;
use App\Models\HargaPemasok;
use App\Models\Menu;
use App\Models\Pemasok;
use App\Models\PenerimaManfaat;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Investor\InvestorTestCase;
use Tests\Feature\Telegram\FakesTelegram;

/**
 * Dasar tes R-06b-1. SPPG A dimiliki investorAB (bersama B); SPPG C milik investorC. Semua data fiktif.
 * Menu contoh: beras (kg, 20.000/kg, 100 g) + telur (butir, 2.000/butir, 50 g/butir, 100 g) = 2.000 + 4.000 = 6.000 per porsi.
 */
abstract class PersetujuanTestCase extends InvestorTestCase
{
    use FakesTelegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        $this->forgetActor();
    }

    protected function pemasok(int $sppgId, string $jenis = 'umkm', bool $aktif = true): Pemasok
    {
        $this->forgetActor();

        return Pemasok::factory()->create(['sppg_id' => $sppgId, 'jenis' => $jenis, 'is_active' => $aktif]);
    }

    protected function bahan(int $sppgId, string $satuan = 'kg', ?int $pemasokId = null, string $nama = 'Bahan Uji'): BahanBaku
    {
        $this->forgetActor();

        return BahanBaku::factory()->create(['sppg_id' => $sppgId, 'satuan' => $satuan, 'pemasok_id' => $pemasokId, 'nama' => $nama]);
    }

    protected function harga(Pemasok $pemasok, BahanBaku $bahan, float|int $harga, ?string $berlakuMulai = null, ?float $gps = null): HargaPemasok
    {
        $this->forgetActor();

        return HargaPemasok::factory()->create([
            'sppg_id' => $pemasok->sppg_id, 'pemasok_id' => $pemasok->id, 'bahan_baku_id' => $bahan->id,
            'harga' => $harga, 'gram_per_satuan' => $gps, 'berlaku_mulai' => $berlakuMulai ?? now()->toDateString(),
        ]);
    }

    /** Menu draf dengan beras + telur dan harga lengkap; 10 penerima aktif (siswa) sebagai dasar porsi. */
    protected function menuSiapAjukan(?int $sppgId = null, string $status = 'draf'): Menu
    {
        $sppgId ??= $this->sppgA;
        $pemasok = $this->pemasok($sppgId, 'koperasi');
        $beras = $this->bahan($sppgId, 'kg', $pemasok->id, 'Beras Uji');
        $telur = $this->bahan($sppgId, 'butir', $pemasok->id, 'Telur Uji');
        $this->harga($pemasok, $beras, 20000);
        $this->harga($pemasok, $telur, 2000, null, 50);

        $menu = Menu::factory()->create(['sppg_id' => $sppgId, 'status_persetujuan' => $status, 'nama_menu' => 'Nasi Telur Uji']);
        $menu->bahanBakus()->attach([$beras->id => ['gram_per_porsi' => 100], $telur->id => ['gram_per_porsi' => 100]]);
        PenerimaManfaat::factory()->count(10)->create(['sppg_id' => $sppgId, 'kelompok_sasaran' => 'siswa', 'is_active' => true]);

        return $menu->refresh();
    }

    /** Ajukan sebagai ahli gizi A; mengembalikan id pengajuan. */
    protected function ajukan(Menu $menu, ?int $versi = null): int
    {
        return (int) $this->sebagai($this->ahliGiziA)
            ->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => $versi ?? $menu->versi])
            ->assertCreated()
            ->json('data.id');
    }

    protected function menuSegar(Menu $menu): Menu
    {
        return Menu::withoutGlobalScope(SppgScope::class)->findOrFail($menu->id);
    }

    protected function investorBaru(int $sppgId): User
    {
        return $this->investor([$sppgId]);
    }

    protected function jumlahAudit(string $event, string $tipe, int $id): int
    {
        return DB::table('audit_logs')->where('event', $event)->where('auditable_type', $tipe)->where('auditable_id', $id)->count();
    }
}

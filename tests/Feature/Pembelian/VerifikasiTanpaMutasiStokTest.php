<?php

namespace Tests\Feature\Pembelian;

use App\Models\BahanBaku;
use App\Models\MutasiStok;
use App\Models\Pengeluaran;
use App\Models\Scopes\SppgScope;

/** Kunci perilaku R-06b-2: verifikasi pembelian hanya membuat pengeluaran disetujui, tanpa mutasi stok. */
class VerifikasiTanpaMutasiStokTest extends PembelianTestCase
{
    public function test_verifikasi_setuju_tidak_membuat_mutasi_stok_dan_tidak_mengubah_stok_bahan(): void
    {
        $this->pencairan($this->sppgA, 1_000_000);
        $stokAwal = (string) BahanBaku::withoutGlobalScope(SppgScope::class)->findOrFail($this->berasA->id)->getRawOriginal('stok');
        $mutasiAwal = MutasiStok::query()->withoutGlobalScopes()->count();

        $id = $this->catatPembelian(['jumlah' => 10, 'harga_satuan' => 12500]);
        $res = $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])
            ->assertOk()->assertJsonPath('data.status', 'terverifikasi');

        $this->assertSame($mutasiAwal, MutasiStok::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, MutasiStok::query()->withoutGlobalScopes()->where('bahan_baku_id', $this->berasA->id)->count());
        $this->assertSame($stokAwal, (string) BahanBaku::withoutGlobalScope(SppgScope::class)->findOrFail($this->berasA->id)->getRawOriginal('stok'));

        $p = Pengeluaran::query()->withoutGlobalScopes()->findOrFail((int) $res->json('data.pengeluaran_id'));
        $this->assertSame('disetujui', $p->status_persetujuan->value);
        $this->assertSame(1, Pengeluaran::query()->withoutGlobalScopes()->where('pembelian_bahan_id', $id)->count());
    }

    public function test_verifikasi_tolak_tidak_membuat_mutasi_stok_maupun_pengeluaran(): void
    {
        $id = $this->catatPembelian();

        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'ditolak', 'alasan' => 'Nota tidak jelas', 'versi' => 1])
            ->assertOk()->assertJsonPath('data.status', 'ditolak');

        $this->assertSame(0, MutasiStok::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Pengeluaran::query()->withoutGlobalScopes()->where('pembelian_bahan_id', $id)->count());
    }
}

<?php

namespace Tests\Feature\Pembelian;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\BahanBaku;
use App\Models\Pemasok;
use App\Models\PembelianBahan;
use App\Models\PencairanDana;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Persetujuan\PersetujuanTestCase;
use Tests\Support\GambarUji;

/**
 * Dasar tes R-06b-2. Staf gudang A mencatat, akuntan A memverifikasi, investor AB memutuskan pengeluaran SPPG A.
 * Data fiktif: bahan "Beras Uji" (kg), pemasok "UD Sumber Pangan Fiktif".
 */
abstract class PembelianTestCase extends PersetujuanTestCase
{
    use GambarUji;

    protected Pemasok $pemasokA;

    protected BahanBaku $berasA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        $this->pemasokA = $this->pemasok($this->sppgA);
        $this->pemasokA->forceFill(['nama' => 'UD Sumber Pangan Fiktif'])->save();
        $this->berasA = $this->bahan($this->sppgA, 'kg', $this->pemasokA->id, 'Beras Uji');
        $this->forgetActor();
    }

    /** Fixture pengguna dibuat tanpa pengguna login (BelongsToSppg memaksa sppg_id = SPPG pengguna login). */
    protected function userOf(Role $role, int $sppgId, ?RelawanUnit $unit = null): User
    {
        $this->forgetActor();

        return parent::userOf($role, $sppgId, $unit);
    }

    /** Unggah nota tertunda sebagai $user; mengembalikan id lampiran. */
    protected function unggahNota(User $user): int
    {
        $this->sebagai($user);

        return (int) $this->post('/api/v1/lampiran', ['modul' => 'pembelian-bahan', 'berkas' => $this->fotoJpeg('nota.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    protected function bodyPembelian(array $o = []): array
    {
        return array_merge([
            'bahan_baku_id' => $this->berasA->id,
            'pemasok_id' => $this->pemasokA->id,
            'tanggal_beli' => now()->toDateString(),
            'jumlah' => 10,
            'harga_satuan' => 12500,
            'catatan' => 'Beli di pasar pagi',
        ], $o);
    }

    /** Catat pembelian sebagai staf gudang A; mengembalikan id. @param array<string, mixed> $o */
    protected function catatPembelian(array $o = []): int
    {
        $nota = $this->unggahNota($this->stafGudangA);

        return (int) $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['lampiran_ids' => [$nota]] + $o))
            ->assertCreated()->json('data.id');
    }

    protected function pencairan(int $sppgId, int $nominal, string $periode = ''): PencairanDana
    {
        $this->forgetActor();

        return PencairanDana::factory()->create([
            'sppg_id' => $sppgId, 'nominal' => $nominal,
            'periode' => $periode ?: now()->format('Y-m'), 'tanggal_cair' => now()->startOfMonth()->toDateString(),
        ]);
    }

    protected function pembelianRaw(int $id): PembelianBahan
    {
        return PembelianBahan::withoutGlobalScope(SppgScope::class)->findOrFail($id);
    }
}

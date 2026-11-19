<?php

namespace Tests\Feature\GrupB;

use App\Enums\Role;
use App\Models\BahanBaku;
use App\Models\MutasiStok;
use Illuminate\Support\Str;

/** NFR-07: Idempotency-Key pada POST /bahan-baku dan POST /bahan-baku/{id}/mutasi. */
class BahanBakuIdempotensiTest extends GrupBTestCase
{
    private function payload(array $override = []): array
    {
        return array_merge(['nama' => 'Beras Premium', 'kode' => 'BB-001', 'kategori' => 'karbohidrat', 'satuan' => 'kg', 'stok_minimum' => 50], $override);
    }

    private function jumlahBahan(): int
    {
        return BahanBaku::query()->withoutGlobalScopes()->count();
    }

    private function bahanKosong(): BahanBaku
    {
        return BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'stok' => 0, 'stok_minimum' => 10, 'pemasok_id' => null, 'kedaluwarsa_terdekat' => null]);
    }

    private function stok(BahanBaku $bahan): float
    {
        return (float) BahanBaku::query()->withoutGlobalScopes()->find($bahan->id)->stok;
    }

    private function jumlahMutasi(BahanBaku $bahan): int
    {
        return MutasiStok::query()->withoutGlobalScopes()->where('bahan_baku_id', $bahan->id)->count();
    }

    public function test_store_replay_kunci_dan_payload_sama_tidak_menggandakan(): void
    {
        $this->login(Role::StafGudang);
        $kunci = (string) Str::uuid();

        $a = $this->postJson('/api/v1/bahan-baku', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();
        $b = $this->postJson('/api/v1/bahan-baku', $this->payload(), ['Idempotency-Key' => $kunci])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json(), $b->json());
        $this->assertSame(1, $this->jumlahBahan());
    }

    public function test_store_kunci_sama_payload_beda_422_dan_tidak_membuat_data(): void
    {
        $this->login(Role::StafGudang);
        $kunci = (string) Str::uuid();

        $this->postJson('/api/v1/bahan-baku', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'BB-002']), ['Idempotency-Key' => $kunci])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');

        $this->assertSame(1, $this->jumlahBahan());
    }

    public function test_store_tanpa_kunci_tetap_berjalan_dan_kunci_bukan_uuid_422(): void
    {
        $this->login(Role::StafGudang);

        $this->postJson('/api/v1/bahan-baku', $this->payload())->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'BB-002']))->assertCreated();
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'BB-003']), ['Idempotency-Key' => 'bukan-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');

        $this->assertSame(2, $this->jumlahBahan());
    }

    public function test_store_respons_gagal_tidak_disimpan_sehingga_kunci_boleh_dipakai_ulang(): void
    {
        $this->login(Role::StafGudang);
        $kunci = (string) Str::uuid();

        $this->postJson('/api/v1/bahan-baku', [], ['Idempotency-Key' => $kunci])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->postJson('/api/v1/bahan-baku', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();

        $this->assertSame(1, $this->jumlahBahan());
    }

    public function test_store_kunci_sama_pengguna_berbeda_tidak_berbagi_cache(): void
    {
        $kunci = (string) Str::uuid();
        $this->login(Role::StafGudang);
        $this->postJson('/api/v1/bahan-baku', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->login(Role::StafGudang, $this->sppgB);
        // Kode unik global: payload berbeda; bila cache dibagi antarpengguna, hasilnya 422 (bukan 201).
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'BB-B01']), ['Idempotency-Key' => $kunci])
            ->assertCreated()->assertHeaderMissing('Idempotent-Replayed')->assertJsonPath('data.sppg_id', $this->sppgB->id);

        $this->assertSame(2, $this->jumlahBahan());
    }

    public function test_store_tanpa_hak_tetap_403_dan_tidak_disimpan_sebagai_replay(): void
    {
        $this->login(Role::KepalaSppg);
        $kunci = (string) Str::uuid();

        $this->postJson('/api/v1/bahan-baku', $this->payload(), ['Idempotency-Key' => $kunci])->assertForbidden();
        $this->postJson('/api/v1/bahan-baku', $this->payload(), ['Idempotency-Key' => $kunci])->assertForbidden()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(0, $this->jumlahBahan());
    }

    public function test_mutasi_replay_tidak_mengubah_stok_dua_kali(): void
    {
        $bahan = $this->bahanKosong();
        $this->login(Role::StafGudang);
        $kunci = (string) Str::uuid();
        $body = ['jenis' => 'masuk', 'jumlah' => 100, 'catatan' => 'Pengiriman pagi'];

        $a = $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", $body, ['Idempotency-Key' => $kunci])->assertCreated();
        $b = $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", $body, ['Idempotency-Key' => $kunci])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json(), $b->json());
        $this->assertSame(1, $this->jumlahMutasi($bahan));
        $this->assertEquals(100, $this->stok($bahan));
    }

    public function test_mutasi_kunci_sama_payload_beda_422_stok_tidak_berubah(): void
    {
        $bahan = $this->bahanKosong();
        $this->login(Role::StafGudang);
        $kunci = (string) Str::uuid();

        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'masuk', 'jumlah' => 100], ['Idempotency-Key' => $kunci])->assertCreated();
        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'masuk', 'jumlah' => 50], ['Idempotency-Key' => $kunci])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');

        $this->assertEquals(100, $this->stok($bahan));
        $this->assertSame(1, $this->jumlahMutasi($bahan));
    }

    public function test_mutasi_tanpa_kunci_tetap_berjalan_setiap_kali(): void
    {
        $bahan = $this->bahanKosong();
        $this->login(Role::StafGudang);
        $body = ['jenis' => 'masuk', 'jumlah' => 10];

        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", $body)->assertCreated();
        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", $body)->assertCreated();

        $this->assertEquals(20, $this->stok($bahan));
    }

    public function test_mutasi_kunci_sama_pada_bahan_berbeda_tidak_berbagi_cache(): void
    {
        $a = $this->bahanKosong();
        $b = $this->bahanKosong();
        $this->login(Role::StafGudang);
        $kunci = (string) Str::uuid();
        $body = ['jenis' => 'masuk', 'jumlah' => 10];

        $this->postJson("/api/v1/bahan-baku/{$a->id}/mutasi", $body, ['Idempotency-Key' => $kunci])->assertCreated();
        $this->postJson("/api/v1/bahan-baku/{$b->id}/mutasi", $body, ['Idempotency-Key' => $kunci])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertEquals(10, $this->stok($b));
    }

    public function test_mutasi_lintas_sppg_tetap_404_walau_berkunci(): void
    {
        $milikB = BahanBaku::factory()->create(['sppg_id' => $this->sppgB->id, 'stok' => 0]);
        $this->login(Role::StafGudang);

        $this->postJson("/api/v1/bahan-baku/{$milikB->id}/mutasi", ['jenis' => 'masuk', 'jumlah' => 5], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
    }
}

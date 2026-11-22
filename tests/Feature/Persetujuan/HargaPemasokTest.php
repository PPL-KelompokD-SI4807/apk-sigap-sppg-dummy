<?php

namespace Tests\Feature\Persetujuan;

use App\Enums\Role;
use App\Models\HargaPemasok;
use App\Models\Menu;
use App\Models\PenerimaManfaat;
use LogicException;

/** Riwayat harga pemasok (append-only), harga terkini, dan rincian biaya menu. */
class HargaPemasokTest extends PersetujuanTestCase
{
    public function test_stafgudang_mencatat_harga_dan_riwayat_terbaru_dulu(): void
    {
        $pemasok = $this->pemasok($this->sppgA, 'koperasi');
        $bahan = $this->bahan($this->sppgA, 'kg');

        $this->sebagai($this->stafGudangA)
            ->postJson("/api/v1/pemasok/{$pemasok->id}/harga", ['bahan_baku_id' => $bahan->id, 'harga' => 18000, 'berlaku_mulai' => now()->subDays(10)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.harga', 18000)
            ->assertJsonPath('data.sudah_berlaku', true);
        $this->postJson("/api/v1/pemasok/{$pemasok->id}/harga", ['bahan_baku_id' => $bahan->id, 'harga' => 20000, 'berlaku_mulai' => now()->toDateString(), 'catatan' => 'Naik'])
            ->assertCreated();

        $res = $this->getJson("/api/v1/pemasok/{$pemasok->id}/harga?bahan_baku_id={$bahan->id}")->assertOk();
        $this->assertSame([20000, 18000], array_column($res->json('data'), 'harga'));
        $this->assertSame(1, $this->jumlahAudit('created', HargaPemasok::class, (int) $res->json('data.0.id')));
    }

    public function test_validasi_harga_dan_satuan_non_baku_wajib_gram_per_satuan(): void
    {
        $pemasok = $this->pemasok($this->sppgA);
        $butir = $this->bahan($this->sppgA, 'butir');
        $this->sebagai($this->stafGudangA);
        $base = ['bahan_baku_id' => $butir->id, 'harga' => 2000, 'berlaku_mulai' => now()->toDateString()];

        $this->postJson("/api/v1/pemasok/{$pemasok->id}/harga", $base)->assertUnprocessable()->assertJsonValidationErrors(['gram_per_satuan']);
        $this->postJson("/api/v1/pemasok/{$pemasok->id}/harga", $base + ['gram_per_satuan' => 50])->assertCreated();
        $this->postJson("/api/v1/pemasok/{$pemasok->id}/harga", [...$base, 'harga' => 0, 'gram_per_satuan' => 50])->assertUnprocessable()->assertJsonValidationErrors(['harga']);
        $this->postJson("/api/v1/pemasok/{$pemasok->id}/harga", [...$base, 'gram_per_satuan' => 50, 'berlaku_mulai' => now()->addYears(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['berlaku_mulai']);
        $this->postJson("/api/v1/pemasok/{$pemasok->id}/harga", [])->assertUnprocessable()->assertJsonValidationErrors(['bahan_baku_id', 'harga', 'berlaku_mulai']);
    }

    public function test_bahan_dan_pemasok_wajib_satu_sppg_dan_lintas_sppg_tertutup(): void
    {
        $pemasok = $this->pemasok($this->sppgA);
        $bahanB = $this->bahan($this->sppgB, 'kg');
        $pemasokB = $this->pemasok($this->sppgB);
        $bahanA = $this->bahan($this->sppgA, 'kg');
        $body = ['harga' => 100, 'berlaku_mulai' => now()->toDateString()];

        $this->sebagai($this->stafGudangA)
            ->postJson("/api/v1/pemasok/{$pemasok->id}/harga", $body + ['bahan_baku_id' => $bahanB->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['bahan_baku_id']);
        $this->postJson("/api/v1/pemasok/{$pemasokB->id}/harga", $body + ['bahan_baku_id' => $bahanA->id])->assertNotFound();
        $this->getJson("/api/v1/pemasok/{$pemasokB->id}/harga")->assertNotFound();
        $this->assertSame(0, HargaPemasok::query()->withoutGlobalScopes()->count());
    }

    public function test_rbac_catat_dan_baca_harga(): void
    {
        $pemasok = $this->pemasok($this->sppgA);
        $bahan = $this->bahan($this->sppgA, 'kg');
        $body = ['bahan_baku_id' => $bahan->id, 'harga' => 100, 'berlaku_mulai' => now()->toDateString()];

        foreach ([$this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->relawanA, $this->investorAB] as $user) {
            $this->sebagai($user, $user->role === Role::Investor ? $this->sppgA : null)->postJson("/api/v1/pemasok/{$pemasok->id}/harga", $body)->assertForbidden();
        }

        foreach ([$this->kepalaA, $this->ahliGiziA, $this->akuntanA] as $user) {
            $this->sebagai($user)->getJson("/api/v1/pemasok/{$pemasok->id}/harga")->assertOk();
        }

        $this->sebagai($this->relawanA)->getJson("/api/v1/pemasok/{$pemasok->id}/harga")->assertForbidden();
        $this->sebagai($this->investorAB, $this->sppgA)->getJson("/api/v1/pemasok/{$pemasok->id}/harga")->assertForbidden();
        $this->sebagai($this->admin)->postJson("/api/v1/pemasok/{$pemasok->id}/harga", $body)->assertCreated();
    }

    public function test_tidak_ada_rute_ubah_atau_hapus_dan_model_menolak(): void
    {
        $pemasok = $this->pemasok($this->sppgA);
        $bahan = $this->bahan($this->sppgA, 'kg');
        $harga = $this->harga($pemasok, $bahan, 100);

        $this->sebagai($this->admin);
        $this->patchJson("/api/v1/pemasok/{$pemasok->id}/harga/{$harga->id}", ['harga' => 1])->assertStatus(404);
        $this->deleteJson("/api/v1/pemasok/{$pemasok->id}/harga/{$harga->id}")->assertStatus(404);

        $this->forgetActor();
        $segar = HargaPemasok::query()->withoutGlobalScopes()->findOrFail($harga->id);

        try {
            $segar->update(['harga' => 1]);
            $this->fail('Update harus ditolak.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $segar->delete();
    }

    public function test_harga_terkini_utama_diutamakan_mendatang_diabaikan_dan_konversi(): void
    {
        $utama = $this->pemasok($this->sppgA, 'umkm');
        $koperasi = $this->pemasok($this->sppgA, 'koperasi');
        $nonaktif = $this->pemasok($this->sppgA, 'umkm', false);
        $beras = $this->bahan($this->sppgA, 'kg', $utama->id, 'Beras Uji');
        $this->harga($utama, $beras, 15000, now()->subDays(30)->toDateString());
        $this->harga($utama, $beras, 99999, now()->addDays(5)->toDateString());   // mendatang: diabaikan
        $this->harga($koperasi, $beras, 12000, now()->toDateString());             // lebih baru tetapi bukan utama
        $this->harga($nonaktif, $beras, 1, now()->toDateString());                 // nonaktif: diabaikan

        $menu = Menu::factory()->draf()->create(['sppg_id' => $this->sppgA]);
        $menu->bahanBakus()->attach([$beras->id => ['gram_per_porsi' => 150]]);
        PenerimaManfaat::factory()->count(4)->create(['sppg_id' => $this->sppgA, 'is_active' => true]);

        $data = $this->sebagai($this->ahliGiziA)->getJson("/api/v1/menu-gizi/{$menu->id}/rincian-biaya")->assertOk()->json('data');

        $this->assertEquals(15000.0, $data['bahan'][0]['harga_per_satuan']);
        $this->assertSame($utama->id, $data['bahan'][0]['pemasok_id']);
        $this->assertTrue($data['bahan'][0]['pemasok_utama']);
        $this->assertEquals(2250.0, $data['bahan'][0]['biaya_per_porsi']);     // 150 g / 1000 x 15000
        $this->assertEquals(2250.0, $data['biaya_per_porsi']);
        $this->assertTrue($data['lengkap']);

        // Tanpa pemasok utama berharga: entri terbaru dari pemasok aktif lain dipakai (seri: id terbesar).
        $beras->forceFill(['pemasok_id' => null])->save();
        $data = $this->getJson("/api/v1/menu-gizi/{$menu->id}/rincian-biaya")->assertOk()->json('data');
        $this->assertEquals(12000.0, $data['bahan'][0]['harga_per_satuan']);
        $this->assertEquals(1800.0, $data['biaya_per_porsi']);
    }

    public function test_bahan_tanpa_harga_atau_konversi_ditandai_dan_pengajuan_422(): void
    {
        $pemasok = $this->pemasok($this->sppgA);
        $tanpaHarga = $this->bahan($this->sppgA, 'kg', null, 'Bayam Uji');
        $tanpaKonversi = $this->bahan($this->sppgA, 'ikat', null, 'Kangkung Uji');
        $this->harga($pemasok, $tanpaKonversi, 3000, null, null);
        $menu = Menu::factory()->draf()->create(['sppg_id' => $this->sppgA]);
        $menu->bahanBakus()->attach([$tanpaHarga->id => ['gram_per_porsi' => 50], $tanpaKonversi->id => ['gram_per_porsi' => 50]]);

        $data = $this->sebagai($this->ahliGiziA)->getJson("/api/v1/menu-gizi/{$menu->id}/rincian-biaya")->assertOk()->json('data');
        $this->assertFalse($data['lengkap']);
        $this->assertEqualsCanonicalizing(['tanpa_harga', 'tanpa_konversi'], array_column($data['bahan_bermasalah'], 'masalah'));

        $this->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('alasan', 'harga_bahan_belum_lengkap')
            ->assertJsonCount(2, 'bahan_bermasalah')
            ->assertJsonValidationErrors(['bahan']);
        $this->assertSame('draf', $this->menuSegar($menu)->status_persetujuan->value);
    }

    public function test_pembulatan_dua_desimal_konsisten(): void
    {
        $pemasok = $this->pemasok($this->sppgA);
        $bahan = $this->bahan($this->sppgA, 'kg');
        $this->harga($pemasok, $bahan, 12345.67);
        $menu = Menu::factory()->draf()->create(['sppg_id' => $this->sppgA]);
        $menu->bahanBakus()->attach([$bahan->id => ['gram_per_porsi' => 33.33]]);

        $data = $this->sebagai($this->ahliGiziA)->getJson("/api/v1/menu-gizi/{$menu->id}/rincian-biaya")->assertOk()->json('data');

        $this->assertEquals(411.48, $data['bahan'][0]['biaya_per_porsi']);   // 33.33 / 1000 x 12345.67 = 411.4...
        $this->assertEquals(411.48, $data['biaya_per_porsi']);
        $this->assertEquals(round(411.48 * $data['jumlah_porsi'], 2), $data['total_biaya']);
    }
}

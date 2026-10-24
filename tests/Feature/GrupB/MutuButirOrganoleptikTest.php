<?php

namespace Tests\Feature\GrupB;

use App\Enums\Role;
use App\Events\MutuTidakLayak;
use App\Models\JadwalProduksi;
use App\Models\PemeriksaanMutu;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/** Checklist organoleptik dipecah: rasa_baik, aroma_baik, tampilan_baik (+ organoleptik_baik turunan). */
class MutuButirOrganoleptikTest extends GrupBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([MutuTidakLayak::class]);
    }

    private function jadwal(): JadwalProduksi
    {
        return JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000, 'tanggal_produksi' => now()->toDateString()]);
    }

    /** @return array<string, mixed> */
    private function dasar(JadwalProduksi $jadwal, array $butir): array
    {
        return array_merge([
            'jadwal_produksi_id' => $jadwal->id, 'suhu_makanan_c' => 72, 'kebersihan_dapur' => true, 'kebersihan_petugas' => true,
            'sampel_disimpan' => true, 'hasil' => 'layak',
        ], $butir);
    }

    public function test_tiga_butir_baik_menurunkan_organoleptik_baik_true(): void
    {
        $this->login(Role::AhliGizi);

        $this->postJson('/api/v1/mutu', $this->dasar($this->jadwal(), ['rasa_baik' => true, 'aroma_baik' => true, 'tampilan_baik' => true]))
            ->assertCreated()
            ->assertJsonPath('data.rasa_baik', true)
            ->assertJsonPath('data.aroma_baik', true)
            ->assertJsonPath('data.tampilan_baik', true)
            ->assertJsonPath('data.organoleptik_baik', true);
    }

    public function test_satu_butir_gagal_membuat_organoleptik_false_dan_layak_ditolak(): void
    {
        $jadwal = $this->jadwal();
        $this->login(Role::AhliGizi);
        $butir = ['rasa_baik' => true, 'aroma_baik' => false, 'tampilan_baik' => true];

        $res = $this->postJson('/api/v1/mutu', $this->dasar($jadwal, $butir + ['catatan_organoleptik' => 'Aroma apek']))
            ->assertUnprocessable()->assertJsonValidationErrors(['hasil']);
        $this->assertStringContainsString('Aroma', $res->json('errors.hasil.0'));
        $this->assertStringNotContainsString('Rasa', $res->json('errors.hasil.0'));

        $this->postJson('/api/v1/mutu', $this->dasar($jadwal, $butir + ['hasil' => 'tidak_layak', 'tindakan_korektif' => 'Masak ulang']))
            ->assertUnprocessable()->assertJsonValidationErrors(['catatan_organoleptik']);

        $this->postJson('/api/v1/mutu', $this->dasar($jadwal, $butir + ['hasil' => 'tidak_layak', 'tindakan_korektif' => 'Masak ulang', 'catatan_organoleptik' => 'Aroma apek']))
            ->assertCreated()
            ->assertJsonPath('data.rasa_baik', true)
            ->assertJsonPath('data.aroma_baik', false)
            ->assertJsonPath('data.tampilan_baik', true)
            ->assertJsonPath('data.organoleptik_baik', false);
    }

    public function test_klien_lama_cukup_kirim_organoleptik_baik(): void
    {
        $jadwal = $this->jadwal();
        $this->login(Role::AhliGizi);

        $this->postJson('/api/v1/mutu', $this->dasar($jadwal, ['organoleptik_baik' => true]))
            ->assertCreated()
            ->assertJsonPath('data.rasa_baik', true)->assertJsonPath('data.aroma_baik', true)->assertJsonPath('data.tampilan_baik', true);

        $this->postJson('/api/v1/mutu', $this->dasar($jadwal, ['organoleptik_baik' => false, 'hasil' => 'tidak_layak', 'tindakan_korektif' => 'Ulang', 'catatan_organoleptik' => 'Kurang']))
            ->assertCreated()
            ->assertJsonPath('data.rasa_baik', false)->assertJsonPath('data.aroma_baik', false)->assertJsonPath('data.tampilan_baik', false)
            ->assertJsonPath('data.organoleptik_baik', false);
    }

    public function test_nilai_turunan_mengalahkan_kiriman_yang_bertentangan_dan_butir_wajib_ada(): void
    {
        $jadwal = $this->jadwal();
        $this->login(Role::AhliGizi);

        $this->postJson('/api/v1/mutu', $this->dasar($jadwal, ['rasa_baik' => true, 'aroma_baik' => true, 'tampilan_baik' => true, 'organoleptik_baik' => false]))
            ->assertCreated()->assertJsonPath('data.organoleptik_baik', true);

        $this->postJson('/api/v1/mutu', $this->dasar($jadwal, []))
            ->assertUnprocessable()->assertJsonValidationErrors(['rasa_baik', 'aroma_baik', 'tampilan_baik', 'organoleptik_baik']);
        $this->postJson('/api/v1/mutu', $this->dasar($jadwal, ['rasa_baik' => 'mungkin', 'aroma_baik' => true, 'tampilan_baik' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors(['rasa_baik']);
    }

    public function test_update_satu_butir_menghitung_ulang_turunan_dan_menjaga_aturan_layak(): void
    {
        $jadwal = $this->jadwal();
        $this->login(Role::AhliGizi);
        $id = $this->postJson('/api/v1/mutu', $this->dasar($jadwal, ['rasa_baik' => true, 'aroma_baik' => true, 'tampilan_baik' => true]))->json('data.id');

        // Layak ditolak bila ada butir gagal.
        $this->patchJson("/api/v1/mutu/{$id}", ['tampilan_baik' => false, 'catatan_organoleptik' => 'Pucat'])
            ->assertUnprocessable()->assertJsonValidationErrors(['hasil']);

        $this->patchJson("/api/v1/mutu/{$id}", ['tampilan_baik' => false, 'catatan_organoleptik' => 'Pucat', 'hasil' => 'tidak_layak', 'tindakan_korektif' => 'Ulang'])
            ->assertOk()
            ->assertJsonPath('data.rasa_baik', true)
            ->assertJsonPath('data.tampilan_baik', false)
            ->assertJsonPath('data.organoleptik_baik', false);

        // Pulih: semua butir baik kembali -> turunan true.
        $this->patchJson("/api/v1/mutu/{$id}", ['tampilan_baik' => true, 'hasil' => 'layak'])
            ->assertOk()->assertJsonPath('data.organoleptik_baik', true);
    }

    public function test_migrasi_mengisi_butir_dari_organoleptik_baik_data_lama(): void
    {
        $lama = PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgA->id, 'organoleptik_baik' => false, 'rasa_baik' => true, 'aroma_baik' => true, 'tampilan_baik' => true]);
        $baik = PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgA->id]);

        $berkas = 'database/migrations/2026_10_25_000501_add_butir_organoleptik_to_pemeriksaan_mutus_table.php';
        Artisan::call('migrate:rollback', ['--path' => $berkas, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('pemeriksaan_mutus', 'rasa_baik'));

        Artisan::call('migrate', ['--path' => $berkas, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('pemeriksaan_mutus', 'rasa_baik'));

        $row = DB::table('pemeriksaan_mutus')->where('id', $lama->id)->first();
        $this->assertSame([0, 0, 0], [(int) $row->rasa_baik, (int) $row->aroma_baik, (int) $row->tampilan_baik]);
        $row = DB::table('pemeriksaan_mutus')->where('id', $baik->id)->first();
        $this->assertSame([1, 1, 1], [(int) $row->rasa_baik, (int) $row->aroma_baik, (int) $row->tampilan_baik]);
    }
}

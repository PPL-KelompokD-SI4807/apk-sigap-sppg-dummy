<?php

namespace Tests\Feature\Audit;

use App\Models\Armada;
use App\Models\AuditLog;
use App\Models\BahanBaku;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\Pemasok;
use App\Models\Pengemudi;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Master\MasterApiTestCase;

/**
 * F-01/F-03 audit RBAC: 9 model data master/operasional kini tercatat di audit_logs (dibuat/diubah/dihapus),
 * tanpa kolom data pribadi/berkas. Semua data fiktif.
 */
class AuditModulMasterTest extends MasterApiTestCase
{
    /** @return array<string, array{class-string<Model>, string, mixed}> model, kolom yang diubah, nilai baru */
    public static function modelAudit(): array
    {
        return [
            'sppg' => [Sppg::class, 'nama', 'SPPG Nama Baru'],
            'titik-layanan' => [TitikLayanan::class, 'nama', 'SDN Nama Baru'],
            'pemasok' => [Pemasok::class, 'nama', 'UD Nama Baru'],
            'bahan-baku' => [BahanBaku::class, 'nama', 'Beras Nama Baru'],
            'menu' => [Menu::class, 'nama_menu', 'Menu Nama Baru'],
            'jadwal-produksi' => [JadwalProduksi::class, 'catatan', 'Catatan baru'],
            'armada' => [Armada::class, 'jenis', 'Pickup Baru'],
            'pengemudi' => [Pengemudi::class, 'nama', 'Pengemudi Nama Baru'],
            'keluhan' => [FeedbackKeluhan::class, 'status', 'diproses'],
        ];
    }

    /** @param class-string<Model> $kelas */
    #[DataProvider('modelAudit')]
    public function test_buat_ubah_hapus_tercatat_per_model(string $kelas, string $kolom, mixed $nilai): void
    {
        $this->forgetActor();
        $model = $kelas === Sppg::class ? Sppg::factory()->create() : $kelas::factory()->create(['sppg_id' => $this->sppgA]);
        $sppgLog = $kelas === Sppg::class ? $model->getKey() : $this->sppgA;

        $model->forceFill([$kolom => $nilai])->save();
        $model->delete();

        $log = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', $model->getMorphClass())->where('auditable_id', $model->getKey())->orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'deleted'], $log->pluck('event')->all());
        $this->assertSame($sppgLog, $log[0]->sppg_id);
        $this->assertNull($log[0]->user_id, 'Tanpa pengguna login (seeder/impor/job) tetap tercatat tanpa galat.');
        $this->assertArrayHasKey($kolom, $log[1]->new_values);
    }

    public function test_hapus_lewat_api_oleh_admin_mencatat_pelaku_dan_sppg(): void
    {
        $pemasok = Pemasok::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'UD Fiktif Hapus']);
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);

        $this->as($this->admin);
        $this->deleteJson("/api/v1/pemasok/{$pemasok->id}")->assertNoContent();
        $this->deleteJson("/api/v1/titik-layanan/{$titik->id}")->assertNoContent();

        foreach ([$pemasok, $titik] as $model) {
            $log = AuditLog::query()->withoutGlobalScopes()->where('event', 'deleted')
                ->where('auditable_type', $model->getMorphClass())->where('auditable_id', $model->getKey())->sole();
            $this->assertSame($this->admin->id, $log->user_id);
            $this->assertSame($this->sppgA, $log->sppg_id);
        }
    }

    public function test_perubahan_no_va_dan_parameter_anggaran_sppg_tercatat_dengan_nilai_lama_dan_baru(): void
    {
        $this->forgetActor();
        Sppg::query()->whereKey($this->sppgA)->first()->forceFill(['no_va' => '8881234500', 'insentif_fasilitas_per_hari' => 6_000_000])->save();

        $this->as($this->kepalaA)->patchJson("/api/v1/sppg/{$this->sppgA}", [
            'no_va' => '8889999900',
            'acuan_bahan_baku_kecil' => 9000,
            'insentif_fasilitas_per_hari' => 7_000_000,
        ])->assertOk();

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', 'updated')->where('auditable_type', (new Sppg)->getMorphClass())
            ->where('auditable_id', $this->sppgA)->latest('id')->firstOrFail();

        $this->assertSame($this->kepalaA->id, $log->user_id);
        $this->assertSame($this->sppgA, $log->sppg_id);
        $this->assertSame(['no_va' => '8881234500', 'acuan_bahan_baku_kecil' => null, 'insentif_fasilitas_per_hari' => 6_000_000], $log->old_values);
        $this->assertEquals(['no_va' => '8889999900', 'acuan_bahan_baku_kecil' => 9000, 'insentif_fasilitas_per_hari' => 7_000_000], $log->new_values);
    }

    public function test_saldo_awal_hanya_punya_satu_event_audit_khusus_tanpa_log_updated_ganda(): void
    {
        $this->as($this->akuntanA)->putJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", ['saldo_awal' => 25_000_000, 'saldo_awal_tanggal' => '2026-11-01'])->assertOk();

        $logs = AuditLog::query()->withoutGlobalScopes()->where('auditable_type', (new Sppg)->getMorphClass())
            ->where('auditable_id', $this->sppgA)->where('user_id', $this->akuntanA->id)->get();

        $this->assertSame(['saldo_awal_diubah'], $logs->pluck('event')->all());
        $this->assertSame(25_000_000, $logs->sole()->new_values['saldo_awal']);
    }

    public function test_kolom_data_pribadi_dan_berkas_tidak_pernah_masuk_audit(): void
    {
        $this->forgetActor();
        Pengemudi::factory()->create(['sppg_id' => $this->sppgA, 'no_hp' => '0812-0000-7777', 'no_sim' => 'SIM-FIKTIF-123']);
        TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'pic_telepon' => '0812-0000-8888', 'pic_nama' => 'Bu PIC Fiktif', 'pic_telegram_chat_id' => 900000555]);
        Pemasok::factory()->create(['sppg_id' => $this->sppgA, 'kontak' => '0812-0000-9999']);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Keluhan rahasia fiktif', 'foto_path' => 'keluhan/foto-rahasia.jpg', 'tindak_lanjut' => 'Tindak lanjut rahasia']);

        $json = AuditLog::query()->withoutGlobalScopes()->get()->toJson();

        foreach (['0812-0000-7777', 'SIM-FIKTIF-123', '0812-0000-8888', 'Bu PIC Fiktif', '900000555', '0812-0000-9999', 'Keluhan rahasia fiktif', 'foto-rahasia', 'Tindak lanjut rahasia'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $json, $rahasia);
        }
        $this->assertGreaterThanOrEqual(4, AuditLog::query()->withoutGlobalScopes()->where('event', 'created')->count());
    }
}

<?php

namespace Tests\Feature\Audit;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\BahanBaku;
use App\Models\FeedbackKeluhan;
use App\Models\Pengeluaran;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Master\MasterApiTestCase;

/**
 * #172 (P13): GET /api/v1/audit-log khusus Admin. Data fiktif.
 */
class AuditLogApiTest extends MasterApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Buang jejak audit dari fixture (pengguna/SPPG) agar hitungan tiap tes hanya memuat log buatan tes.
        DB::table('audit_logs')->delete();
    }

    private function buatLog(string $type, string $event, ?int $userId, string $waktu, array $baru = ['x' => 1], ?array $lama = null): AuditLog
    {
        $log = AuditLog::factory()->create([
            'sppg_id' => $this->sppgA,
            'user_id' => $userId,
            'event' => $event,
            'auditable_type' => $type,
            'auditable_id' => 7,
            'old_values' => $lama,
            'new_values' => $baru,
        ]);
        $log->forceFill(['created_at' => Carbon::parse($waktu), 'updated_at' => Carbon::parse($waktu)])->saveQuietly();

        return $log;
    }

    /** @return array<string, array{Role, RelawanUnit|null}> */
    public static function perannonAdmin(): array
    {
        return [
            'kepala_sppg' => [Role::KepalaSppg, null],
            'ahli_gizi' => [Role::AhliGizi, null],
            'akuntan' => [Role::Akuntan, null],
            'staf_gudang' => [Role::StafGudang, null],
            'relawan dapur' => [Role::Relawan, RelawanUnit::Dapur],
            'relawan distribusi' => [Role::Relawan, RelawanUnit::Distribusi],
            'investor' => [Role::Investor, null],
        ];
    }

    public function test_seluruh_peran_non_admin_tercakup_dalam_data_uji(): void
    {
        $peran = array_unique(array_map(fn (array $p) => $p[0]->value, self::perannonAdmin()));
        $semua = array_values(array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r !== Role::Admin)));

        $this->assertEqualsCanonicalizing($semua, array_values($peran));
    }

    #[DataProvider('perannonAdmin')]
    public function test_peran_non_admin_mendapat_403_di_semua_endpoint(Role $role, ?RelawanUnit $unit): void
    {
        $user = User::factory()->role($role, $unit)->create(['sppg_id' => $role === Role::Investor ? null : $this->sppgA]);
        $log = $this->buatLog(FeedbackKeluhan::class, 'created', null, '2026-11-10 08:00:00');

        $this->as($user);
        $this->getJson('/api/v1/audit-log')->assertForbidden();
        $this->getJson('/api/v1/audit-log?to=bukan-tanggal')->assertForbidden();
        $this->getJson('/api/v1/audit-log/opsi')->assertForbidden();
        $this->getJson('/api/v1/audit-log/'.$log->id)->assertForbidden();
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/audit-log')->assertUnauthorized();
        $this->getJson('/api/v1/audit-log/opsi')->assertUnauthorized();
        $this->getJson('/api/v1/audit-log/1')->assertUnauthorized();
    }

    public function test_admin_nonaktif_ditolak(): void
    {
        $nonaktif = User::factory()->admin()->create(['is_active' => false]);

        $this->as($nonaktif);
        $this->getJson('/api/v1/audit-log')->assertStatus(403);
    }

    public function test_admin_200_bentuk_item_dan_paginasi(): void
    {
        $this->buatLog(FeedbackKeluhan::class, 'updated', $this->kepalaA->id, '2026-11-10 08:00:00', ['status' => 'diproses'], ['status' => 'baru']);

        $this->as($this->admin);
        $res = $this->getJson('/api/v1/audit-log')->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'waktu', 'pengguna' => ['id', 'nama'], 'sppg_id', 'sumber', 'modul', 'modul_label', 'rekaman_id', 'aksi', 'nilai_lama', 'nilai_baru']],
                'links', 'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $res->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('data.0.modul', 'keluhan')
            ->assertJsonPath('data.0.aksi', 'updated')
            ->assertJsonPath('data.0.pengguna.id', $this->kepalaA->id)
            ->assertJsonPath('data.0.pengguna.nama', $this->kepalaA->name)
            ->assertJsonPath('data.0.rekaman_id', 7)
            ->assertJsonPath('data.0.sumber', 'web')
            ->assertJsonPath('data.0.nilai_lama.status', 'baru')
            ->assertJsonPath('data.0.nilai_baru.status', 'diproses');
        $this->assertArrayNotHasKey('ip', $res->json('data.0'));
    }

    public function test_urut_terbaru_dulu_dan_paginasi_per_page(): void
    {
        foreach (range(1, 5) as $i) {
            $this->buatLog(FeedbackKeluhan::class, 'created', null, "2026-11-0{$i} 08:00:00");
        }

        $this->as($this->admin);
        $res = $this->getJson('/api/v1/audit-log?per_page=2&page=2')->assertOk();
        $res->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);

        $semua = $this->getJson('/api/v1/audit-log')->assertOk()->json('data');
        $ids = array_column($semua, 'id');
        $urut = $ids;
        rsort($urut);
        $this->assertSame($urut, $ids);
    }

    public function test_per_page_maksimal_100(): void
    {
        $this->as($this->admin);

        $this->getJson('/api/v1/audit-log?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/audit-log?per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_filter_modul_aksi_user_dan_rentang_tanggal(): void
    {
        $a = $this->buatLog(FeedbackKeluhan::class, 'created', $this->kepalaA->id, '2026-11-10 08:00:00');
        $b = $this->buatLog(BahanBaku::class, 'updated', $this->stafGudangA->id, '2026-11-12 23:30:00');
        $c = $this->buatLog(BahanBaku::class, 'created', $this->stafGudangA->id, '2026-11-15 00:10:00');

        $this->as($this->admin);
        $ids = fn (string $q): array => collect($this->getJson('/api/v1/audit-log?'.$q)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$a->id], $ids('modul=keluhan'));
        $this->assertSame([$b->id, $c->id], $ids('modul=bahan-baku'));
        $this->assertSame([$b->id], $ids('modul=bahan-baku&aksi=updated'));
        $this->assertSame([$b->id, $c->id], $ids('user_id='.$this->stafGudangA->id));
        $this->assertSame([$b->id], $ids('from=2026-11-12&to=2026-11-12'));
        $this->assertSame([$c->id], $ids('from=2026-11-13'));
        $this->assertSame([$a->id], $ids('to=2026-11-11'));
        $this->assertSame([], $ids('modul=keluhan&aksi=deleted'));
    }

    public function test_validasi_422(): void
    {
        $this->as($this->admin);

        $this->getJson('/api/v1/audit-log?from=2026-13-45')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/audit-log?to=10-11-2026')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/audit-log?from=2026-11-20&to=2026-11-10')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/audit-log?modul=tidak-ada')->assertStatus(422)->assertJsonValidationErrors('modul');
        $this->getJson('/api/v1/audit-log?user_id=abc')->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->getJson('/api/v1/audit-log?aksi='.urlencode("created'; drop"))->assertStatus(422)->assertJsonValidationErrors('aksi');
    }

    public function test_kolom_audit_exclude_dan_sensitif_tidak_tampil_termasuk_log_lama(): void
    {
        // Log "lama" yang terlanjur memuat kolom yang kini dikecualikan model.
        $this->buatLog(FeedbackKeluhan::class, 'updated', $this->kepalaA->id, '2026-11-10 08:00:00', [
            'status' => 'diproses', 'deskripsi' => 'RAHASIA-DESKRIPSI', 'tindak_lanjut' => 'RAHASIA-TL', 'foto_path' => 'RAHASIA-FOTO',
            'password' => 'RAHASIA-PW', 'remember_token' => 'RAHASIA-RT', 'telegram_hash' => 'RAHASIA-TH', 'nik' => '3200000000000001',
            'detail' => ['token_hash' => 'RAHASIA-NESTED', 'aman' => 'ok'],
        ], ['deskripsi' => 'RAHASIA-LAMA', 'status' => 'baru']);
        $this->buatLog(Pengeluaran::class, 'created', null, '2026-11-10 09:00:00', ['nominal' => '1000', 'uraian' => 'RAHASIA-URAIAN', 'nota_path' => 'RAHASIA-NOTA']);
        $log = $this->buatLog(User::class, 'updated', null, '2026-11-10 10:00:00', ['name' => 'Aman', 'phone' => 'RAHASIA-HP', 'username' => 'RAHASIA-UNAME', 'password' => 'RAHASIA-PW2']);

        $this->as($this->admin);
        $daftar = $this->getJson('/api/v1/audit-log')->assertOk();
        $detail = $this->getJson('/api/v1/audit-log/'.$log->id)->assertOk();

        foreach ([$daftar->getContent(), $detail->getContent()] as $isi) {
            $this->assertStringNotContainsString('RAHASIA', $isi);
        }

        $daftar->assertJsonFragment(['status' => 'diproses'])->assertJsonFragment(['aman' => 'ok'])->assertJsonFragment(['nominal' => '1000']);
        $detail->assertJsonPath('data.nilai_baru.name', 'Aman')->assertJsonPath('data.modul', 'pengguna');
    }

    public function test_detail_404_dan_opsi(): void
    {
        $this->buatLog(FeedbackKeluhan::class, 'ai_eksekusi_draf', null, '2026-11-10 08:00:00');

        $this->as($this->admin);
        $this->getJson('/api/v1/audit-log/999999')->assertNotFound();

        $opsi = $this->getJson('/api/v1/audit-log/opsi')->assertOk()->assertJsonStructure(['data' => ['modul' => [['kode', 'label']], 'aksi']]);
        $this->assertContains('keluhan', array_column($opsi->json('data.modul'), 'kode'));
        $this->assertContains('bahan-baku', array_column($opsi->json('data.modul'), 'kode'));
        $this->assertContains('created', $opsi->json('data.aksi'));
        $this->assertContains('ai_eksekusi_draf', $opsi->json('data.aksi'));
    }

    public function test_admin_melihat_lintas_sppg(): void
    {
        $log = $this->buatLog(FeedbackKeluhan::class, 'created', null, '2026-11-10 08:00:00');
        $log->forceFill(['sppg_id' => $this->sppgB])->saveQuietly();

        $this->as($this->admin);
        $this->getJson('/api/v1/audit-log')->assertOk()->assertJsonPath('data.0.sppg_id', $this->sppgB);
    }
}

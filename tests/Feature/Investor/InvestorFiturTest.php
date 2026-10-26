<?php

namespace Tests\Feature\Investor;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PenerimaManfaat;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Users\KepemilikanSppgService;
use App\Support\SppgAktif;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\AlurLogin;

/**
 * R-06a: agregat penerima tanpa data pribadi, kepemilikan SPPG oleh Admin, undangan & login
 * investor lewat alur R-03.
 */
class InvestorFiturTest extends InvestorTestCase
{
    use AlurLogin;

    // ---------------------------------------------------------------- agregat penerima

    public function test_agregat_penerima_hanya_hitungan_tanpa_data_pribadi(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'SDN Fiktif 1']);
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id, 'nama' => 'Anak Rahasia Satu', 'kelompok_sasaran' => 'siswa', 'kelas_keterangan' => 'Kelas 4B', 'catatan_alergi' => 'Alergi kacang tanah']);
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id, 'nama' => 'Anak Rahasia Dua', 'kelompok_sasaran' => 'siswa']);
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id, 'kelompok_sasaran' => 'balita', 'is_active' => false]);
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgB, 'kelompok_sasaran' => 'balita']);

        $res = $this->sebagai($this->investorAB, $this->sppgA)->getJson('/api/v1/penerima-manfaat/agregat')->assertOk()
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.aktif', 2)
            ->assertJsonPath('data.per_kelompok_sasaran.0', ['kelompok_sasaran' => 'siswa', 'label' => 'Siswa', 'jumlah' => 2])
            ->assertJsonPath('data.per_kelompok_sasaran.1.jumlah', 0)
            ->assertJsonPath('data.per_titik_layanan', [['titik_layanan_id' => $titik->id, 'nama' => 'SDN Fiktif 1', 'jumlah' => 2]]);

        $json = $res->getContent();
        foreach (['Anak Rahasia', 'Kelas 4B', 'Alergi kacang', 'catatan_alergi', 'kelas_keterangan', '"nama_penerima"'] as $pribadi) {
            $this->assertStringNotContainsString($pribadi, $json);
        }

        // SPPG aktif B: hanya data B.
        $this->sebagai($this->investorAB, $this->sppgB)->getJson('/api/v1/penerima-manfaat/agregat')->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_akses_agregat_per_peran(): void
    {
        $this->sebagai($this->investorAB);
        $this->assertAlasan($this->getJson('/api/v1/penerima-manfaat/agregat'), 409, SppgAktif::BELUM_DIPILIH);
        $this->sebagai($this->investorAB, $this->sppgC);
        $this->assertAlasan($this->getJson('/api/v1/penerima-manfaat/agregat'), 403, SppgAktif::DITOLAK);

        $this->sebagai($this->ahliGiziA)->getJson('/api/v1/penerima-manfaat/agregat')->assertOk()->assertJsonPath('data.sppg_id', $this->sppgA);
        $this->sebagai($this->kepalaA)->getJson('/api/v1/penerima-manfaat/agregat')->assertOk();
        $this->sebagai($this->akuntanA)->getJson('/api/v1/penerima-manfaat/agregat')->assertForbidden();
        $this->sebagai($this->relawanA)->getJson('/api/v1/penerima-manfaat/agregat')->assertForbidden();

        $this->sebagai($this->admin)->getJson('/api/v1/penerima-manfaat/agregat')->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->getJson("/api/v1/penerima-manfaat/agregat?sppg_id={$this->sppgC}")->assertOk()->assertJsonPath('data.sppg_id', $this->sppgC);
    }

    // ---------------------------------------------------------------- kepemilikan (Admin)

    public function test_admin_mengatur_kepemilikan_dan_tercatat_audit(): void
    {
        $this->sebagai($this->admin)->getJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg")->assertOk()
            ->assertJsonPath('data.role', 'investor')->assertJsonCount(2, 'data.sppg');

        $this->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", ['sppg_ids' => [$this->sppgB, $this->sppgC]])->assertOk()
            ->assertJsonPath('data.user_id', $this->investorAB->id)
            ->assertJsonPath('data.sppg.0.id', $this->sppgB)
            ->assertJsonPath('data.sppg.1.id', $this->sppgC);

        $this->assertEqualsCanonicalizing([$this->sppgB, $this->sppgC], DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->pluck('sppg_id')->all());
        $this->assertSame($this->admin->id, (int) DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgC)->value('created_by'));

        $this->forgetActor();
        $audit = AuditLog::query()->where('event', KepemilikanSppgService::AUDIT_DIUBAH)->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($this->investorAB->id, $audit->auditable_id);
        $this->assertSame([$this->sppgA], $audit->new_values['dihapus']);
        $this->assertSame([$this->sppgC], $audit->new_values['ditambah']);

        // Berlaku segera: SPPG A tidak lagi dapat dipakai investor.
        $this->sebagai($this->investorAB, $this->sppgA);
        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);
        $this->sebagai($this->investorAB, $this->sppgC)->getJson('/api/v1/keluhan')->assertOk();

        // Kosongkan.
        $this->sebagai($this->admin)->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", ['sppg_ids' => []])->assertOk()->assertJsonCount(0, 'data.sppg');
    }

    public function test_admin_tautkan_dan_lepas_satu_sppg_idempoten(): void
    {
        $url = "/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg";
        $this->sebagai($this->admin);

        $this->postJson($url, ['sppg_id' => $this->sppgC])->assertOk()->assertJsonCount(3, 'data.sppg');
        $this->postJson($url, ['sppg_id' => $this->sppgC])->assertOk()->assertJsonCount(3, 'data.sppg');
        $this->assertSame(1, DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgC)->count());

        $this->deleteJson("{$url}/{$this->sppgA}")->assertOk()->assertJsonCount(2, 'data.sppg');
        $this->deleteJson("{$url}/{$this->sppgA}")->assertOk()->assertJsonCount(2, 'data.sppg');
        $this->assertSame(1, DB::table('investor_sppg')->where('user_id', $this->investorC->id)->count(), 'Investor lain tidak tersentuh.');

        $this->forgetActor();
        $this->assertSame(2, AuditLog::query()->where('event', KepemilikanSppgService::AUDIT_DIUBAH)->count(), 'Aksi tanpa perubahan tidak diaudit.');

        $this->sebagai($this->admin);
        $this->postJson($url, ['sppg_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson("/api/v1/pengguna/{$this->kepalaA->id}/kepemilikan-sppg", ['sppg_id' => $this->sppgA])->assertUnprocessable()->assertJsonValidationErrors(['user']);
        $this->deleteJson("/api/v1/pengguna/{$this->kepalaA->id}/kepemilikan-sppg/{$this->sppgA}")->assertUnprocessable()->assertJsonValidationErrors(['user']);

        // Non-admin: 403 (investor sendiri) / 404 (di luar scope).
        $this->sebagai($this->investorAB, $this->sppgB)->postJson($url, ['sppg_id' => $this->sppgA])->assertForbidden();
        $this->sebagai($this->investorAB, $this->sppgB)->deleteJson("{$url}/{$this->sppgB}")->assertForbidden();
        $this->sebagai($this->ahliGiziA)->postJson($url, ['sppg_id' => $this->sppgA])->assertNotFound();
        $this->assertSame(0, DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgA)->count());
    }

    public function test_validasi_kepemilikan(): void
    {
        $this->sebagai($this->admin);

        $this->putJson("/api/v1/pengguna/{$this->kepalaA->id}/kepemilikan-sppg", ['sppg_ids' => [$this->sppgA]])
            ->assertUnprocessable()->assertJsonValidationErrors(['user']);
        $this->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_ids']);
        $this->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", ['sppg_ids' => [999999]])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_ids.0']);
        $this->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", ['sppg_ids' => [$this->sppgA, $this->sppgA]])
            ->assertUnprocessable();
        $this->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", ['sppg_ids' => 'semua'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_ids']);
    }

    public function test_non_admin_tidak_dapat_mengatur_kepemilikan(): void
    {
        $body = ['sppg_ids' => [$this->sppgA, $this->sppgB, $this->sppgC]];

        $this->sebagai($this->kepalaA)->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", $body)->assertNotFound();
        $this->sebagai($this->investorAB, $this->sppgA)->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", $body)->assertForbidden();
        $this->sebagai($this->investorAB, $this->sppgA)->getJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg")->assertForbidden();
        $this->sebagai($this->investorC)->putJson("/api/v1/pengguna/{$this->investorAB->id}/kepemilikan-sppg", $body)->assertNotFound();

        $this->assertSame(0, DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgC)->count());
    }

    public function test_peran_investor_diubah_menghapus_kepemilikan_dan_sppg_id_investor_wajib_kosong(): void
    {
        $this->sebagai($this->admin);

        $this->patchJson("/api/v1/pengguna/{$this->investorAB->id}", ['sppg_id' => $this->sppgA])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->patchJson("/api/v1/pengguna/{$this->investorAB->id}", ['role' => 'kepala_sppg', 'sppg_id' => $this->sppgA])
            ->assertOk()->assertJsonPath('data.role', 'kepala_sppg');
        $this->assertSame(0, DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->count());

        // Kepala -> investor: sppg_id dikosongkan otomatis.
        $this->patchJson("/api/v1/pengguna/{$this->kepalaB->id}", ['role' => 'investor'])
            ->assertOk()->assertJsonPath('data.role', 'investor')->assertJsonPath('data.sppg_id', null);

        $this->postJson('/api/v1/pengguna', ['name' => 'Investor Baru (Demo)', 'role' => 'investor'])
            ->assertCreated()->assertJsonPath('data.sppg_id', null);
        $this->postJson('/api/v1/pengguna', ['name' => 'Investor Salah (Demo)', 'role' => 'investor', 'sppg_id' => $this->sppgA])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
    }

    // ---------------------------------------------------------------- undangan + login R-03

    public function test_undangan_peran_investor_72_jam(): void
    {
        $this->sebagai($this->admin);

        $res = $this->postJson('/api/v1/pengguna/undangan', ['role' => 'investor'])->assertCreated()
            ->assertJsonPath('data.role', 'investor')
            ->assertJsonPath('data.sppg_id', null);
        $this->assertNotEmpty($res->json('data.token'));

        $this->postJson('/api/v1/pengguna/undangan', ['role' => 'investor', 'sppg_id' => $this->sppgA])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/pengguna/undangan', ['role' => 'investor', 'unit' => 'dapur'])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit']);

        // Investor sendiri tidak bisa mengundang.
        $this->sebagai($this->investorAB, $this->sppgA)->postJson('/api/v1/pengguna/undangan', ['role' => 'investor'])->assertForbidden();
    }

    public function test_login_investor_lewat_alur_r03_dan_token_tanpa_ability_tulis(): void
    {
        $this->fakeTelegram();
        $this->forgetActor();
        $investor = User::factory()->role(Role::Investor)->denganKredensial('investor.satu', 'Rahasia123x')
            ->create(['sppg_id' => null, 'telegram_id' => 7099900011]);
        DB::table('investor_sppg')->insert([
            ['user_id' => $investor->id, 'sppg_id' => $this->sppgA, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $investor->id, 'sppg_id' => $this->sppgB, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $token = $this->loginPenuh($investor, 'investor.satu', 'Rahasia123x');

        $abilities = (array) PersonalAccessToken::findToken($token)->abilities;
        $this->assertContains('role:investor', $abilities);
        $this->assertSame([], array_values(array_filter($abilities, fn ($a) => preg_match('/^[a-z-]+:[CUD]$/', $a) === 1)));

        $this->lupakanGuard();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.role', 'investor')->assertJsonPath('user.multi_sppg', true);
        $this->lupakanGuard();
        $this->assertAlasan($this->withToken($token)->getJson('/api/v1/keluhan'), 409, SppgAktif::BELUM_DIPILIH);
        $this->lupakanGuard();
        $this->withToken($token)->withHeader('X-SPPG-Id', (string) $this->sppgB)->getJson('/api/v1/keluhan')->assertOk();
        $this->lupakanGuard();
        $this->assertAlasan($this->withToken($token)->withHeader('X-SPPG-Id', (string) $this->sppgC)->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);
    }
}

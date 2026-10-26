<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PermintaanResetPassword;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AlurLogin;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R-03 lupa password = permintaan reset yang disetujui Admin dalam scope-nya, lalu password baru +
 * token 6 digit dari bot ke telegram_id pemilik.
 */
class PermintaanResetTest extends TestCase
{
    use AlurLogin, CreatesSppg, RefreshDatabase;

    private const PASSWORD = 'Rahasia123x';

    private const BARU = 'BaruSekali9z';

    private int $sppgA;

    private User $siti;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        $this->sppgA = $this->createSppg('SPPG Sukamaju');
        $this->siti = User::factory()->role(Role::StafGudang)->denganKredensial('siti.gudang', self::PASSWORD)
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7012345678, 'phone' => '081234567890']);
        $this->admin = User::factory()->admin()->denganKredensial('admin.pusat', 'AdminRahasia9')->create(['telegram_id' => 7000000001]);
    }

    private function adminPenuh(?User $admin = null): string
    {
        return $this->beriSesiPenuh($admin ?? $this->admin, 'laptop-admin-dddddddddddddddddddd');
    }

    private function minta(User $user): string
    {
        $pra = $this->praLogin($user);
        $this->kirim($pra, '/api/v1/auth/lupa-password')->assertCreated()->assertJsonPath('data.status', 'menunggu');

        return $pra;
    }

    /** @return array<string, string> */
    private function passwordBaru(): array
    {
        return ['password' => self::BARU, 'password_confirmation' => self::BARU, 'device_id' => self::HP];
    }

    public function test_alur_lengkap_minta_disetujui_token_lalu_password_baru_dan_sesi_dicabut(): void
    {
        $lama = $this->beriSesiPenuh($this->siti, 'tablet-lama-eeeeeeeeeeeeeeeeeeee');
        $pra = $this->minta($this->siti);

        // Admin diberi tahu lewat bot tanpa no telepon; pemilik belum menerima token apa pun.
        $notif = $this->messagesTo(7000000001)->sole();
        $this->assertStringContainsString('reset password', $notif['text']);
        $this->assertStringContainsString($this->siti->name, $notif['text']);
        $this->assertStringNotContainsString('0812', $notif['text']);
        $this->assertCount(0, $this->messagesTo(7012345678));

        // Satu permintaan aktif per akun: permintaan ulang mengembalikan yang sama, tanpa notifikasi baru.
        $id = $this->kirim($pra, '/api/v1/auth/lupa-password')->assertOk()->json('data.id');
        $this->assertSame(1, PermintaanResetPassword::query()->count());
        $this->assertCount(1, $this->messagesTo(7000000001));
        $this->kirim($pra, '/api/v1/auth/lupa-password', method: 'GET')->assertOk()->assertJsonPath('data.status', 'menunggu');

        // Tanpa persetujuan: tidak ada token terkirim.
        $this->kirim($pra, '/api/v1/auth/lupa-password/lanjut', $this->passwordBaru())
            ->assertStatus(409)->assertJsonPath('alasan', 'reset_belum_disetujui');
        $this->assertCount(0, $this->messagesTo(7012345678));

        $daftar = $this->kirim($this->adminPenuh(), '/api/v1/pengguna/permintaan-reset', method: 'GET')->assertOk();
        $this->assertSame($id, $daftar->json('data.0.id'));
        $this->assertSame('siti.gudang', $daftar->json('data.0.pengguna.username'));
        $this->assertStringNotContainsString('0812', $daftar->getContent());

        $this->kirim($this->adminPenuh(), "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertOk()->assertJsonPath('data.status', 'disetujui');
        // Pemberitahuan persetujuan ke pemilik tidak memuat token.
        $this->assertStringContainsString('disetujui', $this->messagesTo(7012345678)->sole()['text']);
        $this->assertSame(0, preg_match('/\d{6}/', $this->messagesTo(7012345678)->sole()['text']));
        $this->assertTrue(Hash::check(self::PASSWORD, $this->siti->fresh()->password), 'Persetujuan saja tidak mengubah password.');

        $pra = $this->praLogin($this->siti);
        $tantangan = $this->kirim($pra, '/api/v1/auth/lupa-password/lanjut', $this->passwordBaru())
            ->assertStatus(202)->assertJsonPath('tantangan.tujuan', 'reset_password')->json('tantangan.id');
        $penuh = $this->kirim($pra, '/api/v1/auth/token/verifikasi', [
            'tantangan_id' => $tantangan, 'kode' => $this->kodeTerakhir(7012345678), 'device_id' => self::HP,
        ])->assertOk()->assertJsonPath('tujuan', 'reset_password')->json('token');

        $this->assertTrue(Hash::check(self::BARU, $this->siti->fresh()->password));
        $this->kirim($lama, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($penuh, '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->assertSame('selesai', PermintaanResetPassword::query()->sole()->status->value);

        foreach ([AuditAuth::RESET_DIMINTA, AuditAuth::RESET_DISETUJUI, AuditAuth::RESET_PASSWORD_BERHASIL] as $event) {
            $this->assertSame(1, AuditLog::query()->where('event', $event)->count(), $event);
        }
        $this->assertSame($this->admin->id, AuditLog::query()->where('event', AuditAuth::RESET_DISETUJUI)->value('user_id'));
        $this->assertStringNotContainsString(self::BARU, AuditLog::query()->get()->toJson());

        // Permintaan sudah selesai: tidak bisa dipakai lagi.
        $pra = $this->praLogin($this->siti);
        $this->kirim($pra, '/api/v1/auth/lupa-password/lanjut', $this->passwordBaru())->assertStatus(409);
    }

    public function test_permintaan_ditolak_tidak_mengirim_token(): void
    {
        $pra = $this->minta($this->siti);
        $id = PermintaanResetPassword::query()->sole()->id;

        $this->kirim($this->adminPenuh(), "/api/v1/pengguna/permintaan-reset/{$id}/tolak")->assertOk()->assertJsonPath('data.status', 'ditolak');
        $this->kirim($this->adminPenuh(), "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertStatus(409)->assertJsonPath('alasan', 'permintaan_tidak_aktif');

        $this->kirim($pra, '/api/v1/auth/lupa-password/lanjut', $this->passwordBaru())->assertStatus(409);
        $this->assertSame(0, collect($this->messagesTo(7012345678))->filter(fn ($r) => str_contains($r['text'], 'Token SiGAP'))->count());
        $this->kirim($pra, '/api/v1/auth/lupa-password', method: 'GET')->assertJsonPath('data.status', 'ditolak');
    }

    public function test_permintaan_akun_lain_tidak_bisa_dipakai(): void
    {
        $this->minta($this->siti);
        $id = PermintaanResetPassword::query()->sole()->id;
        $this->kirim($this->adminPenuh(), "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertOk();

        // Budi (akun lain, SPPG sama) tidak punya permintaan disetujui: tidak bisa menetapkan password.
        $budi = User::factory()->role(Role::StafGudang)->denganKredensial('budi.gudang', self::PASSWORD)
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7099990000]);
        $praBudi = $this->praLogin($budi);
        $this->kirim($praBudi, '/api/v1/auth/lupa-password/lanjut', $this->passwordBaru())
            ->assertStatus(409)->assertJsonPath('alasan', 'reset_belum_disetujui');
        $this->kirim($praBudi, '/api/v1/auth/lupa-password', method: 'GET')->assertOk()->assertJsonPath('data', null);

        // Tantangan reset Siti tidak bisa diverifikasi dengan token pra-login Budi.
        $praSiti = $this->praLogin($this->siti);
        $tantangan = $this->kirim($praSiti, '/api/v1/auth/lupa-password/lanjut', $this->passwordBaru())->assertStatus(202)->json('tantangan.id');
        $this->kirim($praBudi, '/api/v1/auth/token/verifikasi', [
            'tantangan_id' => $tantangan, 'kode' => $this->kodeTerakhir(7012345678), 'device_id' => self::HP,
        ])->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');

        $this->assertTrue(Hash::check(self::PASSWORD, $budi->fresh()->password));
        $this->assertTrue(Hash::check(self::PASSWORD, $this->siti->fresh()->password));
        $this->assertCount(0, $this->messagesTo(7099990000));
    }

    public function test_tanpa_hak_atau_di_luar_scope_403(): void
    {
        $kepalaA = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7011110001]);
        $kepalaB = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->createSppg('SPPG Lain'), 'telegram_id' => 7011110002]);
        $this->minta($this->siti);
        $id = PermintaanResetPassword::query()->sole()->id;

        foreach ([$kepalaA, $kepalaB, $this->siti] as $aktor) {
            $token = $this->beriSesiPenuh($aktor, 'perangkat-aktor-ffffffffffffffffff');
            $this->kirim($token, '/api/v1/pengguna/permintaan-reset', method: 'GET')->assertForbidden();
            $this->kirim($token, "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertForbidden();
            $this->kirim($token, "/api/v1/pengguna/permintaan-reset/{$id}/tolak")->assertForbidden();
        }

        // Token pra-login (belum login penuh) juga ditolak.
        $this->kirim($this->praLogin($this->admin), "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertForbidden();
        $this->kirim($this->adminPenuh(), '/api/v1/pengguna/permintaan-reset/01JBDQ6V9X8Y7Z6W5V4U3T2S1R/setujui')->assertNotFound();
        $this->assertSame('menunggu', PermintaanResetPassword::query()->sole()->status->value);
    }

    public function test_admin_tidak_bisa_menyetujui_permintaannya_sendiri(): void
    {
        $adminLain = User::factory()->admin()->create(['telegram_id' => 7000000002]);
        $pra = $this->minta($this->admin);
        $id = PermintaanResetPassword::query()->sole()->id;

        // Admin lain diberi tahu; pemohon sendiri tidak.
        $this->assertCount(1, $this->messagesTo(7000000002));
        $this->assertCount(0, $this->messagesTo(7000000001));

        $sendiri = $this->beriSesiPenuh($this->admin, 'laptop-admin-lain-gggggggggggggggg');
        $this->kirim($sendiri, "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertForbidden();
        $this->kirim($sendiri, '/api/v1/pengguna/permintaan-reset', method: 'GET')->assertOk()->assertJsonCount(0, 'data');

        $this->kirim($this->adminPenuh($adminLain), "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertOk();
        $this->kirim($pra, '/api/v1/auth/lupa-password', method: 'GET')->assertJsonPath('data.status', 'disetujui');
    }

    public function test_admin_tunggal_diarahkan_ke_perintah_server(): void
    {
        $pra = $this->praLogin($this->admin);

        $this->kirim($pra, '/api/v1/auth/lupa-password')->assertStatus(409)->assertJsonPath('alasan', 'tidak_ada_pemutus');
        $this->assertSame(0, PermintaanResetPassword::query()->count());
    }

    public function test_permintaan_kedaluwarsa_24_jam(): void
    {
        $this->minta($this->siti);
        $id = PermintaanResetPassword::query()->sole()->id;

        $this->travel(24)->hours();
        $this->travel(1)->seconds();

        $this->kirim($this->adminPenuh(), "/api/v1/pengguna/permintaan-reset/{$id}/setujui")
            ->assertStatus(409)->assertJsonPath('alasan', 'permintaan_tidak_aktif')->assertJsonPath('status', 'kedaluwarsa');
        $pra = $this->praLogin($this->siti);
        $this->kirim($pra, '/api/v1/auth/lupa-password', method: 'GET')->assertJsonPath('data.status', 'kedaluwarsa');
        $this->kirim($this->adminPenuh(), '/api/v1/pengguna/permintaan-reset?status=kedaluwarsa', method: 'GET')->assertJsonCount(1, 'data');

        // Permintaan baru boleh diajukan setelah yang lama kedaluwarsa.
        $this->kirim($pra, '/api/v1/auth/lupa-password')->assertCreated();
    }

    public function test_persetujuan_kedaluwarsa_sebelum_token_dikonfirmasi(): void
    {
        $this->minta($this->siti);
        $id = PermintaanResetPassword::query()->sole()->id;
        $this->travel(24 * 60 - 1)->minutes();
        $this->kirim($this->adminPenuh(), "/api/v1/pengguna/permintaan-reset/{$id}/setujui")->assertOk();

        // Token diminta 1 menit sebelum batas, dikonfirmasi 2 menit kemudian (token & pra-login masih
        // berlaku, permintaan sudah kedaluwarsa): ditolak dan password tidak berubah.
        $pra = $this->praLogin($this->siti);
        $tantangan = $this->kirim($pra, '/api/v1/auth/lupa-password/lanjut', $this->passwordBaru())->assertStatus(202)->json('tantangan.id');
        $kode = $this->kodeTerakhir(7012345678);
        $this->travel(2)->minutes();

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $tantangan, 'kode' => $kode, 'device_id' => self::HP])
            ->assertStatus(409)->assertJsonPath('alasan', 'reset_belum_disetujui');
        $this->assertTrue(Hash::check(self::PASSWORD, $this->siti->fresh()->password));
        $this->assertSame(0, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
    }

    public function test_akun_terkunci_tidak_bisa_meminta_reset(): void
    {
        User::withoutGlobalScopes()->whereKey($this->siti->id)->update(['locked_until' => now()->addMinutes(15), 'failed_login_attempts' => 5]);
        $pra = $this->praLogin($this->siti);

        $this->kirim($pra, '/api/v1/auth/lupa-password')->assertStatus(429)->assertJsonPath('alasan', 'terkunci');

        $this->assertSame(0, PermintaanResetPassword::query()->count());
        $this->assertCount(0, $this->messagesTo(7000000001));
    }

    public function test_batas_permintaan_per_hari(): void
    {
        $admin = $this->adminPenuh();

        for ($i = 0; $i < 3; $i++) {
            $this->minta($this->siti);
            $id = PermintaanResetPassword::query()->where('status', 'menunggu')->sole()->id;
            $this->kirim($admin, "/api/v1/pengguna/permintaan-reset/{$id}/tolak")->assertOk();
        }

        $this->kirim($this->praLogin($this->siti), '/api/v1/auth/lupa-password')->assertStatus(429)->assertJsonPath('alasan', 'terlalu_sering');
    }

    public function test_akun_tanpa_password_diarahkan_ke_aktivasi(): void
    {
        $baru = User::factory()->role(Role::StafGudang)->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7022220000]);

        $this->kirim($this->praLogin($baru), '/api/v1/auth/lupa-password')->assertStatus(409)->assertJsonPath('alasan', 'perlu_aktivasi');
    }
}

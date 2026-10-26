<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\SesiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AlurLogin;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R-03 daftar sesi, keluar dari semua perangkat, pencabutan otomatis (peran/SPPG/status/password),
 * serta reset password & cabut sesi oleh Admin.
 */
class SesiAdminTest extends TestCase
{
    use AlurLogin, CreatesSppg, RefreshDatabase;

    private const PASSWORD = 'Rahasia123x';

    private int $sppgA;

    private User $siti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        $this->sppgA = $this->createSppg('SPPG Sukamaju');
        $this->siti = User::factory()->role(Role::StafGudang)->denganKredensial('siti.gudang', self::PASSWORD)
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7012345678]);
    }

    private function adminDenganSesi(): array
    {
        $admin = User::factory()->admin()->denganKredensial('admin.pusat', 'AdminRahasia9')->create(['telegram_id' => 7000000001]);

        return [$admin, $this->loginPenuh($admin, 'admin.pusat', 'AdminRahasia9', self::LAPTOP)];
    }

    public function test_daftar_sesi_menandai_sesi_ini_tanpa_data_rahasia(): void
    {
        $hp = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::TABLET);

        $res = $this->kirim($hp, '/api/v1/auth/sesi', method: 'GET')->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(1, collect($res->json('data'))->where('sesi_ini', true)->count());
        $this->assertStringNotContainsString(SesiService::hashPerangkat(self::HP), $res->getContent());
        $this->assertStringNotContainsString(self::HP, $res->getContent());
        $this->assertSame(['id', 'perangkat', 'dibuat_pada', 'terakhir_dipakai_pada', 'diverifikasi_pada', 'sesi_ini'], array_keys($res->json('data.0')));
    }

    public function test_keluar_dari_semua_perangkat(): void
    {
        $hp = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $tablet = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::TABLET);

        $this->kirim($hp, '/api/v1/auth/keluar-semua')->assertOk()->assertJsonPath('jumlah_sesi', 2);

        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($tablet, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->assertSame(0, $this->siti->perangkatTepercaya()->aktif()->count());
        $this->assertFalse(app(SesiService::class)->punyaSesiPenuh($this->siti));
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::KELUAR_SEMUA)->count());

        // Perangkat lama wajib token lagi.
        $pra = $this->praLogin($this->siti, self::HP);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.gudang', 'password' => self::PASSWORD, 'device_id' => self::HP])->assertStatus(202);
    }

    public function test_sesi_tercabut_saat_peran_sppg_status_atau_password_berubah(): void
    {
        $perubahan = [
            'role' => fn (User $u) => $u->update(['role' => Role::Akuntan]),
            'sppg' => fn (User $u) => $u->update(['sppg_id' => $this->createSppg('SPPG Baru '.uniqid())]),
            'nonaktif' => fn (User $u) => $u->update(['is_active' => false]),
            'password' => fn (User $u) => $u->forceFill(['password' => Hash::make('LainSekali9z')])->save(),
        ];

        foreach ($perubahan as $nama => $ubah) {
            $user = User::factory()->role(Role::StafGudang)->denganKredensial('user.'.$nama, self::PASSWORD)
                ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7100000000 + crc32($nama) % 1000000]);
            $token = $this->loginPenuh($user, 'user.'.$nama, self::PASSWORD);

            // Diubah dari luar sesi pengguna itu (mis. Admin/console).
            $this->lupakanGuard();
            $ubah($user->fresh());

            $this->kirim($token, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
            $this->assertSame(0, $user->tokens()->count(), $nama);
            $this->assertSame(0, $user->perangkatTepercaya()->aktif()->count(), $nama);
        }
    }

    public function test_perubahan_nama_tidak_mencabut_sesi(): void
    {
        $token = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD);
        $this->siti->fresh()->update(['name' => 'Siti Baru']);

        $this->kirim($token, '/api/v1/auth/me', method: 'GET')->assertOk();
    }

    // ---------------------------------------------------------------- Admin

    public function test_admin_reset_password_tanpa_melihat_password_dan_mencabut_sesi(): void
    {
        $tokenSiti = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD);
        [, $admin] = $this->adminDenganSesi();

        $res = $this->kirim($admin, "/api/v1/pengguna/{$this->siti->id}/reset-password")->assertOk();
        $this->assertStringNotContainsString('password_hash', $res->getContent());
        $this->assertArrayNotHasKey('password', $res->json());

        $this->kirim($tokenSiti, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->assertNull($this->siti->fresh()->password);

        // Pengguna wajib atur password baru (aktivasi + token bot) saat membuka Mini App.
        $this->bukaMiniApp($this->siti)->assertOk()->assertJsonPath('langkah', 'aktivasi')->assertJsonPath('akun.perlu_username', false);

        $log = AuditLog::query()->where('event', AuditAuth::PASSWORD_DIRESET_ADMIN)->sole();
        $this->assertSame($this->siti->id, $log->auditable_id);
        $this->assertNotSame($this->siti->id, $log->user_id);

        // Detail pengguna untuk Admin: penanda saja, tidak pernah hash/password.
        $this->kirim($admin, "/api/v1/pengguna/{$this->siti->id}", method: 'GET')
            ->assertOk()->assertJsonPath('data.password_diatur', false)->assertJsonPath('data.username', 'siti.gudang')
            ->assertJsonMissingPath('data.password');
    }

    public function test_setelah_reset_admin_pengguna_mengatur_password_sendiri(): void
    {
        [, $admin] = $this->adminDenganSesi();
        $this->kirim($admin, "/api/v1/pengguna/{$this->siti->id}/reset-password")->assertOk();

        $pra = $this->praLogin($this->siti);
        $id = $this->kirim($pra, '/api/v1/auth/aktivasi', [
            'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z', 'device_id' => self::HP,
        ])->assertStatus(202)->json('tantangan.id');

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir(7012345678), 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('user.username', 'siti.gudang');
        $this->assertTrue(Hash::check('BaruSekali9z', $this->siti->fresh()->password));
    }

    public function test_admin_cabut_semua_sesi_pengguna(): void
    {
        $hp = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $tablet = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::TABLET);
        [, $admin] = $this->adminDenganSesi();

        $this->kirim($admin, "/api/v1/pengguna/{$this->siti->id}/sesi", method: 'DELETE')
            ->assertOk()->assertJsonPath('jumlah_sesi', 2);

        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($tablet, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($admin, '/api/v1/auth/me', method: 'GET')->assertOk();
        // Password tidak berubah.
        $this->assertTrue(Hash::check(self::PASSWORD, $this->siti->fresh()->password));
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::SESI_DICABUT_ADMIN)->count());
    }

    public function test_non_admin_403_dan_lintas_sppg_403(): void
    {
        $kepalaA = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->sppgA]);
        $kepalaB = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->createSppg('SPPG Lain')]);

        // Kepala SPPG sama & pengguna itu sendiri: 403. Kepala SPPG lain: pengguna tak terlihat (404).
        foreach ([[$kepalaA, 403], [$this->siti, 403], [$kepalaB, 404]] as [$aktor, $status]) {
            $this->lupakanGuard();
            $this->masukPenuh($aktor);
            $this->postJson("/api/v1/pengguna/{$this->siti->id}/reset-password")->assertStatus($status);
            $this->deleteJson("/api/v1/pengguna/{$this->siti->id}/sesi")->assertStatus($status);
        }

        $this->assertNotNull($this->siti->fresh()->password);
    }

    public function test_admin_tidak_dapat_reset_password_sendiri_lewat_admin(): void
    {
        [$adminUser, $admin] = $this->adminDenganSesi();

        $this->kirim($admin, "/api/v1/pengguna/{$adminUser->id}/reset-password")->assertUnprocessable();
        $this->assertNotNull($adminUser->fresh()->password);
    }

    public function test_pengguna_tidak_ditemukan_404(): void
    {
        [, $admin] = $this->adminDenganSesi();

        $this->kirim($admin, '/api/v1/pengguna/999999/reset-password')->assertNotFound();
    }

    // ---------------------------------------------------------------- ubah password (S-3)

    public function test_ubah_password_mempertahankan_sesi_ini_dan_mencabut_sesi_lain(): void
    {
        $hp = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $tablet = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::TABLET);

        $this->kirim($hp, '/api/v1/auth/password', [
            'password_lama' => self::PASSWORD, 'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z',
        ], 'PUT')->assertOk()->assertJsonPath('jumlah_sesi_dicabut', 1);

        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->kirim($tablet, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->assertTrue(Hash::check('BaruSekali9z', $this->siti->fresh()->password));
        $sesi = app(SesiService::class);
        $this->assertNotNull($sesi->perangkatAktif($this->siti, SesiService::hashPerangkat(self::HP)));
        $this->assertNull($sesi->perangkatAktif($this->siti, SesiService::hashPerangkat(self::TABLET)));

        $log = AuditLog::query()->where('event', AuditAuth::PASSWORD_DIUBAH)->sole();
        $this->assertStringNotContainsString('BaruSekali9z', $log->toJson());
        $this->assertStringNotContainsString(self::PASSWORD, AuditLog::query()->get()->toJson());
    }

    public function test_ubah_password_lama_salah_ikut_penguncian_dan_aturan_password(): void
    {
        $hp = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $salah = ['password_lama' => 'SalahSekali1', 'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z'];

        $this->kirim($hp, '/api/v1/auth/password', ['password_lama' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], 'PUT')
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->kirim($hp, '/api/v1/auth/password', ['password_lama' => self::PASSWORD, 'password' => 'Password123', 'password_confirmation' => 'Password123'], 'PUT')
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        for ($sisa = 4; $sisa >= 1; $sisa--) {
            $this->kirim($hp, '/api/v1/auth/password', $salah, 'PUT')
                ->assertUnprocessable()->assertJsonPath('errors.password_lama.0', 'Password lama salah.')->assertJsonPath('sisa_percobaan', $sisa);
        }

        $this->kirim($hp, '/api/v1/auth/password', $salah, 'PUT')->assertStatus(429)->assertJsonPath('alasan', 'terkunci');
        // Password lama yang benar pun ditolak selama terkunci; sesi ini sendiri tetap berlaku.
        $this->kirim($hp, '/api/v1/auth/password', [...$salah, 'password_lama' => self::PASSWORD], 'PUT')->assertStatus(429);
        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->assertTrue(Hash::check(self::PASSWORD, $this->siti->fresh()->password));
    }

    public function test_ubah_password_butuh_login_penuh(): void
    {
        $pra = $this->praLogin($this->siti);

        $this->kirim($pra, '/api/v1/auth/password', ['password_lama' => self::PASSWORD, 'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z'], 'PUT')
            ->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
    }

    // ---------------------------------------------------------------- cabut satu sesi (S-3)

    public function test_cabut_satu_sesi_milik_sendiri(): void
    {
        $hp = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $tablet = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::TABLET);
        $idTablet = collect($this->kirim($hp, '/api/v1/auth/sesi', method: 'GET')->json('data'))->firstWhere('sesi_ini', false)['id'];

        $this->kirim($hp, "/api/v1/auth/sesi/{$idTablet}", method: 'DELETE')->assertOk()->assertJsonPath('sesi_ini', false);

        $this->kirim($tablet, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->assertNull(app(SesiService::class)->perangkatAktif($this->siti, SesiService::hashPerangkat(self::TABLET)));
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::SESI_DICABUT)->count());
    }

    public function test_cabut_sesi_milik_akun_lain_404_tanpa_efek(): void
    {
        $hpSiti = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $budi = User::factory()->role(Role::StafGudang)->denganKredensial('budi.gudang', self::PASSWORD)
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7012340000]);
        $hpBudi = $this->loginPenuh($budi, 'budi.gudang', self::PASSWORD, self::HP);
        $idBudi = $this->kirim($hpBudi, '/api/v1/auth/sesi', method: 'GET')->json('data.0.id');
        $praSiti = $budi->tokens()->count();

        // IDOR: id sesi Budi dari akun Siti -> 404, id tak ada -> 404.
        $this->kirim($hpSiti, "/api/v1/auth/sesi/{$idBudi}", method: 'DELETE')->assertNotFound();
        $this->kirim($hpSiti, '/api/v1/auth/sesi/999999', method: 'DELETE')->assertNotFound();

        $this->kirim($hpBudi, '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->assertSame($praSiti, $budi->tokens()->count());
    }

    // ---------------------------------------------------------------- sesi pengguna untuk Admin (S-3)

    public function test_admin_melihat_dan_mencabut_satu_sesi_pengguna(): void
    {
        $hp = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $tablet = $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::TABLET);
        [$adminUser, $admin] = $this->adminDenganSesi();

        $daftar = $this->kirim($admin, "/api/v1/pengguna/{$this->siti->id}/sesi", method: 'GET')->assertOk()->assertJsonCount(2, 'data');
        $this->assertFalse(collect($daftar->json('data'))->contains('sesi_ini', true));
        $this->assertStringNotContainsString(SesiService::hashPerangkat(self::HP), $daftar->getContent());

        $idHp = $this->kirim($hp, '/api/v1/auth/sesi', method: 'GET')->json('data.0.id');
        $this->kirim($admin, "/api/v1/pengguna/{$this->siti->id}/sesi/{$idHp}", method: 'DELETE')->assertOk();

        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($tablet, '/api/v1/auth/me', method: 'GET')->assertOk();
        $log = AuditLog::query()->where('event', AuditAuth::SESI_DICABUT_ADMIN)->sole();
        $this->assertSame($adminUser->id, $log->user_id);
        $this->assertSame($this->siti->id, $log->auditable_id);

        // Sesi milik pengguna lain lewat path pengguna ini: 404 (cegah IDOR).
        $idAdmin = collect($this->kirim($admin, '/api/v1/auth/sesi', method: 'GET')->json('data'))->firstWhere('sesi_ini', true)['id'];
        $this->kirim($admin, "/api/v1/pengguna/{$this->siti->id}/sesi/{$idAdmin}", method: 'DELETE')->assertNotFound();
        $this->kirim($admin, '/api/v1/auth/me', method: 'GET')->assertOk();
    }

    public function test_sesi_pengguna_hanya_untuk_admin_dalam_scope(): void
    {
        // Dibuat sebelum login: setelah request, guard tes masih memegang Siti dan BelongsToSppg
        // akan memaksa sppg_id pengguna baru ke SPPG Siti.
        $kepalaA = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->sppgA]);
        $kepalaB = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->createSppg('SPPG Lain')]);
        $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD, self::HP);
        $id = $this->siti->tokens()->where('name', 'like', 'sesi:%')->value('id');
        $sitiLain = $this->beriSesiPenuh($this->siti, 'perangkat-lain-siti-zzzzzzzzzzzz');

        // Token sungguhan (bukan Sanctum::actingAs) agar sama dengan jalur produksi.
        foreach ([[$this->beriSesiPenuh($kepalaA), 403], [$sitiLain, 403], [$this->beriSesiPenuh($kepalaB), 404]] as [$token, $status]) {
            $this->kirim($token, "/api/v1/pengguna/{$this->siti->id}/sesi", method: 'GET')->assertStatus($status);
            $this->kirim($token, "/api/v1/pengguna/{$this->siti->id}/sesi/{$id}", method: 'DELETE')->assertStatus($status);
        }

        $this->assertSame(2, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
    }

    public function test_pemeriksaan_sesi_untuk_bot(): void
    {
        $sesi = app(SesiService::class);
        $this->assertFalse($sesi->punyaSesiPenuh($this->siti));

        // Token pra-login saja bukan sesi penuh.
        $this->praLogin($this->siti);
        $this->assertFalse($sesi->punyaSesiPenuh($this->siti));

        $this->loginPenuh($this->siti, 'siti.gudang', self::PASSWORD);
        $this->assertTrue($sesi->punyaSesiPenuh($this->siti));

        User::withoutGlobalScopes()->whereKey($this->siti->id)->update(['is_active' => false]);
        $this->assertFalse($sesi->punyaSesiPenuh($this->siti->fresh()));
    }
}

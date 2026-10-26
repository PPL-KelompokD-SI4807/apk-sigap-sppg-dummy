<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\TantanganToken;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\TokenSekaliPakaiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AlurLogin;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R-03 aktivasi (undangan / sigap:buat-admin / setelah reset Admin), tidak ada reset password
 * mandiri (lupa password = hubungi Admin / sigap:reset-password-admin), aturan kekuatan password.
 */
class AktivasiResetTest extends TestCase
{
    use AlurLogin, CreatesSppg, RefreshDatabase;

    private const PASSWORD = 'Rahasia123x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
    }

    private function akunBaru(array $atribut = []): User
    {
        return User::factory()->role(Role::AhliGizi)->create(array_merge([
            'sppg_id' => $this->createSppg(), 'telegram_id' => 7011112222, 'phone' => null,
        ], $atribut));
    }

    /** @param array<string, mixed> $body */
    private function aktivasi(string $pra, array $body): TestResponse
    {
        return $this->kirim($pra, '/api/v1/auth/aktivasi', $body + [
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'device_id' => self::HP,
        ]);
    }

    // ---------------------------------------------------------------- aktivasi

    public function test_aktivasi_akun_undangan_username_telepon_password_dan_token(): void
    {
        $user = $this->akunBaru();
        $pra = $this->bukaMiniApp($user)->assertOk()->assertJsonPath('langkah', 'aktivasi')->json('token');

        // Belum aktivasi: masuk ditolak 409.
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'apa.saja', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(409)->assertJsonPath('alasan', 'perlu_aktivasi')->assertJsonPath('langkah', 'aktivasi');

        $id = $this->aktivasi($pra, ['username' => '@Rina.Gizi', 'phone' => '0857 1234 5678'])
            ->assertStatus(202)->assertJsonPath('tantangan.tujuan', 'aktivasi')->json('tantangan.id');

        // Belum berlaku sebelum token dikonfirmasi.
        $this->assertNull($user->fresh()->password);
        $this->assertNull($user->fresh()->username);

        $pesan = $this->messagesTo(7011112222)->last()['text'];
        $this->assertStringContainsString('aktivasi akun', $pesan);
        $this->assertStringNotContainsString(self::PASSWORD, $pesan);
        $this->assertStringNotContainsString('5678', $pesan);

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir(7011112222), 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('tujuan', 'aktivasi')->assertJsonPath('user.username', 'rina.gizi');

        $user->refresh();
        $this->assertSame('rina.gizi', $user->username);
        $this->assertSame('+6285712345678', $user->phone);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::AKTIVASI_BERHASIL)->count());

        // Audit perubahan User tidak memuat password maupun no telepon.
        $this->assertStringNotContainsString('+6285712345678', AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString($user->password, AuditLog::query()->get()->toJson());

        // Sesudahnya dapat masuk dengan username maupun no telepon.
        $this->loginPenuh($user, 'rina.gizi', self::PASSWORD, self::TABLET);
        $this->loginPenuh($user, '+6285712345678', self::PASSWORD, self::LAPTOP);
    }

    public function test_admin_dari_sigap_buat_admin_tanpa_password_wajib_atur_password_saat_login_pertama(): void
    {
        $this->artisan('sigap:buat-admin', ['telegram_id' => '7000000001', 'nama' => 'Admin Fiktif'])->assertSuccessful();
        $admin = User::query()->where('telegram_id', 7000000001)->sole();
        $this->assertNull($admin->password);

        $pra = $this->bukaMiniApp($admin)->assertOk()->assertJsonPath('langkah', 'aktivasi')->assertJsonPath('akun.perlu_username', true)->json('token');

        // Tanpa password tidak ada jalan pintas ke login penuh.
        $this->kirim($pra, '/api/v1/pengguna', method: 'GET')->assertForbidden();
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'admin', 'password' => '', 'device_id' => self::HP])->assertUnprocessable();

        $id = $this->aktivasi($pra, ['username' => 'admin.fiktif'])->assertStatus(202)->json('tantangan.id');
        $penuh = $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir(7000000001), 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('user.role', 'admin')->json('token');

        $this->kirim($penuh, '/api/v1/pengguna', method: 'GET')->assertOk();
    }

    public function test_aktivasi_username_wajib_sesuai_format(): void
    {
        $pra = $this->praLogin($this->akunBaru());

        $this->aktivasi($pra, [])->assertUnprocessable()->assertJsonValidationErrors(['username']);
        $this->aktivasi($pra, ['username' => '0812345678'])->assertUnprocessable()->assertJsonValidationErrors(['username']);
        $this->aktivasi($pra, ['username' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors(['username']);
        $this->assertCount(0, $this->telegramCalls('sendMessage'));
    }

    public function test_username_dipakai_pesan_seragam_dan_memakai_jatah_tantangan(): void
    {
        User::factory()->denganKredensial('rina.gizi')->create(['sppg_id' => $this->createSppg('SPPG Lain'), 'telegram_id' => 7099998888]);
        $pra = $this->praLogin($this->akunBaru());

        // Pesan tidak menyebut "sudah terdaftar/dipakai akun lain".
        $this->aktivasi($pra, ['username' => 'Rina.Gizi'])->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'Username tidak tersedia. Pilih username lain.');
        $this->assertCount(0, $this->telegramCalls('sendMessage'));

        // Setiap pemeriksaan memakai jatah tantangan (5 per 30 menit): tidak bisa dipakai menebak massal.
        for ($i = 0; $i < 4; $i++) {
            $this->aktivasi($pra, ['username' => 'rina.gizi'])->assertUnprocessable();
        }
        $this->aktivasi($pra, ['username' => 'nama.bebas'])->assertStatus(429)->assertJsonPath('alasan', 'terlalu_sering');
    }

    public function test_telepon_milik_akun_lain_tidak_ditolak_dan_tidak_disimpan(): void
    {
        $lain = User::factory()->create(['sppg_id' => $this->createSppg('SPPG Lain'), 'telegram_id' => 7099998888, 'phone' => '081299990000']);
        $user = $this->akunBaru();
        $pra = $this->praLogin($user);

        // Respons sama persis dengan nomor yang belum dipakai: tidak ada oracle no telepon.
        $id = $this->aktivasi($pra, ['username' => 'rina.gizi', 'phone' => '+62 812 9999 0000'])->assertStatus(202)->json('tantangan.id');
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir(7011112222), 'device_id' => self::HP])
            ->assertOk()->assertJsonMissingPath('user.phone');

        $this->assertNull($user->fresh()->phone);
        $this->assertSame('+6281299990000', $lain->fresh()->phone);
        $log = AuditLog::query()->where('event', AuditAuth::TELEPON_TIDAK_DISIMPAN)->sole();
        $this->assertStringNotContainsString('9999', json_encode($log->new_values));
    }

    public function test_telepon_tidak_dikenali_ditolak_format(): void
    {
        $pra = $this->praLogin($this->akunBaru());

        $this->aktivasi($pra, ['username' => 'rina.gizi', 'phone' => '1234 5678'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    }

    public function test_aktivasi_akun_yang_sudah_berpassword_ditolak_409(): void
    {
        $user = $this->akunBaru(['username' => 'rina.gizi', 'password' => Hash::make(self::PASSWORD)]);
        $pra = $this->praLogin($user);

        $this->aktivasi($pra, [])->assertStatus(409)->assertJsonPath('alasan', 'sudah_aktivasi');
    }

    public function test_akun_tanpa_telegram_tidak_bisa_menerima_token(): void
    {
        // Lewat HTTP mustahil (initData selalu membawa telegram_id); penjaga lapis service tetap diuji.
        $user = $this->akunBaru(['telegram_id' => null]);

        try {
            app(TokenSekaliPakaiService::class)->buat($user, $user->createToken('pra-login:uji', ['auth:pra-login'])->accessToken, TantanganToken::TUJUAN_AKTIVASI, str_repeat('a', 64));
            $this->fail('Seharusnya ditolak.');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('telegram_belum_terhubung', $e->getResponse()->getData(true)['alasan']);
        }

        $this->assertCount(0, $this->telegramCalls('sendMessage'));
    }

    /** @return array<string, array{string, string}> */
    public static function passwordLemah(): array
    {
        return [
            'kurang dari 8' => ['Ab1defg', 'password'],
            'tanpa huruf besar' => ['rahasia123x', 'password'],
            'tanpa huruf kecil' => ['RAHASIA123X', 'password'],
            'tanpa angka' => ['RahasiaSekali', 'password'],
            'sama dengan username' => ['Rina.Gizi1', 'password'],
            'sandi umum' => ['Password123', 'password'],
            'sandi umum + angka/simbol' => ['Sigapsppg2026!', 'password'],
            'nama bulan' => ['Januari2026', 'password'],
        ];
    }

    #[DataProvider('passwordLemah')]
    public function test_password_lemah_ditolak(string $password, string $field): void
    {
        $pra = $this->praLogin($this->akunBaru());

        $this->kirim($pra, '/api/v1/auth/aktivasi', [
            'username' => 'rina.gizi1', 'password' => $password, 'password_confirmation' => $password, 'device_id' => self::HP,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_konfirmasi_password_wajib_sama(): void
    {
        $pra = $this->praLogin($this->akunBaru());

        $this->kirim($pra, '/api/v1/auth/aktivasi', [
            'username' => 'rina.gizi', 'password' => self::PASSWORD, 'password_confirmation' => 'Rahasia123y', 'device_id' => self::HP,
        ])->assertUnprocessable()->assertJsonPath('errors.password.0', 'Konfirmasi password tidak sama.');
    }

    // ------------------------------------------------- lupa password = hubungi Admin

    public function test_tidak_ada_reset_password_mandiri_termasuk_saat_akun_terkunci(): void
    {
        $user = $this->akunBaru(['username' => 'rina.gizi', 'password' => Hash::make(self::PASSWORD)]);
        User::withoutGlobalScopes()->whereKey($user->id)->update(['locked_until' => now()->addMinutes(15)]);
        $pra = $this->praLogin($user);

        $this->kirim($pra, '/api/v1/auth/reset-password', [
            'identitas' => 'rina.gizi', 'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z', 'device_id' => self::HP,
        ])->assertNotFound();
        // Aktivasi juga bukan jalan pintas untuk akun yang sudah berpassword.
        $this->aktivasi($pra, [])->assertStatus(409)->assertJsonPath('alasan', 'sudah_aktivasi');

        $this->assertCount(0, $this->telegramCalls('sendMessage'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_perintah_server_reset_password_admin_lalu_admin_atur_ulang_sendiri(): void
    {
        $admin = User::factory()->admin()->denganKredensial('admin.pusat', self::PASSWORD)->create(['telegram_id' => 7000000002]);
        $hp = $this->beriSesiPenuh($admin);
        User::withoutGlobalScopes()->whereKey($admin->id)->update(['locked_until' => now()->addMinutes(15), 'failed_login_attempts' => 3]);

        $this->artisan('sigap:reset-password-admin', ['telegram_id' => '7000000002'])
            ->expectsConfirmation('Reset password Admin "'.$admin->name.'" dan cabut semua sesinya?', 'yes')
            ->expectsOutput('Password Admin direset dan semua sesi dicabut.')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertNull($admin->password);
        $this->assertNull($admin->locked_until);
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(0, $admin->perangkatTepercaya()->aktif()->count());
        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $log = AuditLog::query()->where('event', AuditAuth::PASSWORD_DIRESET_ADMIN)->sole();
        $this->assertSame(['oleh' => 'perintah_server'], $log->new_values);

        $pra = $this->bukaMiniApp($admin)->assertOk()->assertJsonPath('langkah', 'aktivasi')->json('token');
        $id = $this->aktivasi($pra, ['password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z'])->assertStatus(202)->json('tantangan.id');
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir(7000000002), 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('user.username', 'admin.pusat');
        $this->assertTrue(Hash::check('BaruSekali9z', $admin->fresh()->password));
    }

    public function test_perintah_server_hanya_untuk_admin_aktif_dan_bisa_dibatalkan(): void
    {
        $bukanAdmin = $this->akunBaru(['username' => 'rina.gizi', 'password' => Hash::make(self::PASSWORD)]);
        $admin = User::factory()->admin()->denganKredensial('admin.pusat', self::PASSWORD)->create(['telegram_id' => 7000000003]);

        $this->artisan('sigap:reset-password-admin', ['telegram_id' => (string) $bukanAdmin->telegram_id, '--force' => true])
            ->expectsOutput('Admin aktif dengan telegram_id tersebut tidak ditemukan.')->assertFailed();
        $this->artisan('sigap:reset-password-admin', ['telegram_id' => 'abc'])->assertFailed();
        $this->artisan('sigap:reset-password-admin', ['telegram_id' => '7000000003'])
            ->expectsConfirmation('Reset password Admin "'.$admin->name.'" dan cabut semua sesinya?', 'no')
            ->assertFailed();

        $this->assertNotNull($bukanAdmin->fresh()->password);
        $this->assertNotNull($admin->fresh()->password);
    }
}

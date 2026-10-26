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
 * R-03 aktivasi (undangan / sigap:buat-admin / setelah reset Admin), lupa password via token bot,
 * aturan kekuatan password, dan pencabutan sesi saat password berubah.
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
        $this->kirim($pra, '/api/v1/auth/reset-password', [
            'identitas' => 'admin.fiktif', 'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z', 'device_id' => self::HP,
        ])->assertStatus(409)->assertJsonPath('alasan', 'perlu_aktivasi');

        $id = $this->aktivasi($pra, ['username' => 'admin.fiktif'])->assertStatus(202)->json('tantangan.id');
        $penuh = $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir(7000000001), 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('user.role', 'admin')->json('token');

        $this->kirim($penuh, '/api/v1/pengguna', method: 'GET')->assertOk();
    }

    public function test_aktivasi_username_wajib_unik_dan_sesuai_format(): void
    {
        User::factory()->denganKredensial('rina.gizi')->create(['sppg_id' => $this->createSppg('SPPG Lain'), 'telegram_id' => 7099998888]);
        $pra = $this->praLogin($this->akunBaru());

        $this->aktivasi($pra, [])->assertUnprocessable()->assertJsonValidationErrors(['username']);
        $this->aktivasi($pra, ['username' => 'Rina.Gizi'])->assertUnprocessable()->assertJsonPath('errors.username.0', 'Username sudah dipakai. Pilih username lain.');
        $this->aktivasi($pra, ['username' => '0812345678'])->assertUnprocessable()->assertJsonValidationErrors(['username']);
        $this->aktivasi($pra, ['username' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors(['username']);
        $this->assertCount(0, $this->telegramCalls('sendMessage'));
    }

    public function test_aktivasi_telepon_milik_akun_lain_ditolak(): void
    {
        User::factory()->create(['sppg_id' => $this->createSppg('SPPG Lain'), 'telegram_id' => 7099998888, 'phone' => '081299990000']);
        $pra = $this->praLogin($this->akunBaru());

        $this->aktivasi($pra, ['username' => 'rina.gizi', 'phone' => '+62 812 9999 0000'])
            ->assertUnprocessable()->assertJsonPath('errors.phone.0', 'No telepon sudah dipakai akun lain.');
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
            app(TokenSekaliPakaiService::class)->buat($user, TantanganToken::TUJUAN_AKTIVASI, str_repeat('a', 64));
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

    // ---------------------------------------------------------------- lupa password (token bot)

    public function test_reset_password_via_token_bot_mencabut_semua_sesi_lama(): void
    {
        $user = $this->akunBaru(['username' => 'rina.gizi', 'password' => Hash::make(self::PASSWORD), 'phone' => '085711112222']);
        $hp = $this->loginPenuh($user, 'rina.gizi', self::PASSWORD, self::HP);
        $tablet = $this->loginPenuh($user, 'rina.gizi', self::PASSWORD, self::TABLET);

        $pra = $this->praLogin($user, self::LAPTOP);
        $id = $this->kirim($pra, '/api/v1/auth/reset-password', [
            'identitas' => '085711112222', 'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z', 'device_id' => self::LAPTOP,
        ])->assertStatus(202)->assertJsonPath('tantangan.tujuan', 'reset_password')->json('tantangan.id');

        // Password lama masih berlaku sampai token dikonfirmasi.
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertOk();

        $baru = $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir(7011112222), 'device_id' => self::LAPTOP])
            ->assertOk()->assertJsonPath('tujuan', 'reset_password')->json('token');

        $this->kirim($hp, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($tablet, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($baru, '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->assertSame(1, $user->perangkatTepercaya()->aktif()->count());

        $this->assertTrue(Hash::check('BaruSekali9z', $user->fresh()->password));
        $pra = $this->praLogin($user, self::HP);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'rina.gizi', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'kredensial_salah');
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::RESET_PASSWORD_BERHASIL)->count());
    }

    public function test_reset_password_tunduk_aturan_password(): void
    {
        $user = $this->akunBaru(['username' => 'rina.gizi', 'password' => Hash::make(self::PASSWORD)]);
        $pra = $this->praLogin($user);

        $this->kirim($pra, '/api/v1/auth/reset-password', [
            'identitas' => 'rina.gizi', 'password' => 'rina.gizi', 'password_confirmation' => 'rina.gizi', 'device_id' => self::HP,
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertCount(0, $this->telegramCalls('sendMessage'));
    }
}

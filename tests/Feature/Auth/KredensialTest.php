<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PerangkatTepercaya;
use App\Models\TantanganToken;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\SesiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\AlurLogin;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R-03 login dua lapis: initData -> pra-login -> username/no telepon + password (+ token bot).
 */
class KredensialTest extends TestCase
{
    use AlurLogin, CreatesSppg, RefreshDatabase;

    private const PASSWORD = 'Rahasia123x';

    private User $siti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();

        $this->siti = User::factory()->role(Role::KepalaSppg)->denganKredensial('siti.dapur', self::PASSWORD)->create([
            'sppg_id' => $this->createSppg('SPPG Sukamaju'),
            'telegram_id' => 7012345678,
            'phone' => '0812-3456-7890',
        ]);
    }

    // ---------------------------------------------------------------- masuk

    public function test_masuk_username_perangkat_baru_wajib_token_bot_lalu_login_penuh(): void
    {
        $pra = $this->praLogin($this->siti);

        $tantangan = $this->kirim($pra, '/api/v1/auth/masuk', [
            'identitas' => 'Siti.Dapur', 'password' => self::PASSWORD, 'device_id' => self::HP, 'label_perangkat' => 'Android · Telegram',
        ])->assertStatus(202)
            ->assertJsonPath('langkah', 'verifikasi_token')
            ->assertJsonPath('tantangan.tujuan', 'perangkat_baru')
            ->assertJsonPath('tantangan.maks_percobaan', 5)
            ->assertJsonMissingPath('token')
            ->json('tantangan');

        // Token dikirim HANYA ke telegram_id akun; tanpa password/no telepon.
        $pesan = $this->messagesTo(7012345678)->last();
        $this->assertStringNotContainsString(self::PASSWORD, $pesan['text']);
        $this->assertStringNotContainsString('7890', $pesan['text']);
        $this->assertCount(1, $this->telegramCalls('sendMessage'));

        $kode = $this->kodeTerakhir(7012345678);
        $this->assertNotSame($kode, TantanganToken::query()->find($tantangan['id'])->kode_hash);

        $res = $this->kirim($pra, '/api/v1/auth/token/verifikasi', [
            'tantangan_id' => $tantangan['id'], 'kode' => $kode, 'device_id' => self::HP,
        ])->assertOk()
            ->assertJsonPath('langkah', 'selesai')
            ->assertJsonPath('token_jenis', 'penuh')
            ->assertJsonPath('expires_at', null)
            ->assertJsonPath('user.id', $this->siti->id)
            ->assertJsonPath('user.username', 'siti.dapur')
            ->assertJsonPath('user.permissions.jadwal', 'CRUD');

        $penuh = PersonalAccessToken::findToken($res->json('token'));
        $this->assertContains(SesiService::ABILITY_PENUH, $penuh->abilities);
        $this->assertContains('role:kepala_sppg', $penuh->abilities);
        $this->assertNull($penuh->expires_at);
        $this->assertNull(PersonalAccessToken::findToken($pra), 'Token pra-login habis dipakai.');

        $perangkat = PerangkatTepercaya::query()->sole();
        $this->assertSame('Android · Telegram', $perangkat->label);
        $this->assertNotSame(self::HP, $perangkat->device_hash);
        $this->assertSame(SesiService::hashPerangkat(self::HP), $perangkat->device_hash);

        $this->kirim($res->json('token'), '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->assertNotNull($this->siti->fresh()->last_login_at);
        $this->assertTrue(app(SesiService::class)->punyaSesiPenuh($this->siti->fresh()));
    }

    public function test_masuk_dengan_no_telepon_format_apa_pun(): void
    {
        foreach (['081234567890', '+62 812-3456-7890', '6281234567890'] as $i => $telepon) {
            $device = 'perangkat-telepon-'.$i.'-xxxxxxxxxxxxxx';
            $token = $this->loginPenuh($this->siti, $telepon, self::PASSWORD, $device);
            $this->kirim($token, '/api/v1/auth/me', method: 'GET')->assertOk();
        }
    }

    public function test_perangkat_tepercaya_dengan_sesi_aktif_tidak_perlu_token_lagi(): void
    {
        $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);
        $terkirim = $this->telegramCalls('sendMessage')->count();

        $pra = $this->praLogin($this->siti);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('langkah', 'selesai');

        $this->assertSame($terkirim, $this->telegramCalls('sendMessage')->count());
        // Satu sesi per perangkat: sesi lama perangkat ini diganti.
        $this->assertSame(1, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
    }

    public function test_setelah_logout_perangkat_wajib_token_lagi(): void
    {
        $token = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);
        $this->kirim($token, '/api/v1/auth/logout')->assertOk();
        $this->kirim($token, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();

        $pra = $this->praLogin($this->siti);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(202);
    }

    public function test_password_salah_422_tanpa_membocorkan_dan_tercatat_audit(): void
    {
        $pra = $this->praLogin($this->siti);

        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => 'SalahSekali1', 'device_id' => self::HP])
            ->assertUnprocessable()
            ->assertJsonPath('alasan', 'kredensial_salah')
            ->assertJsonPath('sisa_percobaan', 4)
            ->assertJsonPath('errors.identitas.0', 'Username/no telepon atau password salah.');

        $this->assertCount(0, $this->telegramCalls('sendMessage'));
        $log = AuditLog::query()->where('event', AuditAuth::MASUK_GAGAL)->sole();
        $this->assertSame($this->siti->id, $log->auditable_id);
        $this->assertStringNotContainsString('SalahSekali1', json_encode($log->new_values));
    }

    // ------------------------------------------------- identitas akun Telegram lain

    public function test_identitas_milik_akun_telegram_lain_ditolak(): void
    {
        $budi = User::factory()->role(Role::Akuntan)->denganKredensial('budi.akuntan', 'BudiRahasia9')->create([
            'sppg_id' => $this->siti->sppg_id, 'telegram_id' => 7099990000, 'phone' => '0813-1111-2222',
        ]);

        // Pemegang initData Siti mencoba masuk sebagai Budi dengan password Budi yang BENAR.
        $pra = $this->praLogin($this->siti);

        foreach (['budi.akuntan', '081311112222'] as $identitas) {
            $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => $identitas, 'password' => 'BudiRahasia9', 'device_id' => self::HP])
                ->assertUnprocessable()->assertJsonPath('alasan', 'kredensial_salah');
        }

        $this->assertCount(0, $this->messagesTo(7099990000));
        $this->assertCount(0, $this->telegramCalls('sendMessage'));
        $this->assertSame(0, $budi->tokens()->count());
        $this->assertSame(0, (int) $budi->fresh()->failed_login_attempts, 'Akun Budi tidak terdampak percobaan dari akun lain.');
        $this->assertTrue(Hash::check('BudiRahasia9', $budi->fresh()->password));

        $logs = AuditLog::query()->where('event', AuditAuth::IDENTITAS_TIDAK_COCOK)->get();
        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn ($l) => $l->auditable_id === $this->siti->id && $l->new_values['milik_akun_lain'] === true));
        $this->assertStringNotContainsString('0813', $logs->toJson());
    }

    // ---------------------------------------------------------------- penguncian

    public function test_salah_berulang_mengunci_sementara_lalu_terbuka_lagi(): void
    {
        $pra = $this->praLogin($this->siti);
        $salah = ['identitas' => 'siti.dapur', 'password' => 'SalahSekali1', 'device_id' => self::HP];

        for ($i = 4; $i >= 1; $i--) {
            $this->kirim($pra, '/api/v1/auth/masuk', $salah)->assertUnprocessable()->assertJsonPath('sisa_percobaan', $i);
        }

        $this->kirim($pra, '/api/v1/auth/masuk', $salah)
            ->assertStatus(429)->assertJsonPath('alasan', 'terkunci')->assertHeader('Retry-After');

        // Password benar pun ditolak selama terkunci.
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(429)->assertJsonPath('alasan', 'terkunci');

        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::AKUN_TERKUNCI)->count());

        $this->travel(16)->minutes();
        $pra = $this->praLogin($this->siti);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(202);
    }

    public function test_penguncian_atomik_slot_habis_oleh_request_paralel_menolak_password_benar(): void
    {
        $pra = $this->praLogin($this->siti);
        $benar = ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP];

        // Keadaan saat 5 request paralel sudah memesan slot dan belum selesai (belum terkunci).
        User::withoutGlobalScopes()->whereKey($this->siti->id)->update(['failed_login_attempts' => 5, 'locked_until' => null]);
        $this->kirim($pra, '/api/v1/auth/masuk', $benar)->assertStatus(429)->assertJsonPath('alasan', 'terkunci');
        $this->assertCount(0, $this->telegramCalls('sendMessage'), 'Password tidak sempat diuji, tidak ada token bot.');
        $this->assertSame(5, (int) $this->siti->fresh()->failed_login_attempts);

        // Satu slot tersisa: password benar memakai slot itu lalu penghitung dinolkan.
        User::withoutGlobalScopes()->whereKey($this->siti->id)->update(['failed_login_attempts' => 4]);
        $this->kirim($pra, '/api/v1/auth/masuk', $benar)->assertStatus(202);
        $this->assertSame(0, (int) $this->siti->fresh()->failed_login_attempts);
    }

    public function test_penguncian_kedaluwarsa_membuka_siklus_baru_dan_penghitung_bertahan_selama_terkunci(): void
    {
        $pra = $this->praLogin($this->siti);
        $salah = ['identitas' => 'siti.dapur', 'password' => 'SalahSekali1', 'device_id' => self::HP];

        for ($i = 0; $i < 5; $i++) {
            $this->kirim($pra, '/api/v1/auth/masuk', $salah);
        }

        $this->assertNotNull($this->siti->fresh()->locked_until);
        $this->assertSame(5, (int) $this->siti->fresh()->failed_login_attempts);

        $this->travel(16)->minutes();
        $pra = $this->praLogin($this->siti);
        $this->kirim($pra, '/api/v1/auth/masuk', $salah)->assertUnprocessable()->assertJsonPath('sisa_percobaan', 4);
        $this->assertNull($this->siti->fresh()->locked_until);
    }

    // ---------------------------------------------------------------- token 6 digit

    /** @return array{0: string, 1: string} [token pra-login, id tantangan] */
    private function mulaiMasuk(string $device = self::HP): array
    {
        $pra = $this->praLogin($this->siti, $device);
        $id = $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => $device])
            ->assertStatus(202)->json('tantangan.id');

        return [$pra, $id];
    }

    public function test_token_kedaluwarsa_ditolak(): void
    {
        [$pra, $id] = $this->mulaiMasuk();
        $kode = $this->kodeTerakhir(7012345678);

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_kedaluwarsa')->assertJsonValidationErrors(['kode']);
        $this->assertSame(0, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
    }

    public function test_token_dipakai_ulang_ditolak(): void
    {
        [$pra, $id] = $this->mulaiMasuk();
        $kode = $this->kodeTerakhir(7012345678);

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])->assertOk();

        // Token pra-login lama sudah habis setelah dipakai; kode yang sama tidak bisa dipakai lagi.
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])->assertUnauthorized();
        $this->assertNotNull(TantanganToken::query()->find($id)->dipakai_pada);
        $praBaru = $this->praLogin($this->siti);
        $this->kirim($praBaru, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');

        $this->assertSame(1, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
        $this->assertNull(TantanganToken::query()->find($id)->payload, 'Payload dikosongkan setelah dipakai.');
    }

    public function test_token_ditebak_berulang_hangus_walau_kode_benar_sesudahnya(): void
    {
        [$pra, $id] = $this->mulaiMasuk();
        $kode = $this->kodeTerakhir(7012345678);
        $salah = $kode === '000000' ? '111111' : '000000';

        for ($sisa = 4; $sisa >= 1; $sisa--) {
            $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $salah, 'device_id' => self::HP])
                ->assertUnprocessable()->assertJsonPath('alasan', 'token_salah')->assertJsonPath('sisa_percobaan', $sisa);
        }

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $salah, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_hangus');

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_hangus');

        // Kirim ulang juga tidak menghidupkan tantangan yang hangus.
        $this->kirim($pra, '/api/v1/auth/token/kirim-ulang', ['tantangan_id' => $id, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');

        $this->assertSame(0, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
        $this->assertSame(4, AuditLog::query()->where('event', AuditAuth::TOKEN_SALAH)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::TOKEN_HANGUS)->count());
        $this->assertStringNotContainsString($kode, AuditLog::query()->get()->toJson());
    }

    public function test_tantangan_terikat_perangkat_dan_pengguna(): void
    {
        [$pra, $id] = $this->mulaiMasuk();
        $kode = $this->kodeTerakhir(7012345678);

        // device_id lain (pengguna sama): diperlakukan tidak ada.
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::TABLET])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');

        // Pengguna lain dengan kode & perangkat yang benar tetap ditolak.
        $lain = User::factory()->denganKredensial('andi.gudang')->create(['sppg_id' => $this->siti->sppg_id, 'telegram_id' => 7055550000]);
        $praLain = $this->praLogin($lain);
        $this->kirim($praLain, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');

        $this->assertSame(0, PersonalAccessToken::query()->where('name', 'like', 'sesi:%')->count());

        // Pemilik sah dengan token pra-login yang meminta tantangan tetap bisa.
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])->assertOk();
    }

    public function test_tantangan_terikat_token_pra_login_yang_memintanya(): void
    {
        [$pra, $id] = $this->mulaiMasuk();
        $kode = $this->kodeTerakhir(7012345678);

        // Token pra-login LAIN milik akun yang sama (dari perangkat lain) + device_id & kode benar: ditolak.
        $praLain = $this->praLogin($this->siti, self::TABLET);
        $this->kirim($praLain, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');
        $this->travel(61)->seconds();
        $this->kirim($praLain, '/api/v1/auth/token/kirim-ulang', ['tantangan_id' => $id, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');
        $this->assertSame(0, TantanganToken::query()->find($id)->percobaan, 'Percobaan dari token lain tidak menyentuh tantangan.');

        // Mini App dibuka ulang di perangkat yang sama = token pra-login baru: wajib ulangi dari awal.
        $praUlang = $this->praLogin($this->siti);
        $this->kirim($praUlang, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kode, 'device_id' => self::HP])
            ->assertUnprocessable()->assertJsonPath('alasan', 'token_tidak_valid');
        $this->assertSame(0, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
        $this->assertNotSame($pra, $praUlang);
        $this->assertSame((int) explode('|', $pra)[0], TantanganToken::query()->find($id)->personal_access_token_id);
    }

    public function test_kirim_ulang_dibatasi_jeda_dan_kode_lama_tidak_berlaku(): void
    {
        [$pra, $id] = $this->mulaiMasuk();
        $kodeLama = $this->kodeTerakhir(7012345678);

        $this->kirim($pra, '/api/v1/auth/token/kirim-ulang', ['tantangan_id' => $id, 'device_id' => self::HP])
            ->assertStatus(429)->assertJsonPath('alasan', 'terlalu_sering')->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        $this->kirim($pra, '/api/v1/auth/token/kirim-ulang', ['tantangan_id' => $id, 'device_id' => self::HP])
            ->assertStatus(202)->assertJsonPath('tantangan.id', $id)->assertJsonPath('tantangan.sisa_kirim_ulang', 2);

        $kodeBaru = $this->kodeTerakhir(7012345678);

        if ($kodeBaru !== $kodeLama) {
            $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kodeLama, 'device_id' => self::HP])
                ->assertUnprocessable()->assertJsonPath('alasan', 'token_salah');
        }

        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kodeBaru, 'device_id' => self::HP])->assertOk();
    }

    public function test_kirim_ulang_tidak_mereset_hitungan_percobaan(): void
    {
        [$pra, $id] = $this->mulaiMasuk();
        $salahLama = $this->kodeTerakhir(7012345678) === '000000' ? '111111' : '000000';

        for ($sisa = 4; $sisa >= 2; $sisa--) {
            $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $salahLama, 'device_id' => self::HP])
                ->assertJsonPath('sisa_percobaan', $sisa);
        }

        $this->travel(61)->seconds();
        $this->kirim($pra, '/api/v1/auth/token/kirim-ulang', ['tantangan_id' => $id, 'device_id' => self::HP])->assertStatus(202);
        $kodeBaru = $this->kodeTerakhir(7012345678);
        $salah = $kodeBaru === '000000' ? '111111' : '000000';

        // Hitungan berlanjut dari 3 salah: satu salah lagi -> sisa 1, berikutnya hangus.
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $salah, 'device_id' => self::HP])
            ->assertJsonPath('alasan', 'token_salah')->assertJsonPath('sisa_percobaan', 1);
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $salah, 'device_id' => self::HP])
            ->assertJsonPath('alasan', 'token_hangus');
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $kodeBaru, 'device_id' => self::HP])
            ->assertJsonPath('alasan', 'token_hangus');
        $this->assertSame(5, TantanganToken::query()->find($id)->percobaan);
    }

    public function test_batas_kirim_ulang(): void
    {
        [$pra, $id] = $this->mulaiMasuk();

        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            $this->kirim($pra, '/api/v1/auth/token/kirim-ulang', ['tantangan_id' => $id, 'device_id' => self::HP])->assertStatus(202);
        }

        $this->travel(61)->seconds();
        $this->kirim($pra, '/api/v1/auth/token/kirim-ulang', ['tantangan_id' => $id, 'device_id' => self::HP])
            ->assertStatus(429)->assertJsonPath('alasan', 'terlalu_sering');
    }

    public function test_permintaan_tantangan_baru_dibatasi_per_pengguna(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->mulaiMasuk();
        }

        $pra = $this->praLogin($this->siti);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(429)->assertJsonPath('alasan', 'terlalu_sering');
    }

    public function test_bot_gagal_mengirim_503_dan_tantangan_hangus(): void
    {
        $this->fakeTelegram(['api.telegram.org/*/sendMessage' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $pra = $this->praLogin($this->siti);

        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(503)->assertJsonPath('alasan', 'gagal_kirim');

        $this->assertSame(0, TantanganToken::query()->terbuka()->count());
    }

    // ---------------------------------------------------------------- ability

    public function test_token_pra_login_ditolak_403_di_semua_rute_data(): void
    {
        $pra = $this->praLogin($this->siti);

        foreach (['/api/v1/auth/me', '/api/v1/sppg', '/api/v1/pengguna', '/api/v1/auth/sesi'] as $url) {
            $this->kirim($pra, $url, method: 'GET')->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
        }

        $this->kirim($pra, '/api/v1/auth/keluar-semua')->assertForbidden();
    }

    public function test_token_tanpa_ability_penuh_atau_wildcard_ditolak_403(): void
    {
        $lama = $this->siti->createToken('miniapp:miniapp', ['role:kepala_sppg', 'sppg:R'])->plainTextToken;
        $wildcard = $this->siti->createToken('wildcard')->plainTextToken;

        $this->kirim($lama, '/api/v1/sppg', method: 'GET')->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
        $this->kirim($wildcard, '/api/v1/auth/me', method: 'GET')->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
    }

    public function test_token_login_penuh_tidak_bisa_memakai_rute_pra_login(): void
    {
        $penuh = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);

        $this->kirim($penuh, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertForbidden()->assertJsonPath('alasan', 'bukan_pra_login');
    }

    public function test_tanpa_token_401(): void
    {
        $this->postJson('/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])->assertUnauthorized();
    }

    public function test_validasi_masuk_422(): void
    {
        $pra = $this->praLogin($this->siti);

        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => '', 'device_id' => 'pendek'])
            ->assertUnprocessable()->assertJsonValidationErrors(['identitas', 'password', 'device_id']);
    }

    public function test_throttle_rute_pra_login(): void
    {
        $pra = $this->praLogin($this->siti);

        for ($i = 0; $i < 10; $i++) {
            $this->kirim($pra, '/api/v1/auth/masuk', [])->assertUnprocessable();
        }

        $this->kirim($pra, '/api/v1/auth/masuk', [])->assertStatus(429);
    }

    // ---------------------------------------------------------------- sesi aktif saat Mini App dibuka

    public function test_mini_app_dibuka_ulang_dengan_sesi_aktif_tidak_perlu_login(): void
    {
        $penuh = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);

        $this->bukaMiniApp($this->siti, self::HP, $penuh)
            ->assertOk()->assertJsonPath('langkah', 'sesi_aktif')->assertJsonPath('user.id', $this->siti->id)->assertJsonMissingPath('token');

        // Perangkat lain dengan token yang sama (mis. disalin): tidak dianggap sesi aktif.
        $this->bukaMiniApp($this->siti, self::TABLET, $penuh)->assertOk()->assertJsonPath('langkah', 'masuk');
    }

    public function test_sesi_milik_akun_telegram_lain_dicabut(): void
    {
        $penuhSiti = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);
        $tabletSiti = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD, self::TABLET);
        $budi = User::factory()->denganKredensial('budi.akuntan')->create(['sppg_id' => $this->siti->sppg_id, 'telegram_id' => 7099990000]);

        // initData Budi + token Siti -> bukan sesi aktif; Budi tetap harus masuk sendiri.
        $this->bukaMiniApp($budi, self::HP, $penuhSiti)->assertOk()->assertJsonPath('langkah', 'masuk');

        // Token Siti di HP itu dicabut beserta kepercayaan perangkatnya; sesi Siti di tablet tetap.
        $this->kirim($penuhSiti, '/api/v1/auth/me', method: 'GET')->assertUnauthorized();
        $this->kirim($tabletSiti, '/api/v1/auth/me', method: 'GET')->assertOk();
        $this->assertNull(app(SesiService::class)->perangkatAktif($this->siti, SesiService::hashPerangkat(self::HP)));
        $this->assertSame(0, $budi->tokens()->where('name', 'like', 'sesi:%')->count());

        $log = AuditLog::query()->where('event', AuditAuth::SESI_DICABUT_AKUN_BERBEDA)->sole();
        $this->assertSame($this->siti->id, $log->auditable_id);
        $this->assertStringNotContainsString($penuhSiti, AuditLog::query()->get()->toJson());
    }

    public function test_token_pemilik_sendiri_dan_token_pra_login_akun_lain_tidak_dicabut_di_auth_telegram(): void
    {
        $penuh = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);
        $budi = User::factory()->denganKredensial('budi.akuntan')->create(['sppg_id' => $this->siti->sppg_id, 'telegram_id' => 7099990000]);
        $praBudi = $this->praLogin($budi);

        // Pemilik sendiri dari perangkat lain: bukan sesi aktif, tetapi token tidak dicabut.
        $this->bukaMiniApp($this->siti, self::TABLET, $penuh)->assertOk()->assertJsonPath('langkah', 'masuk');
        $this->kirim($penuh, '/api/v1/auth/me', method: 'GET')->assertOk();

        // Hanya token LOGIN PENUH akun lain yang dicabut; token pra-login dibiarkan kedaluwarsa sendiri.
        $this->bukaMiniApp($this->siti, self::HP, $praBudi)->assertOk();
        $this->assertSame(1, $budi->tokens()->where('name', 'like', 'pra-login:%')->count());
        $this->assertSame(0, AuditLog::query()->where('event', AuditAuth::SESI_DICABUT_AKUN_BERBEDA)->count());
    }
}

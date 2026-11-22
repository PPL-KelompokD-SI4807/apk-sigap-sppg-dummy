<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PerangkatTepercaya;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\PerangkatTidakTepercayaException;
use App\Services\Auth\SesiService;
use App\Services\Users\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\AlurLogin;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * Sesi login penuh bergeser (R-03): 90 hari (Admin 30) sejak terakhir dipakai, batas absolut 180 hari
 * sejak login, perpanjangan maks sekali per jam, maks 5 sesi per pengguna, pencabutan tetap seketika.
 */
class SesiBergeserTest extends TestCase
{
    use AlurLogin, CreatesSppg, RefreshDatabase;

    private const PASSWORD = 'Rahasia123x';

    private User $siti;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        // Pagi hari tetap: tidak ada batas tengah malam yang tak sengaja terlewati.
        $this->travelTo(Carbon::parse('2026-11-09 08:00:00'));

        $this->siti = User::factory()->role(Role::KepalaSppg)->denganKredensial('siti.dapur', self::PASSWORD)->create([
            'sppg_id' => $this->createSppg('SPPG Sukamaju'),
            'telegram_id' => 7012345678,
        ]);
        $this->admin = User::factory()->admin()->denganKredensial('admin.pusat', self::PASSWORD)->create([
            'telegram_id' => 7012345679,
        ]);
    }

    private function me(string $token): TestResponse
    {
        return $this->kirim($token, '/api/v1/auth/me', method: 'GET');
    }

    private function model(string $token): ?PersonalAccessToken
    {
        return PersonalAccessToken::findToken($token);
    }

    private function sesi(User $user, string $device = self::HP): string
    {
        return $this->beriSesiPenuh($user, $device);
    }

    private function hari(int $n): void
    {
        $this->travel($n)->days();
    }

    // ---------------------------------------------------------------- masa per peran

    public function test_masa_sesi_90_hari_untuk_peran_biasa_dan_30_hari_untuk_admin(): void
    {
        $siti = $this->sesi($this->siti);
        $admin = $this->sesi($this->admin);

        $this->assertTrue($this->model($siti)->expires_at->equalTo(now()->addDays(90)));
        $this->assertTrue($this->model($admin)->expires_at->equalTo(now()->addDays(30)));

        $this->hari(31);
        $this->me($admin)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
        $this->me($siti)->assertOk();

        $this->hari(60);
        $this->me($siti)->assertOk(); // hari ke-91, tetapi dipakai di hari ke-31 -> berlaku s.d. hari ke-121

        $this->hari(91);
        $this->me($siti)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
    }

    public function test_respons_login_penuh_dan_auth_me_memuat_expires_at_dan_batas_absolut(): void
    {
        $token = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);
        $login = now();

        $this->me($token)->assertOk()
            ->assertJsonPath('expires_at', $login->copy()->addDays(90)->toIso8601String())
            ->assertJsonPath('batas_absolut_at', $login->copy()->addDays(180)->toIso8601String());
    }

    // ---------------------------------------------------------------- bergeser + batas absolut

    public function test_sesi_bergeser_saat_dipakai_tetapi_tidak_melewati_batas_absolut_180_hari(): void
    {
        $token = $this->sesi($this->siti);
        $login = now()->copy();

        $this->hari(60);
        $this->me($token)->assertOk();
        $this->assertTrue($this->model($token)->expires_at->equalTo($login->copy()->addDays(150)));

        $this->hari(60);
        $this->me($token)->assertOk();
        // min(hari 120 + 90, login + 180)
        $this->assertTrue($this->model($token)->expires_at->equalTo($login->copy()->addDays(180)));

        $this->hari(59);
        $this->me($token)->assertOk()->assertJsonPath('expires_at', $login->copy()->addDays(180)->toIso8601String());

        $this->hari(2);
        $this->me($token)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
    }

    public function test_admin_dipakai_rutin_tetap_berakhir_di_batas_absolut(): void
    {
        $token = $this->sesi($this->admin);

        for ($i = 0; $i < 9; $i++) {
            $this->hari(20);
            $this->me($token)->assertOk();
        }

        // Hari ke-180: masa bergeser Admin (30 hari) masih tersisa, batas absolut tidak.
        $this->assertTrue($this->model($token)->expires_at->lessThanOrEqualTo($this->model($token)->created_at->copy()->addDays(180)));
        $this->travel(1)->minutes();
        $this->me($token)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
    }

    public function test_env_tidak_dapat_melonggarkan_masa_admin_batas_absolut_atau_jumlah_sesi(): void
    {
        $sesi = app(SesiService::class);

        // Nilai lebih longgar dari batas keras diabaikan.
        config(['kredensial.sesi' => ['masa_hari' => 5000, 'masa_hari_admin' => 365, 'batas_absolut_hari' => 9999, 'maks_per_pengguna' => 50]]);
        $this->assertSame(180, $sesi->batasAbsolutHari());
        $this->assertSame(90, $sesi->masaHari($this->siti));
        $this->assertSame(30, $sesi->masaHari($this->admin));
        $this->assertSame(5, $sesi->maksSesi());

        $token = $this->sesi($this->admin);
        $this->assertTrue($this->model($token)->expires_at->equalTo(now()->addDays(30)));
        $this->hari(31);
        $this->me($token)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');

        // Mengetatkan berlaku; Admin tidak pernah lebih panjang dari peran biasa; masa <= absolut.
        config(['kredensial.sesi' => ['masa_hari' => 20, 'masa_hari_admin' => 30, 'batas_absolut_hari' => 60, 'maks_per_pengguna' => 2]]);
        $this->assertSame(60, $sesi->batasAbsolutHari());
        $this->assertSame(20, $sesi->masaHari($this->siti));
        $this->assertSame(20, $sesi->masaHari($this->admin));
        $this->assertSame(2, $sesi->maksSesi());

        config(['kredensial.sesi' => ['masa_hari' => 90, 'masa_hari_admin' => 30, 'batas_absolut_hari' => 10, 'maks_per_pengguna' => 5]]);
        $this->assertSame(10, $sesi->masaHari($this->siti));
        $this->assertSame(10, $sesi->masaHari($this->admin));

        // Nol/negatif -> 1 hari (fail closed); kosong -> batas keras.
        config(['kredensial.sesi' => ['masa_hari' => 0, 'masa_hari_admin' => -5, 'batas_absolut_hari' => null, 'maks_per_pengguna' => 0]]);
        $this->assertSame(1, $sesi->masaHari($this->siti));
        $this->assertSame(1, $sesi->masaHari($this->admin));
        $this->assertSame(180, $sesi->batasAbsolutHari());
        $this->assertSame(1, $sesi->maksSesi());
    }

    // ---------------------------------------------------------------- jeda perpanjangan

    public function test_perpanjangan_ditulis_maksimal_sekali_per_jam_per_token(): void
    {
        $token = $this->sesi($this->siti);
        $this->me($token)->assertOk();
        $pertama = $this->model($token);
        $this->assertTrue($pertama->last_used_at->equalTo(now()));

        $update = 0;
        DB::listen(function ($q) use (&$update): void {
            if (self::updateToken($q->sql)) {
                $update++;
            }
        });

        // 30 permintaan dalam 59 menit: tidak ada penulisan token sama sekali.
        for ($i = 0; $i < 30; $i++) {
            $this->travel(2)->minutes();
            if (now()->diffInMinutes($pertama->last_used_at, true) >= 60) {
                break;
            }
            $this->me($token)->assertOk();
        }
        $this->assertSame(0, $update);
        $this->assertTrue($this->model($token)->expires_at->equalTo($pertama->expires_at));

        $this->travelTo($pertama->last_used_at->copy()->addMinutes(61));
        $this->me($token)->assertOk();
        $this->me($token)->assertOk();
        $this->assertSame(1, $update, 'Satu UPDATE setelah jeda 1 jam, permintaan berikutnya tidak menulis.');
        $this->assertTrue($this->model($token)->expires_at->equalTo(now()->addDays(90)));
    }

    public function test_token_lama_tanpa_expires_at_yang_dipakai_dalam_satu_jam_tetap_mendapat_expires_at(): void
    {
        $baru = $this->siti->createToken('sesi:lama', [SesiService::ABILITY_PENUH, 'role:kepala_sppg']);
        $baru->accessToken->forceFill([
            'created_at' => now()->subDays(3),
            'last_used_at' => now()->subMinutes(10),
            'expires_at' => null,
        ])->save();
        $token = $baru->plainTextToken;

        $update = 0;
        DB::listen(function ($q) use (&$update): void {
            if (self::updateToken($q->sql)) {
                $update++;
            }
        });

        // last_used_at < 1 jam lalu, tetapi expires_at kosong: cabang orWhereNull('expires_at') menulis sekali.
        $this->me($token)->assertOk()->assertJsonPath('expires_at', now()->addDays(90)->toIso8601String());
        $this->assertSame(1, $update);
        $model = $this->model($token);
        $this->assertTrue($model->expires_at->equalTo(now()->addDays(90)));
        $this->assertTrue($model->last_used_at->equalTo(now()));

        // Setelah expires_at terisi, jeda 1 jam berlaku normal.
        $this->travel(5)->minutes();
        $this->me($token)->assertOk();
        $this->assertSame(1, $update);
    }

    public function test_audit_perpanjangan_hanya_yang_pertama_per_hari(): void
    {
        $token = $this->sesi($this->siti);
        $hitung = fn () => AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DIPERPANJANG)->count();

        $this->me($token)->assertOk();
        $this->travel(2)->hours();
        $this->me($token)->assertOk();
        $this->assertSame(0, $hitung(), 'Hari login sendiri tidak dicatat sebagai perpanjangan.');

        $this->hari(1);
        $this->me($token)->assertOk();
        $this->travel(2)->hours();
        $this->me($token)->assertOk();
        $this->travel(2)->hours();
        $this->me($token)->assertOk();
        $this->assertSame(1, $hitung());

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DIPERPANJANG)->sole();
        $this->assertSame($this->siti->id, (int) $log->auditable_id);
        $this->assertSame((int) $this->model($token)->getKey(), $log->new_values['sesi_id']);
    }

    // ---------------------------------------------------------------- 401 kedaluwarsa

    public function test_token_kedaluwarsa_401_dengan_alasan_yang_dapat_dibedakan(): void
    {
        $token = $this->sesi($this->siti);
        $this->hari(91);

        $this->me($token)->assertUnauthorized()
            ->assertExactJson(['message' => 'Sesi Anda telah berakhir. Silakan masuk kembali.', 'alasan' => 'sesi_kedaluwarsa'])
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
            ->assertHeader('Cache-Control', 'no-store, private');

        // Rute data lain: bentuk sama.
        $this->kirim($token, '/api/v1/sppg', method: 'GET')->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');

        // Token tidak dikenal / dicabut: tetap 401 umum tanpa `alasan` (tidak membedakan token yang pernah ada).
        $this->kirim('999|bukan-token-sungguhan', '/api/v1/auth/me', method: 'GET')->assertUnauthorized()
            ->assertExactJson(['message' => 'Tidak terautentikasi. Silakan buka ulang Mini App.']);
    }

    public function test_token_pra_login_tidak_diperpanjang(): void
    {
        $pra = $this->praLogin($this->siti);
        $awal = $this->model($pra)->expires_at;

        $this->kirim($pra, '/api/v1/auth/lupa-password', method: 'GET')->assertOk();
        $this->assertTrue($this->model($pra)->expires_at->equalTo($awal));

        $this->travel(16)->minutes();
        $this->kirim($pra, '/api/v1/auth/lupa-password', method: 'GET')->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
    }

    // ---------------------------------------------------------------- pertukaran initData

    public function test_mini_app_dibuka_ulang_initdata_baru_dengan_sesi_berlaku_menggeser_masa_tanpa_token_baru(): void
    {
        $token = $this->sesi($this->siti, self::HP);
        $this->hari(40);

        $this->bukaMiniApp($this->siti, self::HP, $token)->assertOk()
            ->assertJsonPath('langkah', 'sesi_aktif')
            ->assertJsonPath('expires_at', now()->addDays(90)->toIso8601String())
            ->assertJsonMissingPath('token');
    }

    public function test_mini_app_dibuka_ulang_initdata_baru_dengan_sesi_kedaluwarsa_wajib_password_lagi(): void
    {
        $token = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD, self::HP);
        $this->hari(91);
        $this->me($token)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');

        // Login senyap: initData tidak pernah cukup untuk login penuh (lapis 2 tetap password).
        $pra = $this->bukaMiniApp($this->siti, self::HP, $token)->assertOk()
            ->assertJsonPath('langkah', 'masuk')
            ->assertJsonPath('token_jenis', 'pra_login')
            ->json('token');

        // Perangkat masih tepercaya (91 < 180 hari sejak token bot): password saja, tanpa token bot.
        $terkirim = $this->telegramCalls('sendMessage')->count();
        $baru = $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('langkah', 'selesai')->json('token');
        $this->assertSame($terkirim, $this->telegramCalls('sendMessage')->count());
        $this->me($baru)->assertOk();
        $this->assertNull($this->model($token), 'Token kedaluwarsa perangkat ini diganti.');
    }

    // ---------------------------------------------------------------- kepercayaan perangkat (180 hari)

    public function test_kepercayaan_perangkat_berakhir_180_hari_sejak_token_bot_lalu_wajib_token_bot_lagi(): void
    {
        $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD, self::HP);
        $verifikasi = now()->copy();

        // Hari 100: perangkat tepercaya, password saja. Kepercayaan TIDAK bergeser karena dipakai.
        $this->hari(100);
        $pra = $this->praLogin($this->siti);
        $kedua = $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertOk()->assertJsonPath('langkah', 'selesai')
            // min(hari 100 + 90, verifikasi + 180)
            ->assertJsonPath('expires_at', $verifikasi->copy()->addDays(180)->toIso8601String())
            ->json('token');
        $this->assertTrue($this->siti->perangkatTepercaya()->sole()->diverifikasi_pada->equalTo($verifikasi));

        // Hari 150: dipakai, tetapi expires_at tetap dijepit ke akhir kepercayaan perangkat.
        $this->hari(50);
        $this->me($kedua)->assertOk()->assertJsonPath('expires_at', $verifikasi->copy()->addDays(180)->toIso8601String());

        // Hari 181: sesi berakhir bersama kepercayaan perangkat; masuk berikutnya wajib token bot.
        $this->hari(31);
        $this->me($kedua)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
        $this->assertNull(app(SesiService::class)->perangkatAktif($this->siti, SesiService::hashPerangkat(self::HP)));

        $pra = $this->praLogin($this->siti);
        $tantangan = $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(202)->assertJsonPath('tantangan.tujuan', 'perangkat_baru')->json('tantangan.id');
        $ketiga = $this->kirim($pra, '/api/v1/auth/token/verifikasi', [
            'tantangan_id' => $tantangan, 'kode' => $this->kodeTerakhir((int) $this->siti->telegram_id), 'device_id' => self::HP,
        ])->assertOk()->assertJsonPath('expires_at', now()->addDays(90)->toIso8601String())->json('token');

        // Verifikasi ulang memulai masa kepercayaan baru pada baris perangkat yang sama.
        $perangkat = $this->siti->perangkatTepercaya()->sole();
        $this->assertTrue($perangkat->diverifikasi_pada->equalTo(now()));
        $this->assertNull($perangkat->dicabut_at);
        $this->me($ketiga)->assertOk();
    }

    public function test_perangkat_tepercaya_yang_sudah_ada_dinilai_dari_waktu_verifikasi_tanpa_pemutusan_mendadak(): void
    {
        $sesi = app(SesiService::class);
        $baru = $this->siti->perangkatTepercaya()->create(['device_hash' => SesiService::hashPerangkat(self::HP), 'diverifikasi_pada' => now()->subDays(20)]);
        $lama = $this->siti->perangkatTepercaya()->create(['device_hash' => SesiService::hashPerangkat(self::TABLET), 'diverifikasi_pada' => now()->subDays(181)]);

        $this->assertNotNull($sesi->perangkatAktif($this->siti, SesiService::hashPerangkat(self::HP)));
        $this->assertNull($sesi->perangkatAktif($this->siti, SesiService::hashPerangkat(self::TABLET)));

        // Perangkat 20 hari: tetap password saja. Perangkat 181 hari: wajib token bot.
        $pra = $this->praLogin($this->siti, self::HP);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])->assertOk();
        $pra = $this->praLogin($this->siti, self::TABLET);
        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::TABLET])->assertStatus(202);

        // Pembersihan harian: hanya yang lewat masa ditandai dicabut; baris tidak dihapus.
        $this->artisan('sigap:bersihkan-perangkat')->expectsOutputToContain('dicabut: 1')->assertSuccessful();
        $this->assertNotNull($lama->fresh()->dicabut_at);
        $this->assertNull($baru->fresh()->dicabut_at);
        $this->assertSame(2, $this->siti->perangkatTepercaya()->count());
    }

    public function test_perangkat_tepercaya_tidak_melebihi_batas_mencabut_yang_tanpa_sesi_dan_tertua_lebih_dulu(): void
    {
        $tokens = [];
        foreach (range(1, 5) as $i) {
            $tokens[$i] = $this->sesi($this->siti, "perangkat-{$i}-xxxxxxxxxxxxxxxxxxxxxx");
            $this->travel(1)->hours();
        }
        // Sesi perangkat 2-5 berakhir dan sudah dipangkas; kepercayaannya masih tersisa.
        $this->siti->tokens()->whereKey(collect([2, 3, 4, 5])->map(fn ($i) => $this->model($tokens[$i])->getKey())->all())->delete();

        $baru = $this->sesi($this->siti, 'perangkat-6-xxxxxxxxxxxxxxxxxxxxxx');

        $aktif = $this->siti->perangkatTepercaya()->aktif()->pluck('device_hash')->all();
        $this->assertCount(5, $aktif);
        $this->assertNotContains(SesiService::hashPerangkat('perangkat-2-xxxxxxxxxxxxxxxxxxxxxx'), $aktif, 'Tertua tanpa sesi dicabut.');
        $this->assertContains(SesiService::hashPerangkat('perangkat-1-xxxxxxxxxxxxxxxxxxxxxx'), $aktif, 'Perangkat bersesi dipertahankan walau paling tua.');
        $this->me($tokens[1])->assertOk();
        $this->me($baru)->assertOk();

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::PERANGKAT_DICABUT_BATAS)->sole();
        $this->assertSame(['jumlah_sesi' => 0, 'maks_perangkat' => 5], array_intersect_key($log->new_values, ['jumlah_sesi' => 1, 'maks_perangkat' => 1]));
    }

    public function test_masuk_tepercaya_yang_kepercayaannya_dicabut_di_tengah_proses_tidak_diperbarui_tanpa_token_bot(): void
    {
        $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD, self::HP);
        $perangkat = $this->siti->perangkatTepercaya()->sole();
        $pra = $this->praLogin($this->siti);
        $sesiSebelum = $this->siti->tokens()->where('name', 'like', 'sesi:%')->count();

        // Simulasi balapan (TOCTOU): kepercayaan dicabut tepat setelah pemeriksaan pertama perangkatAktif().
        $sekali = false;
        PerangkatTepercaya::retrieved(function () use (&$sekali, $perangkat): void {
            if (! $sekali) {
                $sekali = true;
                DB::table('perangkat_tepercayas')->where('id', $perangkat->id)->update(['dicabut_at' => now()]);
            }
        });

        $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(202)->assertJsonPath('tantangan.tujuan', 'perangkat_baru')->assertJsonMissingPath('token');

        $segar = $perangkat->fresh();
        $this->assertNotNull($segar->dicabut_at, 'Kepercayaan tidak dihidupkan lagi tanpa token bot.');
        $this->assertTrue($segar->diverifikasi_pada->equalTo($perangkat->diverifikasi_pada));
        $this->assertSame($sesiSebelum, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count(), 'Tidak ada sesi baru.');
        $this->assertNotNull(PersonalAccessToken::findToken($pra), 'Token pra-login tetap untuk melanjutkan token bot.');
    }

    public function test_jalur_tepercaya_menolak_perangkat_dicabut_kedaluwarsa_atau_tidak_ada(): void
    {
        $sesi = app(SesiService::class);
        $hp = SesiService::hashPerangkat(self::HP);
        $dicabut = $this->siti->perangkatTepercaya()->create(['device_hash' => $hp, 'diverifikasi_pada' => now()->subDays(10), 'dicabut_at' => now()->subDay()]);
        $kedaluwarsa = $this->siti->perangkatTepercaya()->create(['device_hash' => SesiService::hashPerangkat(self::TABLET), 'diverifikasi_pada' => now()->subDays(181)]);

        foreach ([$hp, SesiService::hashPerangkat(self::TABLET), SesiService::hashPerangkat(self::LAPTOP)] as $hash) {
            try {
                $sesi->terbitkanPenuh($this->siti, $hash, null, null, lewatTokenBot: false);
                $this->fail('Jalur tanpa token bot harus ditolak.');
            } catch (PerangkatTidakTepercayaException) {
            }
        }

        $this->assertNotNull($dicabut->fresh()->dicabut_at);
        $this->assertTrue($kedaluwarsa->fresh()->diverifikasi_pada->equalTo($kedaluwarsa->diverifikasi_pada));
        $this->assertSame(2, $this->siti->perangkatTepercaya()->count(), 'Perangkat tak dikenal tidak dibuat.');
        $this->assertSame(0, $this->siti->tokens()->count());
    }

    public function test_token_bot_pada_perangkat_yang_masih_tepercaya_selalu_memulai_masa_baru(): void
    {
        $this->sesi($this->siti, self::HP);
        $this->hari(50);

        app(SesiService::class)->terbitkanPenuh($this->siti, SesiService::hashPerangkat(self::HP), null, null, lewatTokenBot: true);

        $this->assertTrue($this->siti->perangkatTepercaya()->sole()->diverifikasi_pada->equalTo(now()));
    }

    public function test_sesi_pada_perangkat_tidak_tepercaya_tidak_dihitung_bot_daftar_maupun_token_lama(): void
    {
        $sesi = app(SesiService::class);

        // Token lama tanpa expires_at, baru dipakai, tetapi perangkatnya diverifikasi 181 hari lalu.
        $perangkat = $this->siti->perangkatTepercaya()->create(['device_hash' => SesiService::hashPerangkat(self::HP), 'diverifikasi_pada' => now()->subDays(181)]);
        $lama = $this->siti->createToken('sesi:'.$perangkat->id, [SesiService::ABILITY_PENUH, 'role:kepala_sppg']);
        $lama->accessToken->forceFill(['perangkat_tepercaya_id' => $perangkat->id, 'created_at' => now()->subDays(10), 'last_used_at' => now()->subDay(), 'expires_at' => null])->save();

        $this->assertFalse($sesi->punyaSesiPenuh($this->siti));
        $this->assertNull($sesi->tokenSesiPenuh($this->siti));
        $this->assertSame([], $sesi->daftarSesi($this->siti, null)->all());
        // Pemakaian PERTAMA token lama sudah ditolak (tidak menunggu expires_at terisi).
        $this->me($lama->plainTextToken)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');

        // Token ber-expires_at yang perangkatnya dicabut langsung di DB juga tidak dihitung.
        $token = $this->sesi($this->siti, self::TABLET);
        $this->assertTrue($sesi->punyaSesiPenuh($this->siti));
        DB::table('perangkat_tepercayas')->where('id', $this->model($token)->perangkat_tepercaya_id)->update(['dicabut_at' => now()]);
        $this->assertFalse($sesi->punyaSesiPenuh($this->siti));
        $this->assertSame([], $sesi->daftarSesi($this->siti, null)->all());
    }

    public function test_auth_me_batas_absolut_mengikuti_akhir_kepercayaan_perangkat_bila_lebih_awal(): void
    {
        $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD, self::HP);
        $verifikasi = now()->copy();
        $this->hari(100);
        $pra = $this->praLogin($this->siti);
        $token = $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertOk()->json('token');

        // Login hari 100 (+180 = hari 280), tetapi perangkat dipercaya sejak hari 0 (+180 = hari 180).
        $this->me($token)->assertOk()->assertJsonPath('batas_absolut_at', $verifikasi->copy()->addDays(180)->toIso8601String());
    }

    public function test_pembersihan_perangkat_mencabut_token_terikat_dan_idempoten(): void
    {
        $perangkat = $this->siti->perangkatTepercaya()->create(['device_hash' => SesiService::hashPerangkat(self::HP), 'diverifikasi_pada' => now()->subDays(181)]);
        $lama = $this->siti->createToken('sesi:'.$perangkat->id, [SesiService::ABILITY_PENUH]);
        $lama->accessToken->forceFill(['perangkat_tepercaya_id' => $perangkat->id])->save();
        $tetap = $this->sesi($this->siti, self::TABLET);

        $this->artisan('sigap:bersihkan-perangkat')->expectsOutputToContain('dicabut: 1')->assertSuccessful();
        $this->assertNull(PersonalAccessToken::find($lama->accessToken->getKey()));
        $this->assertNotNull($perangkat->fresh()->dicabut_at);
        $waktu = $perangkat->fresh()->dicabut_at;

        $this->travel(1)->days();
        $this->artisan('sigap:bersihkan-perangkat')->expectsOutputToContain('dicabut: 0')->assertSuccessful();
        $this->assertTrue($perangkat->fresh()->dicabut_at->equalTo($waktu), 'Jalan kedua tidak mengubah apa pun.');
        $this->me($tetap)->assertOk();
    }

    public function test_initdata_basi_tetap_ditolak_walau_membawa_token_sesi_berlaku(): void
    {
        config(['telegram.init_data_max_age' => 3600]);
        $token = $this->sesi($this->siti, self::HP);
        $this->hari(10);
        $sebelum = $this->model($token)->expires_at;

        $this->lupakanGuard();
        $this->withToken($token)->postJson('/api/v1/auth/telegram', [
            'init_data' => $this->makeInitData(['id' => (int) $this->siti->telegram_id], time() - 3700),
            'device_id' => self::HP,
        ])->assertUnauthorized()->assertJsonPath('message', 'initData kedaluwarsa. Tutup lalu buka ulang Mini App.');

        $this->assertTrue($this->model($token)->expires_at->equalTo($sebelum), 'initData basi tidak menggeser sesi.');
    }

    // ---------------------------------------------------------------- pencabutan seketika

    public function test_perubahan_peran_mencabut_semua_sesi_walau_masa_tersisa_dan_token_baru_membawa_hak_baru(): void
    {
        $hp = $this->sesi($this->siti, self::HP);
        $tablet = $this->sesi($this->siti, self::TABLET);
        $this->me($hp)->assertOk();

        $this->forgetGuardsSaja();
        app(UserAccountService::class)->update($this->admin, $this->siti, ['role' => Role::Akuntan->value]);

        $this->me($hp)->assertUnauthorized()->assertJsonMissingPath('alasan');
        $this->me($tablet)->assertUnauthorized();
        $this->assertSame(0, $this->siti->tokens()->count());

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT_OTOMATIS)->sole();
        $this->assertSame('hak_akses_berubah', $log->new_values['pemicu']);
        $this->assertSame(['role'], $log->new_values['kolom']);
        $this->assertSame(2, $log->new_values['jumlah_token']);

        $baru = $this->sesi($this->siti->fresh(), self::HP);
        $abilities = $this->model($baru)->abilities;
        $this->assertContains('role:akuntan', $abilities);
        $this->assertNotContains('role:kepala_sppg', $abilities);
    }

    public function test_dinonaktifkan_dan_dihapus_mencabut_semua_sesi(): void
    {
        $token = $this->sesi($this->siti);
        app(UserAccountService::class)->update($this->admin, $this->siti, ['is_active' => false]);
        $this->me($token)->assertUnauthorized();
        $this->assertSame(0, $this->siti->tokens()->count());

        $budi = User::factory()->role(Role::Akuntan)->create(['sppg_id' => $this->siti->sppg_id, 'telegram_id' => 7012000001]);
        $tokenBudi = $this->sesi($budi);
        app(UserAccountService::class)->delete($this->admin, $budi);
        $this->me($tokenBudi)->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $budi->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => AuditAuth::SESI_DICABUT_OTOMATIS, 'auditable_id' => $budi->id]);
    }

    public function test_logout_mencabut_seketika_walau_masa_tersisa(): void
    {
        $token = $this->loginPenuh($this->siti, 'siti.dapur', self::PASSWORD);
        $this->kirim($token, '/api/v1/auth/logout')->assertOk();
        $this->me($token)->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['event' => AuditAuth::LOGOUT, 'auditable_id' => $this->siti->id]);
    }

    public function test_pemulihan_akun_mencabut_token_yang_tersisa(): void
    {
        $this->siti->delete();
        // Sisa token (tidak wajar) pada baris terhapus, masa 90 hari masih utuh.
        $sisa = $this->siti->createToken('sesi:9', [SesiService::ABILITY_PENUH], now()->addDays(90));

        $this->siti->restore();

        $this->assertNull(PersonalAccessToken::find($sisa->accessToken->getKey()));
        $this->assertDatabaseHas('audit_logs', ['event' => AuditAuth::SESI_DICABUT_OTOMATIS, 'auditable_id' => $this->siti->id]);
        $this->me($sisa->plainTextToken)->assertUnauthorized();
    }

    // ---------------------------------------------------------------- batas jumlah sesi

    public function test_login_ke_enam_mencabut_sesi_yang_paling_lama_tidak_dipakai(): void
    {
        $tokens = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $p) {
            $tokens[$p] = $this->sesi($this->siti, "perangkat-{$p}-xxxxxxxxxxxxxxxxxxxxxx");
            $this->travel(2)->hours();
        }

        // Perangkat a dibuat paling awal tetapi baru dipakai: yang dicabut b (aktivitas tertua).
        $this->me($tokens['a'])->assertOk();
        $this->travel(1)->minutes();

        $baru = $this->sesi($this->siti, 'perangkat-f-xxxxxxxxxxxxxxxxxxxxxx');

        $this->assertSame(5, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
        $this->me($tokens['b'])->assertUnauthorized();
        $this->me($tokens['a'])->assertOk();
        $this->me($baru)->assertOk();
        // 6 perangkat dipercaya, kepercayaan perangkat b ikut dicabut (seperti logout).
        $this->assertSame(5, $this->siti->perangkatTepercaya()->aktif()->count());

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT_BATAS)->sole();
        $this->assertSame(5, $log->new_values['maks_sesi']);
    }

    public function test_login_berulang_tidak_pernah_melampaui_batas_dan_mencabut_yang_tertua_berurutan(): void
    {
        // Catatan: SQLite (tes) tidak mendukung SELECT ... FOR UPDATE, jadi balapan dua login paralel hanya
        // dapat dibuktikan di MySQL. Tes ini memastikan urutan dan idempotensi pada login beruntun.
        $tokens = [];
        foreach (range(1, 8) as $i) {
            $tokens[$i] = $this->sesi($this->siti, "perangkat-{$i}-xxxxxxxxxxxxxxxxxxxxxx");
            $this->assertLessThanOrEqual(5, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
            $this->travel(1)->minutes();
        }

        foreach ([1, 2, 3] as $i) {
            $this->assertNull($this->model($tokens[$i]), "Sesi {$i} (tertua) dicabut.");
        }
        foreach ([4, 5, 6, 7, 8] as $i) {
            $this->assertNotNull($this->model($tokens[$i]));
        }
        $this->assertSame(3, AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT_BATAS)->count());

        // Login ulang di perangkat yang sudah punya sesi: mengganti sesinya sendiri, tidak mencabut yang lain.
        $ulang = $this->sesi($this->siti, 'perangkat-4-xxxxxxxxxxxxxxxxxxxxxx');
        $this->assertNull($this->model($tokens[4]));
        $this->assertNotNull($this->model($ulang));
        $this->assertSame(5, $this->siti->tokens()->where('name', 'like', 'sesi:%')->count());
        $this->assertSame(3, AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT_BATAS)->count());
    }

    public function test_sesi_kedaluwarsa_tidak_dihitung_dalam_batas_dan_dibersihkan_saat_login(): void
    {
        config(['kredensial.sesi.maks_per_pengguna' => 2]);
        $lama = $this->sesi($this->siti, 'perangkat-lama-xxxxxxxxxxxxxxxxxxxx');
        $this->hari(91);
        $a = $this->sesi($this->siti, 'perangkat-a-xxxxxxxxxxxxxxxxxxxxxx');
        $b = $this->sesi($this->siti, 'perangkat-b-xxxxxxxxxxxxxxxxxxxxxx');

        $this->assertNull($this->model($lama));
        $this->me($a)->assertOk();
        $this->me($b)->assertOk();
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT_BATAS)->count());
    }

    // ---------------------------------------------------------------- token lama beredar

    public function test_token_lama_tanpa_expires_at_diberi_batas_dari_pemakaian_terakhir_tanpa_memutus_sesi_aktif(): void
    {
        $sesi = app(SesiService::class);
        $legacy = function (User $user, int $dibuatHariLalu, ?int $dipakaiHariLalu): string {
            $baru = $user->createToken('sesi:lama', [SesiService::ABILITY_PENUH, 'role:'.$user->role->value]);
            $baru->accessToken->forceFill([
                'created_at' => now()->subDays($dibuatHariLalu),
                'last_used_at' => $dipakaiHariLalu === null ? null : now()->subDays($dipakaiHariLalu),
                'expires_at' => null,
            ])->save();

            return $baru->plainTextToken;
        };

        // Dipakai 5 hari lalu: tetap masuk, expires_at kini ditetapkan (bergeser normal).
        $aktif = $legacy($this->siti, 12, 5);
        $this->me($aktif)->assertOk();
        $this->assertTrue($this->model($aktif)->expires_at->equalTo(now()->addDays(90)));

        // Admin tidak dipakai 40 hari (> 30): kedaluwarsa.
        $this->me($legacy($this->admin, 45, 40))->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');

        // Dibuat 200 hari lalu walau dipakai kemarin: batas absolut.
        $this->me($legacy($this->siti, 200, 1))->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');

        // Tidak pernah dipakai, dibuat 100 hari lalu: dihitung dari created_at.
        $tak = $legacy($this->siti, 100, null);
        $this->assertTrue($sesi->kedaluwarsaEfektif($this->model($tak), $this->siti)->isPast());
    }

    public function test_bot_tidak_menganggap_sesi_kedaluwarsa_sebagai_sesi_penuh(): void
    {
        $sesi = app(SesiService::class);
        $this->sesi($this->siti);
        $this->assertTrue($sesi->punyaSesiPenuh($this->siti));
        $this->assertNotNull($sesi->tokenSesiPenuh($this->siti));

        $this->hari(91);
        $this->assertFalse($sesi->punyaSesiPenuh($this->siti));
        $this->assertNull($sesi->tokenSesiPenuh($this->siti));
        $this->assertSame([], $sesi->daftarSesi($this->siti, null)->all());
    }

    // ---------------------------------------------------------------- audit tanpa rahasia

    public function test_audit_tidak_memuat_token_atau_initdata(): void
    {
        $dikirim = [];
        $tukar = function (?string $bearer) use (&$dikirim): TestResponse {
            $fields = $this->initDataFields(['id' => (int) $this->siti->telegram_id], extra: ['query_id' => 'AAQ'.bin2hex(random_bytes(8))]);
            $dikirim[] = $fields;
            $this->lupakanGuard();
            $req = $bearer === null ? $this : $this->withToken($bearer);

            return $req->postJson('/api/v1/auth/telegram', ['init_data' => $this->encodeInitData($fields), 'device_id' => self::HP]);
        };

        // Login penuh memakai initData yang dicatat di atas (bukan helper yang membuat initData sendiri).
        $pra = (string) $tukar(null)->assertOk()->assertJsonPath('langkah', 'masuk')->json('token');
        $tantangan = $this->kirim($pra, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::PASSWORD, 'device_id' => self::HP])
            ->assertStatus(202)->json('tantangan.id');
        $token = (string) $this->kirim($pra, '/api/v1/auth/token/verifikasi', [
            'tantangan_id' => $tantangan, 'kode' => $this->kodeTerakhir((int) $this->siti->telegram_id), 'device_id' => self::HP,
        ])->assertOk()->json('token');

        $this->hari(1);
        $this->me($token)->assertOk();
        $tukar($token)->assertOk()->assertJsonPath('langkah', 'sesi_aktif');
        config(['kredensial.sesi.maks_per_pengguna' => 1]);
        $tablet = $this->sesi($this->siti, self::TABLET);
        $this->kirim($tablet, '/api/v1/auth/logout')->assertOk();

        $events = AuditLog::query()->withoutGlobalScopes()->pluck('event')->all();
        $this->assertContains(AuditAuth::MASUK_BERHASIL, $events);
        $this->assertContains(AuditAuth::SESI_DIPERPANJANG, $events);
        $this->assertContains(AuditAuth::SESI_DICABUT_BATAS, $events);

        $semua = (string) json_encode(AuditLog::query()->withoutGlobalScopes()->get()->toArray());
        [, $rahasia] = explode('|', $token, 2);
        $this->assertStringNotContainsString($rahasia, $semua);
        $this->assertStringNotContainsString(hash('sha256', $rahasia), $semua);
        [, $rahasiaTablet] = explode('|', $tablet, 2);
        $this->assertStringNotContainsString($rahasiaTablet, $semua);
        $this->assertStringNotContainsString(self::HP, $semua);
        $this->assertStringNotContainsString(self::TABLET, $semua);

        $this->assertCount(2, $dikirim);
        foreach ($dikirim as $fields) {
            foreach (['hash', 'signature', 'query_id', 'auth_date'] as $kunci) {
                $this->assertStringNotContainsString($fields[$kunci], $semua, "initData.{$kunci} tidak boleh masuk audit.");
            }
            $this->assertStringNotContainsString($this->encodeInitData($fields), $semua);
        }
        // Isi objek user initData (profil Telegram) juga tidak disalin ke audit.
        $this->assertStringNotContainsString('allows_write_to_pm', $semua);
    }

    /** UPDATE pada tabel token, apa pun gaya kutip identifier dialek SQL-nya. */
    private static function updateToken(string $sql): bool
    {
        return preg_match('/^\s*update\b/i', $sql) === 1 && preg_match('/\bpersonal_access_tokens\b/i', $sql) === 1;
    }

    private function forgetGuardsSaja(): void
    {
        $this->app['auth']->forgetGuards();
    }
}

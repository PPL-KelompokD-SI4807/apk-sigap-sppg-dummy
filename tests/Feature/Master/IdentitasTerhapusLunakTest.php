<?php

namespace Tests\Feature\Master;

use App\Console\Commands\LepasIdentitasTerhapus;
use App\Enums\Role;
use App\Enums\TipeUndangan;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\TeleponUnik;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\KredensialService;
use App\Services\Users\InvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Tests\Support\AlurLogin;

/**
 * R04-13 (Q4-1..Q4-6): username/no telepon baris terhapus lunak tidak menghalangi akun baru, tetapi akun
 * aktif dan nonaktif-belum-dihapus tetap memegang identitasnya. Data fiktif.
 */
class IdentitasTerhapusLunakTest extends MasterApiTestCase
{
    use AlurLogin;

    private const PASSWORD = 'Rahasia123x';

    private const TELEPON = '081299990001';

    private const TELEPON_E164 = '+6281299990001';

    private User $lama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();

        $this->lama = User::factory()->role(Role::AhliGizi)->denganKredensial('siti.lama')
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7_400_000_001, 'phone' => self::TELEPON]);
    }

    private function hapusOlehAdmin(User $target): void
    {
        $this->as($this->admin)->deleteJson("/api/v1/pengguna/{$target->id}")->assertNoContent();
        $this->forgetActor();
    }

    private function akunBaru(int $telegramId = 7_400_000_002): User
    {
        return User::factory()->role(Role::AhliGizi)->create(['sppg_id' => $this->sppgA, 'telegram_id' => $telegramId, 'phone' => null]);
    }

    /** @return TestResponse */
    private function aktivasi(string $pra, array $body)
    {
        return $this->kirim($pra, '/api/v1/auth/aktivasi', $body + [
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'device_id' => self::HP,
        ]);
    }

    private function selesaikanAktivasi(User $baru, array $body): string
    {
        $pra = $this->praLogin($baru);
        $id = $this->aktivasi($pra, $body)->assertStatus(202)->json('tantangan.id');
        $this->kirim($pra, '/api/v1/auth/token/verifikasi', ['tantangan_id' => $id, 'kode' => $this->kodeTerakhir((int) $baru->telegram_id), 'device_id' => self::HP])->assertOk();

        return $pra;
    }

    private function teleponDitolak(string $nomor, ?int $abaikan = null): bool
    {
        return Validator::make(['phone' => $nomor], ['phone' => [new TeleponUnik($abaikan)]])->fails();
    }

    public function test_hapus_melepas_username_dan_phone_tetapi_mempertahankan_telegram_id(): void
    {
        $this->hapusOlehAdmin($this->lama);

        $baris = DB::table('users')->where('id', $this->lama->id)->first();
        $this->assertNotNull($baris->deleted_at);
        $this->assertNull($baris->username);
        $this->assertNull($baris->phone);
        $this->assertSame(7_400_000_001, (int) $baris->telegram_id);
        $this->assertStringNotContainsString('siti.lama', AuditLog::query()->withoutGlobalScopes()->get()->toJson());
    }

    public function test_q4_1_aktivasi_akun_telegram_baru_dengan_username_akun_terhapus_berhasil(): void
    {
        $this->hapusOlehAdmin($this->lama);
        $baru = $this->akunBaru();

        $this->selesaikanAktivasi($baru, ['username' => 'siti.lama']);

        $this->assertSame('siti.lama', $baru->fresh()->username);
    }

    public function test_q4_2_admin_memakai_phone_milik_akun_terhapus_lunak(): void
    {
        $this->hapusOlehAdmin($this->lama);

        $this->as($this->admin)->postJson('/api/v1/pengguna', [
            'name' => 'Siti Baru', 'role' => 'ahli_gizi', 'sppg_id' => $this->sppgA, 'phone' => self::TELEPON,
        ])->assertCreated();

        $this->assertSame(self::TELEPON_E164, User::query()->where('name', 'Siti Baru')->sole()->phone);
    }

    public function test_q4_3_login_identitas_lama_masuk_ke_akun_baru_bukan_baris_lama(): void
    {
        $this->hapusOlehAdmin($this->lama);
        $baru = $this->akunBaru();
        $this->selesaikanAktivasi($baru, ['username' => 'siti.lama', 'phone' => self::TELEPON]);

        $baru->refresh();
        $this->assertSame(self::TELEPON_E164, $baru->phone);

        $kredensial = app(KredensialService::class);
        $lama = User::withTrashed()->findOrFail($this->lama->id);
        $this->assertFalse($kredensial->identitasCocok($lama, 'siti.lama'));
        $this->assertFalse($kredensial->identitasCocok($lama, self::TELEPON));
        $this->assertTrue($kredensial->identitasCocok($baru, 'siti.lama'));

        $this->loginPenuh($baru, 'siti.lama', self::PASSWORD, self::TABLET);
        $this->loginPenuh($baru, self::TELEPON, self::PASSWORD, self::LAPTOP);
    }

    public function test_q4_4_daftar_ulang_telegram_id_sama_memulihkan_baris_lama_tanpa_bentrok(): void
    {
        $this->hapusOlehAdmin($this->lama);
        $baru = $this->akunBaru();
        $this->selesaikanAktivasi($baru, ['username' => 'siti.lama', 'phone' => self::TELEPON]);

        $service = app(InvitationService::class);
        $token = $service->issue($this->admin, TipeUndangan::Pengguna, ['role' => 'relawan', 'unit' => 'dapur', 'sppg_id' => $this->sppgA])->token;
        $pulih = $service->consume($token, ['id' => 7_400_000_001, 'first_name' => 'Siti', 'last_name' => 'Kembali', 'username' => 'siti_tg']);

        $this->assertSame($this->lama->id, $pulih->id);
        $this->assertFalse($pulih->fresh()->trashed());
        $this->assertNull($pulih->fresh()->username);
        $this->assertSame('siti.lama', $baru->fresh()->username);
        $this->assertSame(self::TELEPON_E164, $baru->fresh()->phone);
    }

    public function test_q4_5_akun_aktif_dan_nonaktif_belum_dihapus_tetap_menolak_username_dan_phone(): void
    {
        $nonaktif = User::factory()->role(Role::Akuntan)->denganKredensial('budi.nonaktif')
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7_400_000_003, 'phone' => '081299990003', 'is_active' => false]);

        // Username: pesan seragam.
        foreach (['siti.lama', 'budi.nonaktif'] as $username) {
            $pra = $this->praLogin($this->akunBaru(7_400_000_010 + strlen($username)));
            $this->aktivasi($pra, ['username' => $username])->assertUnprocessable()
                ->assertJsonPath('errors.username.0', 'Username tidak tersedia. Pilih username lain.');
        }

        // No telepon: ditolak validasi Admin; aktivasi tidak menyimpannya (audit tanpa nomor).
        $this->assertTrue($this->teleponDitolak(self::TELEPON));
        $this->assertTrue($this->teleponDitolak('081299990003'));
        $this->assertFalse($this->teleponDitolak(self::TELEPON, $this->lama->id));

        $baru = $this->akunBaru(7_400_000_020);
        $this->selesaikanAktivasi($baru, ['username' => 'orang.lain', 'phone' => self::TELEPON]);
        $this->assertNull($baru->fresh()->phone);
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::TELEPON_TIDAK_DISIMPAN)->count());
        $this->assertSame('siti.lama', $this->lama->fresh()->username);
        $this->assertSame('budi.nonaktif', $nonaktif->fresh()->username);
    }

    // ------------------------------------------------------------------ perintah pembersihan (Q4-6)

    /** Baris terhapus lunak ala data lama: delete() Eloquent biasa tidak melepas identitas. */
    private function terhapusLegacy(): User
    {
        $this->lama->delete();

        return $this->lama;
    }

    public function test_q4_6_dry_run_tidak_mengubah_apa_pun(): void
    {
        $this->terhapusLegacy();

        $this->artisan('pengguna:lepas-identitas-terhapus')
            ->expectsOutputToContain('DRY RUN: baris terhapus lunak yang akan dilepas identitasnya: 1')
            ->assertSuccessful();

        $baris = DB::table('users')->where('id', $this->lama->id)->first();
        $this->assertSame('siti.lama', $baris->username);
        $this->assertNotNull($baris->phone);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('event', LepasIdentitasTerhapus::EVENT_AUDIT)->count());
    }

    public function test_q4_6_confirm_mengubah_idempoten_dan_baris_aktif_utuh(): void
    {
        $this->terhapusLegacy();
        $nonaktif = User::factory()->role(Role::Akuntan)->denganKredensial('budi.nonaktif')
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => 7_400_000_003, 'phone' => '081299990003', 'is_active' => false]);
        $aktif = User::query()->findOrFail($this->kepalaA->id);
        $aktifSebelum = DB::table('users')->where('id', $aktif->id)->first();

        $this->artisan('pengguna:lepas-identitas-terhapus', ['--confirm' => true])
            ->expectsOutputToContain('1 baris')
            ->assertSuccessful();

        $lama = DB::table('users')->where('id', $this->lama->id)->first();
        $this->assertNull($lama->username);
        $this->assertNull($lama->phone);
        $this->assertSame(7_400_000_001, (int) $lama->telegram_id);
        $this->assertNotNull($lama->deleted_at);
        $this->assertSame('budi.nonaktif', $nonaktif->fresh()->username);
        $this->assertNotNull($nonaktif->fresh()->phone);
        $this->assertEquals($aktifSebelum, DB::table('users')->where('id', $aktif->id)->first());

        $audit = AuditLog::query()->withoutGlobalScopes()->where('event', LepasIdentitasTerhapus::EVENT_AUDIT)->sole();
        $this->assertSame(['jumlah_baris' => 1], $audit->new_values);
        $this->assertStringNotContainsString('siti.lama', $audit->toJson());

        // Idempoten: jalan kedua tidak mengubah apa pun dan tidak menambah audit.
        $this->artisan('pengguna:lepas-identitas-terhapus', ['--confirm' => true])->expectsOutputToContain('0 baris')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('event', LepasIdentitasTerhapus::EVENT_AUDIT)->count());
    }
}

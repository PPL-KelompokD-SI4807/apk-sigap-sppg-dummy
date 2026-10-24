<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Enums\TipeUndangan;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Users\InvalidInvitationException;
use App\Services\Users\InvitationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tes keamanan undangan: token salah, kedaluwarsa, dipakai ulang, balapan, telegram_id ganda.
 */
class InvitationServiceTest extends MasterApiTestCase
{
    private InvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvitationService::class);
    }

    /** @return array{0: string, 1: Invitation} */
    private function issueForNewAccount(array $attrs = []): array
    {
        $issued = $this->service->issue($this->admin, TipeUndangan::Pengguna, $attrs + [
            'role' => 'relawan', 'unit' => 'dapur', 'sppg_id' => $this->sppgA,
        ]);

        return [$issued->token, $issued->invitation];
    }

    private function tg(int $id = 7_000_000_001, array $extra = []): array
    {
        return $extra + ['id' => $id, 'first_name' => 'Siti', 'last_name' => 'Rahayu', 'username' => 'siti_dapur'];
    }

    private function assertReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Seharusnya ditolak dengan alasan {$reason}.");
        } catch (InvalidInvitationException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_issue_menyimpan_hash_saja_dan_token_unik(): void
    {
        $tokens = [];

        for ($i = 0; $i < 20; $i++) {
            [$token, $inv] = $this->issueForNewAccount();
            $tokens[] = $token;
            $this->assertSame(hash('sha256', $token), $inv->token_hash);
            $this->assertNotSame($token, $inv->token_hash);
            $this->assertEquals(48, round(now()->diffInHours($inv->expires_at, true)));
        }

        $this->assertCount(20, array_unique($tokens));
        $this->assertSame(0, DB::table('invitations')->whereIn('token_hash', $tokens)->count());
    }

    public function test_deep_link_memakai_username_bot_dari_config(): void
    {
        config(['telegram.bot_username' => 'sigap_demo_bot']);
        $issued = $this->service->issue($this->admin, TipeUndangan::Pengguna, ['role' => 'akuntan', 'sppg_id' => $this->sppgA]);
        $this->assertSame("https://t.me/sigap_demo_bot?start=inv_{$issued->token}", $issued->deepLink);

        config(['telegram.bot_username' => null]);
        $this->assertNull($this->service->issue($this->admin, TipeUndangan::Pengguna, ['role' => 'akuntan', 'sppg_id' => $this->sppgA])->deepLink);
    }

    public function test_issued_invitation_tidak_membocorkan_token_lewat_debug(): void
    {
        $issued = $this->service->issue($this->admin, TipeUndangan::Pengguna, ['role' => 'akuntan', 'sppg_id' => $this->sppgA]);

        $this->assertStringNotContainsString($issued->token, print_r($issued->__debugInfo(), true));
    }

    public function test_issue_ditolak_untuk_peran_tanpa_hak(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->issue($this->kepalaA, TipeUndangan::Pengguna, ['role' => 'relawan', 'unit' => 'dapur', 'sppg_id' => $this->sppgA]);
    }

    public function test_consume_membuat_akun_baru_dari_undangan_peran(): void
    {
        [$token, $inv] = $this->issueForNewAccount(['unit' => 'distribusi']);

        $user = $this->service->consume("inv_{$token}", $this->tg());

        $this->assertSame(7_000_000_001, $user->telegram_id);
        $this->assertSame('siti_dapur', $user->telegram_username);
        $this->assertSame('Siti Rahayu', $user->name);
        $this->assertSame(Role::Relawan, $user->role);
        $this->assertSame('distribusi', $user->unit->value);
        $this->assertSame($this->sppgA, $user->sppg_id);
        $this->assertTrue($user->is_active);

        $inv->refresh();
        $this->assertNotNull($inv->used_at);
        $this->assertSame($user->id, $inv->used_by);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $user->getMorphClass(), 'auditable_id' => $user->id, 'event' => 'created', 'sumber' => 'bot']);
    }

    public function test_consume_menautkan_akun_yang_dibuat_admin(): void
    {
        $akun = User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgA, 'name' => 'Budi Akuntan']);
        $issued = $this->service->issue($this->admin, TipeUndangan::Pengguna, ['user_id' => $akun->id]);
        $akun->createToken('lama');

        $user = $this->service->consume($issued->token, $this->tg(7_000_000_002, ['username' => '@budi_akun']));

        $this->assertTrue($user->is($akun));
        $this->assertSame('Budi Akuntan', $user->name, 'Nama dari admin tidak ditimpa nama Telegram.');
        $this->assertSame(7_000_000_002, $user->fresh()->telegram_id);
        $this->assertSame('budi_akun', $user->fresh()->telegram_username);
        $this->assertSame(0, User::count() - 8, 'Tidak ada akun baru dibuat.');
        $this->assertSame(0, $akun->tokens()->count(), 'Perubahan telegram_id mencabut token.');
    }

    public function test_token_salah_ditolak(): void
    {
        $this->issueForNewAccount();

        $this->assertReason(InvalidInvitationException::TIDAK_VALID, fn () => $this->service->consume(str_repeat('a', 43), $this->tg()));
        $this->assertReason(InvalidInvitationException::TIDAK_VALID, fn () => $this->service->consume('pendek', $this->tg()));
        $this->assertReason(InvalidInvitationException::TIDAK_VALID, fn () => $this->service->consume('', $this->tg()));
        $this->assertReason(InvalidInvitationException::TIDAK_VALID, fn () => $this->service->consume("' OR 1=1 --".str_repeat('a', 30), $this->tg()));
        $this->assertDatabaseCount('users', 7);
    }

    public function test_token_yang_hash_nya_dipakai_langsung_ditolak(): void
    {
        [, $inv] = $this->issueForNewAccount();

        // Pengetahuan atas token_hash (mis. kebocoran DB) tidak cukup untuk memakai undangan.
        $this->assertReason(InvalidInvitationException::TIDAK_VALID, fn () => $this->service->consume($inv->token_hash, $this->tg()));
        $this->assertNull($inv->fresh()->used_at);
    }

    public function test_kedaluwarsa_setelah_48_jam(): void
    {
        [$token] = $this->issueForNewAccount();

        $this->travel(48)->hours();
        $this->travel(1)->minutes();

        $this->assertReason(InvalidInvitationException::KEDALUWARSA, fn () => $this->service->consume($token, $this->tg()));
        $this->assertDatabaseCount('users', 7);
    }

    public function test_masih_berlaku_sebelum_48_jam(): void
    {
        [$token] = $this->issueForNewAccount();

        $this->travel(47)->hours();

        $this->assertSame(7_000_000_001, $this->service->consume($token, $this->tg())->telegram_id);
    }

    public function test_dipakai_ulang_ditolak_meski_oleh_telegram_lain(): void
    {
        [$token] = $this->issueForNewAccount();
        $this->service->consume($token, $this->tg(7_000_000_001));

        $this->assertReason(InvalidInvitationException::TERPAKAI, fn () => $this->service->consume($token, $this->tg(7_000_000_001)));
        $this->assertReason(InvalidInvitationException::TERPAKAI, fn () => $this->service->consume($token, $this->tg(7_000_000_002)));
        $this->assertDatabaseCount('users', 8);
        $this->assertDatabaseMissing('users', ['telegram_id' => 7_000_000_002]);
    }

    public function test_undangan_dicabut_ditolak(): void
    {
        [$token, $inv] = $this->issueForNewAccount();
        $this->service->revoke($inv);

        $this->assertReason(InvalidInvitationException::DICABUT, fn () => $this->service->consume($token, $this->tg()));
        $this->assertDatabaseCount('users', 7);
    }

    public function test_dua_consume_token_sama_hanya_satu_berhasil(): void
    {
        [$token] = $this->issueForNewAccount();

        $hasil = [];
        foreach ([7_000_000_011, 7_000_000_012] as $id) {
            try {
                $hasil[] = $this->service->consume($token, $this->tg($id))->telegram_id;
            } catch (InvalidInvitationException $e) {
                $hasil[] = $e->reason;
            }
        }

        $this->assertSame([7_000_000_011, InvalidInvitationException::TERPAKAI], $hasil);
        $this->assertDatabaseCount('users', 8);
    }

    public function test_klaim_atomik_menolak_pemenang_balapan_yang_menyelip(): void
    {
        [$token, $inv] = $this->issueForNewAccount();

        // Simulasi proses lain memakai undangan tepat setelah pembacaan (sebelum klaim).
        $diselipkan = false;
        Invitation::retrieved(function (Invitation $model) use (&$diselipkan, $inv): void {
            if (! $diselipkan && $model->is($inv)) {
                $diselipkan = true;
                DB::table('invitations')->where('id', $inv->id)->update(['used_at' => now()]);
            }
        });

        $this->assertReason(InvalidInvitationException::TERPAKAI, fn () => $this->service->consume($token, $this->tg()));
        $this->assertTrue($diselipkan);
        $this->assertDatabaseCount('users', 7);
    }

    public function test_telegram_id_sudah_dipakai_pengguna_lain_ditolak_dan_undangan_tidak_hangus(): void
    {
        [$token, $inv] = $this->issueForNewAccount();

        $this->assertReason(
            InvalidInvitationException::TELEGRAM_SUDAH_TERDAFTAR,
            fn () => $this->service->consume($token, $this->tg($this->kepalaA->telegram_id)),
        );

        $this->assertNull($inv->fresh()->used_at, 'Transaksi dibatalkan: undangan tetap dapat dipakai akun Telegram lain.');
        $this->assertSame(7_000_000_077, $this->service->consume($token, $this->tg(7_000_000_077))->telegram_id);
    }

    public function test_telegram_id_milik_pengguna_terhapus_lunak_tetap_ditolak(): void
    {
        $this->kepalaB->delete();
        [$token] = $this->issueForNewAccount();

        $this->assertReason(
            InvalidInvitationException::TELEGRAM_SUDAH_TERDAFTAR,
            fn () => $this->service->consume($token, $this->tg($this->kepalaB->telegram_id)),
        );
    }

    public function test_balapan_telegram_id_antar_undangan_ditangkap_indeks_unik(): void
    {
        [$token, $inv] = $this->issueForNewAccount();

        // Proses lain menyisipkan telegram_id yang sama tepat sebelum INSERT akun (lolos pemeriksaan awal).
        User::creating(function (User $user): void {
            DB::table('users')->insert([
                'name' => 'Penyelip', 'role' => 'akuntan', 'sppg_id' => $this->sppgA, 'is_active' => true,
                'telegram_id' => $user->telegram_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertReason(InvalidInvitationException::TELEGRAM_SUDAH_TERDAFTAR, fn () => $this->service->consume($token, $this->tg(7_000_000_090)));
        // Transaksi dibatalkan seluruhnya (undangan belum hangus).
        $this->assertNull($inv->fresh()->used_at);
    }

    public function test_akun_yang_sudah_terhubung_tidak_dapat_ditimpa(): void
    {
        $akun = User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgA]);
        $issued = $this->service->issue($this->admin, TipeUndangan::Pengguna, ['user_id' => $akun->id]);
        // Akun terhubung lewat jalur lain setelah undangan terbit.
        $akun->forceFill(['telegram_id' => 7_000_000_050])->save();

        $this->assertReason(InvalidInvitationException::AKUN_SUDAH_TERHUBUNG, fn () => $this->service->consume($issued->token, $this->tg(7_000_000_051)));
        $this->assertSame(7_000_000_050, $akun->fresh()->telegram_id);
    }

    public function test_akun_target_terhapus_lunak_ditolak(): void
    {
        $akun = User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgA]);
        $issued = $this->service->issue($this->admin, TipeUndangan::Pengguna, ['user_id' => $akun->id]);
        DB::table('users')->where('id', $akun->id)->update(['deleted_at' => now()]);

        $this->assertReason(InvalidInvitationException::TIDAK_VALID, fn () => $this->service->consume($issued->token, $this->tg()));
    }

    public function test_data_telegram_tidak_valid_ditolak(): void
    {
        [$token] = $this->issueForNewAccount();

        foreach ([[], ['id' => 0], ['id' => -5], ['id' => 'abc'], ['id' => 1.5], ['id' => null]] as $tg) {
            $this->assertReason(InvalidInvitationException::DATA_TELEGRAM_TIDAK_VALID, fn () => $this->service->consume($token, $tg));
        }
    }

    public function test_nama_dari_telegram_dipotong_dan_cadangan_bila_kosong(): void
    {
        [$token] = $this->issueForNewAccount();
        $user = $this->service->consume($token, ['id' => 7_000_000_100, 'first_name' => str_repeat('A', 300)]);
        $this->assertSame(120, mb_strlen($user->name));

        [$token2] = $this->issueForNewAccount();
        $this->assertSame('Pengguna Telegram', $this->service->consume($token2, ['id' => 7_000_000_101])->name);
    }

    public function test_undangan_pic_menautkan_chat_ke_titik_layanan(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $issued = $this->service->issue($this->kepalaA, TipeUndangan::PicTitikLayanan, ['titik_layanan_id' => $titik->id]);

        // Jenis tidak sesuai: consume() hanya untuk undangan pengguna dan tidak menghanguskan undangan.
        $this->assertReason(InvalidInvitationException::TIPE_TIDAK_SESUAI, fn () => $this->service->consume($issued->token, $this->tg(7_100_000_001)));
        $this->assertNull($issued->invitation->fresh()->used_at);

        $hasil = $this->service->consumeAny($issued->token, $this->tg(7_100_000_001));

        $this->assertSame(TipeUndangan::PicTitikLayanan, $hasil->tipe);
        $this->assertNull($hasil->user);
        $this->assertSame(7_100_000_001, $hasil->titikLayanan->fresh()->pic_telegram_chat_id);
        $this->assertDatabaseMissing('users', ['telegram_id' => 7_100_000_001]);
        $this->assertReason(InvalidInvitationException::TERPAKAI, fn () => $this->service->consumePic($issued->token, $this->tg(7_100_000_002)));
    }

    public function test_pic_boleh_memakai_chat_id_terpisah_dan_menimpa_pic_lama(): void
    {
        $titik = TitikLayanan::factory()->picTerhubung()->create(['sppg_id' => $this->sppgA]);
        $issued = $this->service->issue($this->kepalaA, TipeUndangan::PicTitikLayanan, ['titik_layanan_id' => $titik->id]);

        $this->service->consumePic($issued->token, $this->tg(7_100_000_010, ['chat_id' => 7_100_000_010]));

        $this->assertSame(7_100_000_010, $titik->fresh()->pic_telegram_chat_id);
    }

    public function test_undangan_pengguna_tidak_dapat_dipakai_sebagai_pic(): void
    {
        [$token, $inv] = $this->issueForNewAccount();

        $this->assertReason(InvalidInvitationException::TIPE_TIDAK_SESUAI, fn () => $this->service->consumePic($token, $this->tg()));
        $this->assertNull($inv->fresh()->used_at);
    }

    public function test_kepala_tidak_dapat_membuat_undangan_pic_titik_sppg_lain(): void
    {
        $titikB = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB]);
        $this->as($this->kepalaA);

        $this->expectException(ValidationException::class);
        $this->service->issue($this->kepalaA, TipeUndangan::PicTitikLayanan, ['titik_layanan_id' => $titikB->id]);
    }

    public function test_audit_log_tidak_menyimpan_token(): void
    {
        [$token] = $this->issueForNewAccount();
        $this->service->consume($token, $this->tg());

        foreach (AuditLog::query()->get() as $log) {
            $this->assertStringNotContainsString($token, json_encode($log->toArray()));
        }
    }
}

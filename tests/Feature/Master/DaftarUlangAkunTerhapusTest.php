<?php

namespace Tests\Feature\Master;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Enums\StatusPermintaanReset;
use App\Enums\TipeUndangan;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\Invitation;
use App\Models\PermintaanResetPassword;
use App\Models\User;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Users\InvalidInvitationException;
use App\Services\Users\InvitationService;
use App\Services\Users\KepemilikanSppgService;
use App\Services\Users\UserAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Telegram\FakesTelegram;

/**
 * Daftar ulang akun yang dihapus Admin (soft delete): baris LAMA dipulihkan lewat undangan Admin,
 * atribut akses dari isian Admin, kredensial & semua sesi/tautan lama dicabut, riwayat tetap di id sama.
 */
class DaftarUlangAkunTerhapusTest extends MasterApiTestCase
{
    use FakesTelegram;

    private const PESAN_TERDAFTAR = 'Akun Telegram ini sudah terdaftar di SiGAP SPPG.';

    private User $lama;

    private int $tgLama = 7_300_000_001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();

        $this->lama = User::factory()->role(Role::Akuntan)->denganKredensial('budi_lama')
            ->create(['sppg_id' => $this->sppgA, 'telegram_id' => $this->tgLama, 'name' => 'Budi Lama', 'phone' => '081233330001']);
        $this->lama->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(5)])->save();
    }

    private function service(): InvitationService
    {
        return app(InvitationService::class);
    }

    private function hapusOlehAdmin(User $target): void
    {
        $this->as($this->admin)->deleteJson("/api/v1/pengguna/{$target->id}")->assertNoContent();
        $this->forgetActor();
    }

    private function undanganPeran(array $attrs, ?User $penerbit = null): string
    {
        return $this->service()->issue($penerbit ?? $this->admin, TipeUndangan::Pengguna, $attrs)->token;
    }

    private function start(int $telegramId, string $param): string
    {
        $this->webhook($this->privateUpdate($telegramId, ['text' => trim('/start '.$param)]))->assertOk();

        return (string) $this->messagesTo($telegramId)->last()['text'];
    }

    private function tg(int $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'first_name' => 'Budi', 'last_name' => 'Kembali', 'username' => 'budi_kembali'];
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

    /** @return list<string> */
    private function auditEvents(User $user): array
    {
        return AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id)
            ->orderBy('id')->pluck('event')->all();
    }

    private function terbitBotAksi(User $user, string $status = BotAksi::STATUS_AKTIF): BotAksi
    {
        $aksi = app(BotAksiService::class)->terbit($user, (int) $user->telegram_id, BotAksi::JENIS_DAFTAR, ['sppg_id' => $user->sppg_id]);
        BotAksi::query()->whereKey($aksi->id)->update(['status' => $status]);

        return $aksi->refresh();
    }

    // ------------------------------------------------------------------ hapus

    public function test_hapus_mencabut_token_sanctum_perangkat_tantangan_dan_tombol_bot(): void
    {
        $bearer = $this->beriSesiPenuh($this->lama);
        $aktif = $this->terbitBotAksi($this->lama);
        $catatan = $this->terbitBotAksi($this->lama, BotAksi::STATUS_MENUNGGU_CATATAN);
        $selesai = $this->terbitBotAksi($this->lama, BotAksi::STATUS_SELESAI);

        $this->hapusOlehAdmin($this->lama);

        $this->assertSoftDeleted('users', ['id' => $this->lama->id, 'telegram_id' => $this->tgLama]);
        $this->assertSame(0, $this->lama->tokens()->count());
        $this->assertSame(0, $this->lama->perangkatTepercaya()->aktif()->count());
        $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $aktif->fresh()->status);
        $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $catatan->fresh()->status);
        $this->assertSame(BotAksi::STATUS_SELESAI, $selesai->fresh()->status, 'Riwayat aksi yang sudah selesai tidak diubah.');
        $this->assertFalse(app(BotAksiService::class)->berlaku($aktif->fresh(), $this->tgLama, $this->tgLama, $this->lama));

        $this->withToken($bearer)->getJson('/api/v1/pengguna/'.$this->lama->id)->assertUnauthorized();
    }

    // ------------------------------------------------------------------ pulih lewat undangan peran

    public function test_pulih_lewat_undangan_peran_memakai_isian_admin_dan_baris_yang_sama(): void
    {
        $bearer = $this->beriSesiPenuh($this->lama);
        $auditSebelum = AuditLog::query()->withoutGlobalScopes()->where('auditable_id', $this->lama->id)->where('auditable_type', $this->lama->getMorphClass())->pluck('id')->all();
        $this->hapusOlehAdmin($this->lama);
        $jumlahUser = User::query()->withoutGlobalScopes()->count();

        $token = $this->undanganPeran(['role' => 'relawan', 'unit' => 'distribusi', 'sppg_id' => $this->sppgB]);
        $balasan = $this->start($this->tgLama, 'inv_'.$token);

        $this->assertStringContainsString('berhasil terhubung', $balasan);
        $this->assertStringNotContainsString(self::PESAN_TERDAFTAR, $balasan);

        $pulih = User::query()->withoutGlobalScopes()->findOrFail($this->lama->id);
        $this->assertFalse($pulih->trashed());
        $this->assertSame($jumlahUser, User::query()->withoutGlobalScopes()->count(), 'Tidak ada baris baru.');
        $this->assertSame($this->tgLama, $pulih->telegram_id);
        // Isian Admin, bukan baris lama (akuntan SPPG A).
        $this->assertSame(Role::Relawan, $pulih->role);
        $this->assertSame(RelawanUnit::Distribusi, $pulih->unit);
        $this->assertSame($this->sppgB, $pulih->sppg_id);
        $this->assertTrue($pulih->is_active);
        // Undangan peran: nama dari profil Telegram (seperti akun baru), bukan nama baris lama.
        $this->assertSame('Siti Fiktif', $pulih->name);
        $this->assertSame('siti_fiktif', $pulih->telegram_username);
        // Kredensial lama dikosongkan: wajib aktivasi ulang lewat token bot.
        $this->assertNull($pulih->username);
        $this->assertNull($pulih->password);
        $this->assertFalse($pulih->punyaPassword());
        $this->assertNull($pulih->password_changed_at);
        $this->assertSame(0, $pulih->failed_login_attempts);
        $this->assertNull($pulih->locked_until);
        $this->assertNull($pulih->phone, 'No telepon lama tidak dibawa (undangan peran tanpa no telepon).');

        $inv = Invitation::query()->where('token_hash', Invitation::hashToken($token))->sole();
        $this->assertSame($this->lama->id, (int) $inv->used_by);
        $this->assertNotNull($inv->used_at);

        // Riwayat tetap pada id yang sama + pemulihan tercatat bersumber bot.
        $this->assertSame($auditSebelum, AuditLog::query()->withoutGlobalScopes()->whereIn('id', $auditSebelum)->where('auditable_id', $this->lama->id)->pluck('id')->all());
        $events = $this->auditEvents($pulih);
        $this->assertSame('created', $events[0]);
        $this->assertContains('deleted', $events);
        $this->assertSame(['updated', 'restored'], array_slice($events, -2));
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $this->lama->id, 'auditable_type' => $this->lama->getMorphClass(), 'event' => 'restored', 'sumber' => 'bot', 'sppg_id' => $this->sppgB]);
        $this->assertStringNotContainsString($token, (string) json_encode(AuditLog::query()->get()->toArray()));

        // Token lama tetap tidak berlaku setelah pulih.
        $this->assertSame(0, $pulih->tokens()->count());
        $this->withToken($bearer)->getJson('/api/v1/pengguna/'.$this->lama->id)->assertUnauthorized();
    }

    public function test_pemulihan_mencabut_token_bot_aksi_draf_reset_yang_tersisa(): void
    {
        $this->hapusOlehAdmin($this->lama);

        // Sisa yang (secara tidak wajar) masih menempel pada baris terhapus.
        $this->lama->createToken('sisa');
        $aksi = $this->terbitBotAksi($this->lama);
        $catatan = $this->terbitBotAksi($this->lama, BotAksi::STATUS_MENUNGGU_CATATAN);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $this->lama->id]);
        $reset = new PermintaanResetPassword;
        $reset->forceFill(['user_id' => $this->lama->id, 'sppg_id' => $this->sppgA, 'status' => StatusPermintaanReset::Disetujui, 'kedaluwarsa_pada' => now()->addDay()])->save();

        $user = $this->service()->consume($this->undanganPeran(['role' => 'akuntan', 'sppg_id' => $this->sppgA]), $this->tg($this->tgLama));

        $this->assertSame($this->lama->id, $user->id);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $aksi->fresh()->status);
        $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $catatan->fresh()->status);
        $this->assertFalse(app(BotAksiService::class)->berlaku($aksi->fresh(), $this->tgLama, $this->tgLama, $user));
        $this->assertSame(StatusAiDraft::Expired, AiDraft::query()->withoutGlobalScopes()->findOrFail($draf->id)->status);
        $this->assertSame((int) $this->lama->id, (int) AiDraft::query()->withoutGlobalScopes()->findOrFail($draf->id)->user_id, 'Draf tetap milik baris yang sama.');
        $this->assertSame(StatusPermintaanReset::Ditolak, PermintaanResetPassword::query()->withoutGlobalScopes()->findOrFail($reset->id)->status);
    }

    // ------------------------------------------------------------------ pulih lewat undangan user_id

    public function test_pulih_lewat_undangan_user_id_memakai_placeholder_lalu_placeholder_dihapus(): void
    {
        $this->hapusOlehAdmin($this->lama);

        $this->as($this->admin);
        $placeholderId = $this->postJson('/api/v1/pengguna', [
            'name' => 'Budi Baru', 'role' => 'staf_gudang', 'sppg_id' => $this->sppgB, 'phone' => '0812-3333-0099', 'is_active' => false,
        ])->assertCreated()->json('data.id');
        $undangan = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $placeholderId])->assertCreated()->json('data');
        $telepon = User::query()->findOrFail($placeholderId)->phone;
        $this->forgetActor();

        $balasan = $this->start($this->tgLama, 'inv_'.$undangan['token']);
        $this->assertStringNotContainsString(self::PESAN_TERDAFTAR, $balasan);

        $pulih = User::query()->withoutGlobalScopes()->findOrFail($this->lama->id);
        $this->assertFalse($pulih->trashed());
        $this->assertSame('Budi Baru', $pulih->name);
        $this->assertSame(Role::StafGudang, $pulih->role);
        $this->assertNull($pulih->unit);
        $this->assertSame($this->sppgB, $pulih->sppg_id);
        $this->assertFalse($pulih->is_active, 'Status aktif mengikuti isian Admin.');
        $this->assertSame($telepon, $pulih->phone);
        $this->assertNull($pulih->username);
        $this->assertNull($pulih->password);

        $placeholder = User::query()->withoutGlobalScopes()->findOrFail($placeholderId);
        $this->assertTrue($placeholder->trashed());
        $this->assertNull($placeholder->telegram_id);
        $this->assertNull($placeholder->phone);

        $inv = Invitation::query()->findOrFail($undangan['id']);
        $this->assertSame($placeholderId, (int) $inv->user_id);
        $this->assertSame($this->lama->id, (int) $inv->used_by);
        $this->assertSame(['updated', 'restored'], array_slice($this->auditEvents($pulih), -2));

        $this->as($this->admin)->getJson('/api/v1/pengguna/undangan?status=terpakai')->assertOk()
            ->assertJsonPath('data.0.id', $undangan['id'])
            ->assertJsonPath('data.0.used_by', $this->lama->id);
        $this->getJson("/api/v1/pengguna/{$this->lama->id}")->assertOk()->assertJsonPath('data.name', 'Budi Baru');
        $this->getJson("/api/v1/pengguna/{$placeholderId}")->assertNotFound();
    }

    public function test_kepemilikan_investor_lama_tidak_hidup_kembali(): void
    {
        $this->forgetActor();
        $investor = User::factory()->role(Role::Investor)->create(['telegram_id' => 7_300_000_050]);
        $kepemilikan = app(KepemilikanSppgService::class);
        $kepemilikan->atur($this->admin, $investor, [$this->sppgA, $this->sppgB]);
        $this->hapusOlehAdmin($investor);

        $placeholder = User::factory()->role(Role::Investor)->withoutTelegram()->create();
        $kepemilikan->atur($this->admin, $placeholder, [$this->sppgB]);
        $issued = $this->service()->issue($this->admin, TipeUndangan::Pengguna, ['user_id' => $placeholder->id]);

        $pulih = $this->service()->consume($issued->token, $this->tg(7_300_000_050));

        $this->assertSame($investor->id, $pulih->id);
        $this->assertSame([$this->sppgB], DB::table('investor_sppg')->where('user_id', $investor->id)->pluck('sppg_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, DB::table('investor_sppg')->where('user_id', $placeholder->id)->count());
    }

    public function test_akun_pulih_hanya_melihat_sppg_baru_tanpa_kebocoran_lintas_sppg(): void
    {
        $this->forgetActor();
        $kepalaLama = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->sppgB, 'telegram_id' => 7_300_000_060]);
        $this->hapusOlehAdmin($kepalaLama);

        $pulih = $this->service()->consume($this->undanganPeran(['role' => 'kepala_sppg', 'sppg_id' => $this->sppgA]), $this->tg(7_300_000_060));
        $this->assertSame($this->sppgA, $pulih->sppg_id);

        $this->as($pulih);
        $this->getJson("/api/v1/pengguna/{$this->kepalaB->id}")->assertNotFound();
        $this->getJson("/api/v1/pengguna/{$this->kepalaA->id}")->assertOk();
        $ids = collect($this->getJson('/api/v1/pengguna?per_page=100')->assertOk()->json('data'))->pluck('sppg_id')->unique()->values()->all();
        $this->assertSame([$this->sppgA], $ids);
    }

    // ------------------------------------------------------------------ penolakan

    public function test_id_telegram_aktif_ganda_tetap_ditolak_dengan_pesan_persis(): void
    {
        $token = $this->undanganPeran(['role' => 'akuntan', 'sppg_id' => $this->sppgB]);

        $balasan = $this->start($this->tgLama, 'inv_'.$token);

        $this->assertSame(self::PESAN_TERDAFTAR, $balasan);
        $segar = $this->lama->fresh();
        $this->assertSame(Role::Akuntan, $segar->role);
        $this->assertSame($this->sppgA, $segar->sppg_id);
        $this->assertSame('budi_lama', $segar->username);
        $this->assertNull(Invitation::query()->where('token_hash', Invitation::hashToken($token))->sole()->used_at);
    }

    public function test_dua_undangan_untuk_id_terhapus_yang_sama_hanya_satu_memulihkan(): void
    {
        $this->hapusOlehAdmin($this->lama);
        $t1 = $this->undanganPeran(['role' => 'akuntan', 'sppg_id' => $this->sppgA]);
        $t2 = $this->undanganPeran(['role' => 'admin']);

        $this->assertSame($this->lama->id, $this->service()->consume($t1, $this->tg($this->tgLama))->id);
        $this->assertReason(InvalidInvitationException::TELEGRAM_SUDAH_TERDAFTAR, fn () => $this->service()->consume($t2, $this->tg($this->tgLama)));
        $this->assertSame(Role::Akuntan, $this->lama->fresh()->role, 'Undangan kedua tidak menaikkan peran.');
    }

    public function test_start_tanpa_undangan_atau_undangan_tidak_sah_tidak_memulihkan(): void
    {
        $this->hapusOlehAdmin($this->lama);

        $this->assertStringNotContainsString('berhasil terhubung', $this->start($this->tgLama, ''));
        $this->assertSame('Tautan undangan tidak valid.', $this->start($this->tgLama, 'inv_TokenFiktifTidakAdaSamaSekali123'));

        $kedaluwarsa = $this->undanganPeran(['role' => 'akuntan', 'sppg_id' => $this->sppgA]);
        Invitation::query()->update(['expires_at' => now()->subMinute()]);
        $this->assertStringContainsString('kedaluwarsa', $this->start($this->tgLama, 'inv_'.$kedaluwarsa));

        $this->assertSoftDeleted('users', ['id' => $this->lama->id]);
    }

    public function test_non_admin_tidak_dapat_menerbitkan_undangan_403(): void
    {
        $this->hapusOlehAdmin($this->lama);

        foreach ([$this->kepalaA, $this->akuntanA, $this->relawanA] as $aktor) {
            $this->as($aktor)->postJson('/api/v1/pengguna/undangan', ['role' => 'akuntan', 'sppg_id' => $this->sppgA])->assertForbidden();
        }

        $this->assertSame(0, Invitation::query()->count());
        $this->assertSoftDeleted('users', ['id' => $this->lama->id]);
    }

    public function test_undangan_dari_penerbit_yang_tidak_lagi_berhak_tidak_memulihkan(): void
    {
        $this->forgetActor();
        $adminLain = User::factory()->admin()->create();
        $this->hapusOlehAdmin($this->lama);
        $token = $this->undanganPeran(['role' => 'admin'], $adminLain);

        // Admin penerbit diturunkan perannya sebelum undangan dipakai.
        app(UserAccountService::class)->update($this->admin, $adminLain, ['role' => 'akuntan', 'sppg_id' => $this->sppgA]);

        $this->assertReason(InvalidInvitationException::TIDAK_VALID, fn () => $this->service()->consume($token, $this->tg($this->tgLama)));
        $this->assertSoftDeleted('users', ['id' => $this->lama->id]);
    }

    public function test_sppg_terhapus_atau_tidak_valid_ditolak(): void
    {
        $this->hapusOlehAdmin($this->lama);
        $token = $this->undanganPeran(['role' => 'akuntan', 'sppg_id' => $this->sppgB]);

        $this->forgetActor();
        $placeholder = User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgB]);
        $tokenPlaceholder = $this->service()->issue($this->admin, TipeUndangan::Pengguna, ['user_id' => $placeholder->id])->token;

        DB::table('sppgs')->where('id', $this->sppgB)->update(['deleted_at' => now()]);

        $this->assertReason(InvalidInvitationException::SPPG_TIDAK_VALID, fn () => $this->service()->consume($token, $this->tg($this->tgLama)));
        $this->assertReason(InvalidInvitationException::SPPG_TIDAK_VALID, fn () => $this->service()->consume($tokenPlaceholder, $this->tg($this->tgLama)));
        // Juga untuk akun baru (ID belum pernah terdaftar).
        $this->assertReason(InvalidInvitationException::SPPG_TIDAK_VALID, fn () => $this->service()->consume($token, $this->tg(7_300_000_099)));

        $this->assertSoftDeleted('users', ['id' => $this->lama->id]);
        $this->assertFalse($placeholder->fresh()->trashed(), 'Transaksi dibatalkan: placeholder utuh.');
        $this->assertSame(0, Invitation::query()->whereNotNull('used_at')->count(), 'Undangan tidak hangus.');
        $this->assertStringContainsString('SPPG pada tautan undangan ini tidak lagi tersedia', $this->start($this->tgLama, 'inv_'.$token));

        // Penerbitan undangan ke SPPG terhapus/tidak ada ditolak (API 422 dan service).
        $this->as($this->admin)->postJson('/api/v1/pengguna/undangan', ['role' => 'akuntan', 'sppg_id' => $this->sppgB])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/pengguna/undangan', ['role' => 'akuntan', 'sppg_id' => 999_999])
            ->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->expectException(ValidationException::class);
        $this->service()->issue($this->admin, TipeUndangan::Pengguna, ['role' => 'akuntan', 'sppg_id' => $this->sppgB]);
    }

    public function test_admin_tidak_dapat_menghapus_diri_dan_admin_aktif_terakhir_dilindungi(): void
    {
        $this->as($this->admin)->deleteJson("/api/v1/pengguna/{$this->admin->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['user']);

        $this->forgetActor();
        $penyerang = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->sppgA]);

        try {
            app(UserAccountService::class)->delete($penyerang, $this->admin);
            $this->fail('Admin aktif terakhir seharusnya tidak dapat dihapus.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user', $e->errors());
        }

        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'deleted_at' => null, 'is_active' => true]);
    }
}

<?php

namespace Tests\Feature\Ai\Eksekusi;

use App\Enums\StatusAiDraft;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Pemasok;
use App\Models\Scopes\SppgScope;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Eksekusi\PermintaanInternal;
use App\Services\Ai\Eksekusi\ResponsInternal;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotAksiAiService;
use App\Support\SppgAktif;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mekanisme sub-request internal: stack REST identik (Policy/Form Request/middleware), identitas = pengguna penekan +
 * sesi penuhnya, dan TIDAK ADA state auth/request/SPPG aktif yang bocor ke permintaan berikutnya.
 */
class AiEksekusiSubRequestTest extends AiEksekusiTestCase
{
    private function sesi(User $user): PersonalAccessToken
    {
        $token = app(SesiService::class)->tokenSesiPenuh($user);
        $this->assertNotNull($token);

        return $token;
    }

    public function test_pengguna_tanpa_hak_ditolak_403_di_jalur_sub_request(): void
    {
        $r = app(PermintaanInternal::class)->kirim($this->kepalaA, $this->sesi($this->kepalaA), 'POST', '/api/v1/pengguna/undangan', ['role' => 'staf_gudang', 'sppg_id' => $this->sppgA]);
        $this->assertSame(403, $r->status);

        $r = app(PermintaanInternal::class)->kirim($this->stafGudangA, $this->sesi($this->stafGudangA), 'DELETE', '/api/v1/pengguna/'.$this->kepalaA->id);
        $this->assertSame(403, $r->status);

        // Investor (baca saja) dan relawan tanpa hak modul.
        $investor = $this->investor();
        $this->assertSame(403, app(PermintaanInternal::class)->kirim($investor, $this->sesi($investor), 'POST', '/api/v1/pemasok', ['nama' => 'X', 'jenis' => 'petani'])->status);
        $this->assertSame(403, app(PermintaanInternal::class)->kirim($this->relawanA, $this->sesi($this->relawanA), 'POST', '/api/v1/pemasok', ['nama' => 'X', 'jenis' => 'petani'])->status);

        // Lintas SPPG: kepala A tidak dapat menghapus titik layanan SPPG B (binding ber-SppgScope -> 404).
        $titikB = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB]);
        $this->assertContains(app(PermintaanInternal::class)->kirim($this->kepalaA, $this->sesi($this->kepalaA), 'DELETE', '/api/v1/titik-layanan/'.$titikB->id)->status, [403, 404]);

        $this->assertSame(0, Invitation::query()->count());
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
    }

    public function test_token_sesi_milik_orang_lain_atau_nonaktif_ditolak(): void
    {
        $r = app(PermintaanInternal::class)->kirim($this->admin, $this->sesi($this->kepalaA), 'POST', '/api/v1/pengguna/undangan', ['role' => 'staf_gudang', 'sppg_id' => $this->sppgA]);
        $this->assertSame(401, $r->status);

        $sesi = $this->sesi($this->stafGudangA);
        $this->stafGudangA->forceFill(['is_active' => false])->saveQuietly();
        $r = app(PermintaanInternal::class)->kirim($this->stafGudangA, $sesi, 'POST', '/api/v1/pemasok', ['nama' => 'X', 'jenis' => 'petani']);
        $this->assertSame(403, $r->status, 'EnsureUserIsActive tetap berlaku');
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
    }

    public function test_sub_request_tidak_membocorkan_state_auth_request_atau_sppg_aktif(): void
    {
        $requestAsal = app('request');
        $guardAsal = config('auth.defaults.guard');

        $r = app(PermintaanInternal::class)->kirim($this->stafGudangA, $this->sesi($this->stafGudangA), 'POST', '/api/v1/pemasok', ['nama' => 'Tani Fiktif', 'jenis' => 'petani']);
        $this->assertSame(201, $r->status);

        $this->assertSame($requestAsal, app('request'));
        $this->assertSame($guardAsal, config('auth.defaults.guard'));
        $this->assertFalse(Auth::guard('sanctum')->hasUser());
        $this->assertFalse(Auth::hasUser());
        $this->assertNull(SppgScope::currentUser());
        $this->assertSame($this->sppgB, app(SppgAktif::class)->id($this->kepalaB), 'Cache SPPG aktif tidak terbawa');
        $this->assertNull(AuditLog::currentSource());

        // Permintaan HTTP berikutnya tanpa token: tidak terautentikasi sebagai staf gudang.
        $this->getJson('/api/v1/pemasok')->assertUnauthorized();
        $this->forgetActor();

        // Konteks pengguna pemanggil (ActingUser) dipulihkan setelah sub-request.
        ActingUser::run($this->kepalaA, function (): void {
            $r = app(PermintaanInternal::class)->kirim($this->stafGudangA, $this->sesi($this->stafGudangA), 'POST', '/api/v1/pemasok', ['nama' => 'Tani Dua', 'jenis' => 'petani']);
            $this->assertSame(201, $r->status);
            $this->assertTrue(Auth::user()->is($this->kepalaA));
            $this->assertSame((string) config('auth.defaults.guard'), 'web');
        });
        $this->assertFalse(Auth::hasUser());

        // Audit perubahan model di dalam sub-request ditulis atas nama staf gudang.
        $pemasok = Pemasok::query()->withoutGlobalScope(SppgScope::class)->where('nama', 'Tani Dua')->sole();
        $this->assertSame($this->sppgA, (int) $pemasok->sppg_id);
    }

    public function test_respons_409_versi_berubah_pesan_ulangi_permintaan_dan_draf_tidak_dijalankan(): void
    {
        $this->llm->push(LlmResponse::toolCall('buat_pemasok', ['nama' => 'Tani Fiktif', 'jenis' => 'petani']))->push(LlmResponse::text('ok'));
        $this->chat($this->stafGudangA, 'tambah pemasok Tani Fiktif');
        $draft = $this->drafTerakhir();

        $this->app->instance(PermintaanInternal::class, new class($this->app) extends PermintaanInternal
        {
            public function kirim(User $pengguna, PersonalAccessToken $sesi, string $metode, string $uri, array $body = [], array $header = []): ResponsInternal
            {
                return new ResponsInternal(409, ['message' => 'Data sudah berubah (versi_tidak_cocok).']);
            }
        });

        $this->tekan($this->stafGudangA, $this->tombol($this->stafGudangA, BotAksiAiService::LABEL_LANJUT));

        $this->assertStringContainsString('Data berubah sejak draf dibuat', $this->teks($this->stafGudangA));
        $this->assertStringContainsString('ulangi permintaan', $this->teks($this->stafGudangA));
        $this->assertSame(StatusAiDraft::Gagal, $draft->fresh()->status);
        $this->assertSame(0, Pemasok::query()->withoutGlobalScope(SppgScope::class)->count());
    }
}

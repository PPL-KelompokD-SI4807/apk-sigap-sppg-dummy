<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Http\Middleware\IdempotencyKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * NFR-07: Idempotency-Key pada POST /pengguna. PenggunaResource tidak memuat rahasia sehingga aman di-cache;
 * POST /pengguna/undangan (respons memuat token mentah) sengaja tanpa middleware idempotensi.
 */
class PenggunaIdempotensiTest extends MasterApiTestCase
{
    private function payload(array $override = []): array
    {
        return array_merge(['name' => 'Siti Rahayu', 'role' => 'relawan', 'unit' => 'dapur', 'sppg_id' => $this->sppgA, 'phone' => '0812-0000-1234'], $override);
    }

    private function jumlahNama(string $nama): int
    {
        return User::query()->withoutGlobalScopes()->where('name', $nama)->count();
    }

    public function test_replay_kunci_dan_payload_sama_tidak_menggandakan_pengguna(): void
    {
        $this->as($this->admin);
        $kunci = (string) Str::uuid();

        $a = $this->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();
        $b = $this->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => $kunci])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json(), $b->json());
        $this->assertSame(1, $this->jumlahNama('Siti Rahayu'));
    }

    public function test_respons_dan_replay_tidak_memuat_rahasia(): void
    {
        $this->as($this->admin);
        $kunci = (string) Str::uuid();

        $this->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();
        $replay = $this->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => $kunci])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $replay->assertJsonPath('data.password_diatur', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.telegram_id')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.remember_token');
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]*(hash|token|secret|telegram_id)[a-z_]*"\s*:/i', (string) $replay->getContent());
    }

    public function test_kunci_sama_payload_beda_422_dan_tidak_membuat_data(): void
    {
        $this->as($this->admin);
        $kunci = (string) Str::uuid();

        $this->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();
        $this->postJson('/api/v1/pengguna', $this->payload(['name' => 'Budi Lain']), ['Idempotency-Key' => $kunci])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');

        $this->assertSame(0, $this->jumlahNama('Budi Lain'));
        $this->assertSame(1, $this->jumlahNama('Siti Rahayu'));
    }

    public function test_tanpa_kunci_tetap_berjalan_seperti_sebelumnya(): void
    {
        $this->as($this->admin);

        $this->postJson('/api/v1/pengguna', $this->payload())->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->postJson('/api/v1/pengguna', $this->payload(['phone' => '0812-0000-5678']))->assertCreated();

        $this->assertSame(2, $this->jumlahNama('Siti Rahayu'));
    }

    public function test_kunci_sama_pengguna_berbeda_tidak_berbagi_cache(): void
    {
        $kunci = (string) Str::uuid();
        $this->as($this->admin)->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();

        $admin2 = User::factory()->admin()->create();
        // Nomor telepon unik: payload berbeda; bila cache dibagi antarpengguna, hasilnya 422 (bukan 201).
        $this->as($admin2)->postJson('/api/v1/pengguna', $this->payload(['phone' => '0812-0000-9999']), ['Idempotency-Key' => $kunci])
            ->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, $this->jumlahNama('Siti Rahayu'));
    }

    public function test_tanpa_hak_tetap_403_dan_validasi_gagal_tidak_di_cache(): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        $kunci = (string) Str::uuid();
        $this->as($this->admin);
        $this->postJson('/api/v1/pengguna', [], ['Idempotency-Key' => $kunci])->assertUnprocessable()->assertJsonValidationErrors(['name', 'role']);
        $this->postJson('/api/v1/pengguna', $this->payload(), ['Idempotency-Key' => $kunci])->assertCreated();
    }

    public function test_rute_undangan_tidak_memakai_middleware_idempotensi(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertNotContains(IdempotencyKey::class, $routes->getByName('api.v1.pengguna.undangan.store')->gatherMiddleware());
        $this->assertContains(IdempotencyKey::class, $routes->getByName('api.v1.pengguna.store')->gatherMiddleware());
    }

    public function test_undangan_dengan_idempotency_key_tidak_pernah_di_replay_dan_tidak_masuk_cache(): void
    {
        $this->forgetActor();
        $akun = User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgA]);
        $this->as($this->admin);
        $kunci = (string) Str::uuid();

        $a = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id], ['Idempotency-Key' => $kunci])->assertCreated();
        $b = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id], ['Idempotency-Key' => $kunci])->assertCreated();

        // Perilaku: tidak ada replay, tiap panggilan menerbitkan token baru (respons tidak pernah di-cache).
        $this->assertFalse($b->headers->has('Idempotent-Replayed'));
        $this->assertNotEmpty($a->json('data.token'));
        $this->assertNotEmpty($b->json('data.token'));
        $this->assertNotSame($a->json('data.token'), $b->json('data.token'));
    }
}

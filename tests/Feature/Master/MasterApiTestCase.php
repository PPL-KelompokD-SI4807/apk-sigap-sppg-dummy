<?php

namespace Tests\Feature\Master;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * Dasar tes modul data master (pengguna, SPPG, titik layanan, penerima manfaat, armada, pemasok).
 * Fixture dibuat tanpa pengguna login; as() mengganti pengguna login sebelum tiap permintaan.
 */
abstract class MasterApiTestCase extends TestCase
{
    use CreatesSppg, RefreshDatabase;

    protected int $sppgA;

    protected int $sppgB;

    protected User $admin;

    protected User $kepalaA;

    protected User $kepalaB;

    protected User $ahliGiziA;

    protected User $akuntanA;

    protected User $stafGudangA;

    protected User $relawanA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');

        $this->admin = User::factory()->admin()->create();
        $this->kepalaA = $this->userOf(Role::KepalaSppg, $this->sppgA);
        $this->kepalaB = $this->userOf(Role::KepalaSppg, $this->sppgB);
        $this->ahliGiziA = $this->userOf(Role::AhliGizi, $this->sppgA);
        $this->akuntanA = $this->userOf(Role::Akuntan, $this->sppgA);
        $this->stafGudangA = $this->userOf(Role::StafGudang, $this->sppgA);
        $this->relawanA = $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
    }

    protected function userOf(Role $role, int $sppgId, ?RelawanUnit $unit = null): User
    {
        return User::factory()->role($role, $unit)->create(['sppg_id' => $sppgId]);
    }

    /** Login sebagai $user untuk permintaan berikutnya (guard dibersihkan agar tidak ada sisa pengguna lama). */
    protected function as(User $user): static
    {
        $this->forgetActor();
        $this->masukPenuh($user);

        return $this;
    }

    /** Hapus pengguna login (mis. sebelum membuat fixture atau membaca model tanpa scope tenant). */
    protected function forgetActor(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** @return list<Role> peran yang tidak boleh menulis modul. */
    protected function everyRoleExcept(Role ...$allowed): array
    {
        return array_values(array_filter(Role::cases(), fn (Role $r) => ! in_array($r, $allowed, true)));
    }
}

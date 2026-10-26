<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Sppg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dasar tes modul PBI 6, 8, 9, 10, 11: dua SPPG, pembuat pengguna per peran, login Sanctum.
 * Buat seluruh fixture SEBELUM login (BelongsToSppg memaksa sppg_id = SPPG pengguna login).
 */
abstract class GrupBTestCase extends TestCase
{
    use RefreshDatabase;

    protected Sppg $sppgA;

    protected Sppg $sppgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sppgA = Sppg::factory()->create(['kapasitas_porsi_harian' => 3000]);
        $this->sppgB = Sppg::factory()->create(['kapasitas_porsi_harian' => 3000]);
    }

    protected function user(Role $role, ?Sppg $sppg = null, ?RelawanUnit $unit = null): User
    {
        return User::factory()->role($role, $unit)->create([
            'sppg_id' => $role === Role::Admin ? null : ($sppg ?? $this->sppgA)->id,
        ]);
    }

    protected function login(Role $role, ?Sppg $sppg = null, ?RelawanUnit $unit = null): User
    {
        $user = $this->user($role, $sppg, $unit);
        $this->masukPenuh($user);

        return $user;
    }
}

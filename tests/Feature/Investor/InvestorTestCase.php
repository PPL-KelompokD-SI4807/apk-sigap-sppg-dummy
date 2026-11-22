<?php

namespace Tests\Feature\Investor;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Master\MasterApiTestCase;

/**
 * Dasar tes R-06a. Investor AB memiliki SPPG A dan B; investor C memiliki SPPG C (milik
 * investor lain). Semua data fiktif.
 */
abstract class InvestorTestCase extends MasterApiTestCase
{
    protected int $sppgC;

    protected User $investorAB;

    protected User $investorC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sppgC = $this->createSppg('SPPG C');
        $this->investorAB = $this->investor([$this->sppgA, $this->sppgB]);
        $this->investorC = $this->investor([$this->sppgC]);
    }

    /** @param list<int> $sppgIds */
    protected function investor(array $sppgIds): User
    {
        $user = User::factory()->role(Role::Investor)->create(['sppg_id' => null]);

        foreach ($sppgIds as $id) {
            DB::table('investor_sppg')->insert(['user_id' => $user->id, 'sppg_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    /** Login penuh sebagai $user dengan header SPPG aktif (null = tanpa header). */
    protected function sebagai(User $user, int|string|null $sppgAktif = null): static
    {
        $this->flushHeaders();
        $this->as($user);

        if ($sppgAktif !== null) {
            $this->withHeader('X-SPPG-Id', (string) $sppgAktif);
        }

        return $this;
    }

    protected function assertAlasan(TestResponse $response, int $status, string $alasan): TestResponse
    {
        return $response->assertStatus($status)->assertJsonPath('alasan', $alasan);
    }
}

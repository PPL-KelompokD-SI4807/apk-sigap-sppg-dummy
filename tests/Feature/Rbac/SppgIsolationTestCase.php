<?php

namespace Tests\Feature\Rbac;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\RbacTestItem;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

abstract class SppgIsolationTestCase extends TestCase
{
    use CreatesSppg, RefreshDatabase;

    protected int $sppgA;

    protected int $sppgB;

    protected function setUp(): void
    {
        parent::setUp();
        RbacTestItem::createTable();
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
    }

    protected function makeUser(Role $role, ?int $sppgId, ?RelawanUnit $unit = null): User
    {
        return User::factory()->role($role, $unit)->create(['sppg_id' => $role === Role::Admin ? null : $sppgId]);
    }

    /** Buat item tanpa pengguna login (seperti seeder/console). */
    protected function item(int $sppgId, array $attributes = []): RbacTestItem
    {
        return RbacTestItem::query()->create(array_merge(['sppg_id' => $sppgId, 'nama' => 'Beras Premium'], $attributes));
    }
}

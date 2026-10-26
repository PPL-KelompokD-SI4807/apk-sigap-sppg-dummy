<?php

namespace Tests\Feature\Rbac;

use App\Enums\Role;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Tests\Fixtures\RbacTestItem;

class SppgScopeTest extends SppgIsolationTestCase
{
    public function test_pengguna_sppg_a_tidak_melihat_data_sppg_b(): void
    {
        $a = $this->item($this->sppgA, ['nama' => 'Milik A']);
        $b = $this->item($this->sppgB, ['nama' => 'Milik B']);

        $this->actingAs($this->makeUser(Role::StafGudang, $this->sppgA));

        $this->assertSame([$a->id], RbacTestItem::query()->pluck('id')->all());
        $this->assertNull(RbacTestItem::query()->find($b->id));
        $this->assertSame(0, RbacTestItem::query()->where('nama', 'Milik B')->count());
        $this->assertSame(0, RbacTestItem::query()->orWhere('sppg_id', $this->sppgB)->count(), 'orWhere tidak menembus scope');
        $this->assertSame(1, RbacTestItem::query()->where('id', '>', 0)->orWhere('sppg_id', $this->sppgB)->count(), 'OR dibungkus scope');
    }

    public function test_admin_dan_console_tanpa_login_melihat_semua(): void
    {
        $this->item($this->sppgA);
        $this->item($this->sppgB);

        $this->assertSame(2, RbacTestItem::query()->count());
        $this->assertSame(1, RbacTestItem::query()->ofSppg($this->sppgB)->count());

        $this->actingAs($this->makeUser(Role::Admin, null));
        $this->assertSame(2, RbacTestItem::query()->count());
    }

    public function test_creating_memaksa_sppg_pengguna_non_admin(): void
    {
        $this->actingAs($this->makeUser(Role::StafGudang, $this->sppgA));

        $item = RbacTestItem::query()->create(['nama' => 'Coba', 'sppg_id' => $this->sppgB]);
        $tanpa = RbacTestItem::query()->create(['nama' => 'Tanpa sppg']);

        $this->assertSame($this->sppgA, $item->sppg_id);
        $this->assertSame($this->sppgA, $tanpa->sppg_id);
    }

    public function test_non_admin_tanpa_sppg_tidak_melihat_apa_pun(): void
    {
        $this->item($this->sppgA);
        $this->actingAs(User::factory()->role(Role::Akuntan)->create(['sppg_id' => null]));

        $this->assertSame(0, RbacTestItem::query()->count());
    }

    public function test_model_user_juga_terisolasi(): void
    {
        $kepalaA = $this->makeUser(Role::KepalaSppg, $this->sppgA);
        $this->makeUser(Role::Akuntan, $this->sppgB);
        $this->makeUser(Role::Admin, null);

        $this->actingAs($kepalaA);

        $this->assertSame([$kepalaA->id], User::query()->pluck('id')->all());
        $this->assertSame($kepalaA->id, SppgScope::currentUser()?->id);
    }
}

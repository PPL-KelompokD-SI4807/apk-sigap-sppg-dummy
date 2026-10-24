<?php

namespace Tests\Feature\Rbac;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\BahanBakuLikePolicy;
use Tests\Fixtures\JadwalDistribusiLikePolicy;
use Tests\Fixtures\JadwalProduksiLikePolicy;
use Tests\Fixtures\LogProduksiLikePolicy;
use Tests\Fixtures\RbacTestItem;

class BaseSppgPolicyTest extends SppgIsolationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::policy(RbacTestItem::class, BahanBakuLikePolicy::class);

        // Rute uji meniru controller modul: route model binding + authorize.
        Route::middleware(['api', 'auth:sanctum', 'active'])->prefix('api/v1/_uji')->group(function () {
            Route::get('items', function () {
                Gate::authorize('viewAny', RbacTestItem::class);

                return ['data' => RbacTestItem::query()->orderBy('id')->pluck('id')];
            });
            Route::post('items', function (Request $request) {
                Gate::authorize('create', RbacTestItem::class);

                return response()->json(['data' => RbacTestItem::query()->create($request->only('nama', 'sppg_id'))], 201);
            });
            Route::get('items/{item}', function (RbacTestItem $item) {
                Gate::authorize('view', $item);

                return ['data' => $item];
            });
            Route::patch('items/{item}', function (Request $request, RbacTestItem $item) {
                Gate::authorize('update', $item);
                $item->update($request->only('nama', 'sppg_id'));

                return ['data' => $item];
            });
            Route::delete('items/{item}', function (RbacTestItem $item) {
                Gate::authorize('delete', $item);
                $item->delete();

                return response()->noContent();
            });
        });
    }

    public function test_peran_dengan_crud_jalur_sukses(): void
    {
        $item = $this->item($this->sppgA);
        Sanctum::actingAs($this->makeUser(Role::StafGudang, $this->sppgA));

        $this->getJson('/api/v1/_uji/items')->assertOk()->assertJsonPath('data', [$item->id]);
        $this->getJson("/api/v1/_uji/items/{$item->id}")->assertOk();
        $created = $this->postJson('/api/v1/_uji/items', ['nama' => 'Telur', 'sppg_id' => $this->sppgB])->assertCreated();
        $this->assertSame($this->sppgA, $created->json('data.sppg_id'), 'sppg_id body diabaikan untuk non-admin');
        $this->patchJson("/api/v1/_uji/items/{$item->id}", ['nama' => 'Beras Medium'])->assertOk();
        $this->deleteJson("/api/v1/_uji/items/{$item->id}")->assertNoContent();
        $this->assertSoftDeleted($item);
    }

    public function test_peran_hanya_baca_ditolak_menulis_403(): void
    {
        $item = $this->item($this->sppgA);
        Sanctum::actingAs($this->makeUser(Role::KepalaSppg, $this->sppgA));

        $this->getJson("/api/v1/_uji/items/{$item->id}")->assertOk();
        $this->postJson('/api/v1/_uji/items', ['nama' => 'X'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Anda tidak memiliki hak akses untuk tindakan ini.']);
        $this->patchJson("/api/v1/_uji/items/{$item->id}", ['nama' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/_uji/items/{$item->id}")->assertForbidden();
    }

    public function test_peran_tanpa_hak_ditolak_membaca_403(): void
    {
        $item = $this->item($this->sppgA);
        Sanctum::actingAs($this->makeUser(Role::Relawan, $this->sppgA, RelawanUnit::Dapur));

        $this->getJson('/api/v1/_uji/items')->assertForbidden();
        $this->getJson("/api/v1/_uji/items/{$item->id}")->assertForbidden();
    }

    public function test_lintas_sppg_tidak_terlihat_404_dan_policy_menolak(): void
    {
        $itemB = $this->item($this->sppgB);
        $userA = $this->makeUser(Role::StafGudang, $this->sppgA);
        Sanctum::actingAs($userA);

        $this->getJson("/api/v1/_uji/items/{$itemB->id}")->assertNotFound()->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->patchJson("/api/v1/_uji/items/{$itemB->id}", ['nama' => 'Retas'])->assertNotFound();
        $this->deleteJson("/api/v1/_uji/items/{$itemB->id}")->assertNotFound();
        $this->getJson('/api/v1/_uji/items')->assertOk()->assertJsonPath('data', []);

        // Policy tetap menolak walau scope dilewati (mis. query withoutGlobalScopes).
        $policy = new BahanBakuLikePolicy;
        foreach (['view', 'update', 'delete', 'restore'] as $ability) {
            $this->assertFalse($policy->{$ability}($userA, $itemB), $ability);
        }
        $this->assertSame('Beras Premium', $itemB->fresh()->nama);
    }

    public function test_token_asli_scope_aktif_saat_route_model_binding(): void
    {
        $itemA = $this->item($this->sppgA);
        $itemB = $this->item($this->sppgB);
        $token = $this->makeUser(Role::StafGudang, $this->sppgA)->createToken('miniapp:miniapp')->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/_uji/items/{$itemA->id}")->assertOk();
        $this->withToken($token)->getJson("/api/v1/_uji/items/{$itemB->id}")->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/_uji/items')->assertOk()->assertJsonPath('data', [$itemA->id]);
    }

    public function test_admin_lintas_sppg(): void
    {
        $itemA = $this->item($this->sppgA);
        $itemB = $this->item($this->sppgB);
        Sanctum::actingAs($this->makeUser(Role::Admin, null));

        $this->getJson('/api/v1/_uji/items')->assertOk()->assertJsonPath('data', [$itemA->id, $itemB->id]);
        $this->patchJson("/api/v1/_uji/items/{$itemB->id}", ['nama' => 'Dikoreksi'])->assertOk();
        $this->postJson('/api/v1/_uji/items', ['nama' => 'Baru', 'sppg_id' => $this->sppgB])
            ->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB);
    }

    public function test_non_admin_tidak_bisa_memindahkan_data_ke_sppg_lain(): void
    {
        $item = $this->item($this->sppgA);
        Sanctum::actingAs($this->makeUser(Role::StafGudang, $this->sppgA));

        $this->patchJson("/api/v1/_uji/items/{$item->id}", ['nama' => 'Pindah', 'sppg_id' => $this->sppgB])->assertOk();

        $this->assertSame($this->sppgA, RbacTestItem::withoutGlobalScopes()->find($item->id)->sppg_id);
    }

    public function test_force_delete_selalu_ditolak(): void
    {
        $item = $this->item($this->sppgA);

        $this->assertFalse((new BahanBakuLikePolicy)->forceDelete($this->makeUser(Role::Admin, null), $item));
    }

    public function test_relawan_hanya_mengubah_data_sendiri_hari_yang_sama(): void
    {
        $policy = new LogProduksiLikePolicy;
        $dapur = $this->makeUser(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $rekan = $this->makeUser(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $kepala = $this->makeUser(Role::KepalaSppg, $this->sppgA);

        $milikSendiri = $this->item($this->sppgA, ['created_by' => $dapur->id]);
        $milikRekan = $this->item($this->sppgA, ['created_by' => $rekan->id]);

        $this->assertTrue($policy->create($dapur));
        $this->assertTrue($policy->update($dapur, $milikSendiri));
        $this->assertFalse($policy->update($dapur, $milikRekan));
        $this->assertFalse($policy->delete($dapur, $milikSendiri), 'relawan tidak punya D');
        $this->assertTrue($policy->update($kepala, $milikRekan));

        $this->travel(1)->days();
        $this->assertFalse($policy->update($dapur, $milikSendiri->fresh()), 'hari berikutnya ditolak');
        $this->assertTrue($policy->update($kepala, $milikSendiri->fresh()));

        $distribusi = $this->makeUser(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $this->assertFalse($policy->viewAny($distribusi));
        $this->assertFalse($policy->create($distribusi));
    }

    public function test_relawan_jadwal_sesuai_unit(): void
    {
        $produksi = new JadwalProduksiLikePolicy;
        $distribusiPolicy = new JadwalDistribusiLikePolicy;
        $dapur = $this->makeUser(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $kurir = $this->makeUser(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $gizi = $this->makeUser(Role::AhliGizi, $this->sppgA);

        $this->assertTrue($produksi->create($dapur));
        $this->assertFalse($distribusiPolicy->create($dapur));
        $this->assertTrue($distribusiPolicy->create($kurir));
        $this->assertFalse($produksi->create($kurir));
        $this->assertTrue($produksi->create($gizi) && $distribusiPolicy->create($gizi));
        $this->assertFalse($produksi->delete($gizi, $this->item($this->sppgA)));
    }

    public function test_user_policy_r_bintang_dan_kepala_sppg(): void
    {
        $policy = new UserPolicy;
        $akuntan = $this->makeUser(Role::Akuntan, $this->sppgA);
        $rekan = $this->makeUser(Role::StafGudang, $this->sppgA);
        $kepala = $this->makeUser(Role::KepalaSppg, $this->sppgA);
        $kepalaB = $this->makeUser(Role::KepalaSppg, $this->sppgB);
        $admin = $this->makeUser(Role::Admin, null);

        $this->assertFalse($policy->viewAny($akuntan));
        $this->assertTrue($policy->view($akuntan, $akuntan));
        $this->assertFalse($policy->view($akuntan, $rekan));
        $this->assertFalse($policy->update($akuntan, $akuntan));

        $this->assertTrue($policy->viewAny($kepala));
        $this->assertTrue($policy->view($kepala, $rekan));
        $this->assertFalse($policy->view($kepala, $kepalaB));
        $this->assertFalse($policy->view($kepala, $admin));
        $this->assertFalse($policy->create($kepala));

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->update($admin, $kepalaB));
        $this->assertTrue($policy->delete($admin, $kepalaB));

        // Penemuan otomatis App\Models\User -> App\Policies\UserPolicy.
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
    }

    public function test_non_admin_tanpa_sppg_ditolak_semua(): void
    {
        $item = $this->item($this->sppgA);
        $user = User::factory()->role(Role::StafGudang)->create(['sppg_id' => null]);

        $this->assertFalse((new BahanBakuLikePolicy)->view($user, $item));
    }
}

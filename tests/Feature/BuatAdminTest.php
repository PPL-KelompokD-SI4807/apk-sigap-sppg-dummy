<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuatAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_membuat_admin_lintas_sppg_tanpa_data_lain(): void
    {
        $this->artisan('sigap:buat-admin', ['telegram_id' => '7012345678', 'nama' => 'Admin Fiktif'])
            ->expectsOutput('Admin dibuat.')
            ->assertSuccessful();

        $admin = User::query()->where('telegram_id', 7012345678)->sole();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertSame('Admin Fiktif', $admin->name);
        $this->assertNull($admin->sppg_id);
        $this->assertNull($admin->phone);
        $this->assertTrue($admin->is_active);
    }

    public function test_idempoten_dijalankan_dua_kali_tidak_menggandakan_atau_mengubah(): void
    {
        $this->artisan('sigap:buat-admin', ['telegram_id' => '7012345678', 'nama' => 'Admin Fiktif'])->assertSuccessful();
        $this->artisan('sigap:buat-admin', ['telegram_id' => '7012345678', 'nama' => 'Nama Lain'])
            ->expectsOutput('Admin sudah ada; tidak ada perubahan.')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame('Admin Fiktif', User::query()->sole()->name);
    }

    public function test_tidak_menaikkan_atau_memulihkan_akun_yang_sudah_ada(): void
    {
        $biasa = User::factory()->role(Role::KepalaSppg)->create(['telegram_id' => 111]);
        $nonaktif = User::factory()->role(Role::Admin)->create(['telegram_id' => 222, 'is_active' => false]);
        $terhapus = User::factory()->role(Role::Admin)->create(['telegram_id' => 333]);
        $terhapus->delete();

        foreach ([111, 222, 333] as $id) {
            $this->artisan('sigap:buat-admin', ['telegram_id' => (string) $id, 'nama' => 'Admin Fiktif'])->assertFailed();
        }

        $this->assertSame(Role::KepalaSppg, $biasa->fresh()->role);
        $this->assertFalse($nonaktif->fresh()->is_active);
        $this->assertTrue(User::withTrashed()->find($terhapus->id)->trashed());
    }

    public function test_masukan_tidak_valid_ditolak(): void
    {
        foreach (['0', '-5', 'abc', '12.5', '', '1234567890123456789'] as $id) {
            $this->artisan('sigap:buat-admin', ['telegram_id' => $id, 'nama' => 'Admin Fiktif'])->assertFailed();
        }

        $this->artisan('sigap:buat-admin', ['telegram_id' => '5', 'nama' => '  '])->assertFailed();
        $this->artisan('sigap:buat-admin', ['telegram_id' => '5', 'nama' => str_repeat('a', 121)])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}

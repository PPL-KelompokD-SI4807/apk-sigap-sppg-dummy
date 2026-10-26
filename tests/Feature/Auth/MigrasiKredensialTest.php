<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi R-03 aditif & aman terhadap data lama: no telepon hanya dinormalkan bila unik,
 * nomor ganda/tak dikenali tidak disentuh, indeks unik dilewati bila masih ada duplikat.
 */
class MigrasiKredensialTest extends TestCase
{
    use RefreshDatabase;

    private function migrasi(): Migration
    {
        return require database_path('migrations/2026_10_26_000101_add_kredensial_to_users_table.php');
    }

    private function userLama(string $nama, ?string $phone): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $nama, 'role' => 'admin', 'phone' => $phone, 'is_active' => true,
            'telegram_id' => random_int(100_000_000, 999_999_999), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_up_aman_untuk_nomor_ganda_dan_tak_dikenali(): void
    {
        $migrasi = $this->migrasi();
        $migrasi->down();
        $this->assertFalse(Schema::hasColumn('users', 'username'));

        // a & b persis sama; c nomor yang sama dalam format lain (bentrok setelah normalisasi).
        $a = $this->userLama('Admin A Fiktif', '0812-1111-2222');
        $b = $this->userLama('Admin B Fiktif', '0812-1111-2222');
        $c = $this->userLama('Admin C Fiktif', '+62 812 1111 2222');
        $d = $this->userLama('Admin D Fiktif', '0813 3333 4444');
        $e = $this->userLama('Admin E Fiktif', '(021) 555');
        $f = $this->userLama('Admin F Fiktif', null);

        $migrasi->up();

        $phone = fn (int $id) => DB::table('users')->where('id', $id)->value('phone');
        $this->assertSame('0812-1111-2222', $phone($a), 'Nomor bentrok tidak disentuh.');
        $this->assertSame('0812-1111-2222', $phone($b));
        $this->assertSame('+62 812 1111 2222', $phone($c));
        $this->assertSame('+6281333334444', $phone($d));
        $this->assertSame('(021) 555', $phone($e), 'Nomor tak dikenali tidak disentuh.');
        $this->assertNull($phone($f));
        $this->assertSame(6, DB::table('users')->whereNull('password')->whereNull('username')->where('failed_login_attempts', 0)->count());
        $this->assertFalse(Schema::hasIndex('users', 'users_phone_unique'), 'Indeks unik dilewati selama ada duplikat.');
        $this->assertTrue(Schema::hasColumns('users', ['username', 'password', 'password_changed_at', 'failed_login_attempts', 'locked_until']));
    }

    public function test_up_membuat_indeks_unik_bila_tidak_ada_duplikat(): void
    {
        $migrasi = $this->migrasi();
        $migrasi->down();

        $a = $this->userLama('Admin A Fiktif', '0812-1111-2222');
        $this->userLama('Admin B Fiktif', '0813 3333 4444');

        $migrasi->up();

        $this->assertTrue(Schema::hasIndex('users', 'users_phone_unique'));
        $this->assertSame('+6281211112222', DB::table('users')->where('id', $a)->value('phone'));
    }
}

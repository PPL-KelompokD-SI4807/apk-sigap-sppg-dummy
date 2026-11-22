<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Migrasi indeks R-07 aman: hanya indeks, idempoten, dapat dibalik, dan tidak menyentuh data. */
class DashboardMigrasiIndeksTest extends TestCase
{
    use RefreshDatabase;

    public function test_indeks_ditambah_idempoten_dan_dapat_dibalik_tanpa_menyentuh_data(): void
    {
        $migrasi = require database_path('migrations/2026_10_29_000001_add_indeks_dashboard.php');
        DB::table('telegram_updates')->insert(['update_id' => 77, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue(Schema::hasIndex('audit_logs', 'audit_logs_event_created_at_index'));
        $this->assertTrue(Schema::hasIndex('telegram_updates', 'telegram_updates_processed_created_index'));

        $migrasi->up(); // idempoten: tidak error walau indeks sudah ada

        $migrasi->down();
        $this->assertFalse(Schema::hasIndex('audit_logs', 'audit_logs_event_created_at_index'));
        $this->assertFalse(Schema::hasIndex('telegram_updates', 'telegram_updates_processed_created_index'));
        $this->assertSame(1, DB::table('telegram_updates')->count());

        $migrasi->up();
        $this->assertTrue(Schema::hasIndex('audit_logs', 'audit_logs_event_created_at_index'));
        $this->assertSame(1, DB::table('telegram_updates')->count());
    }
}

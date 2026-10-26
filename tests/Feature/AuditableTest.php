<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use LogicException;
use Tests\Feature\Rbac\SppgIsolationTestCase;
use Tests\Fixtures\RbacTestItem;

class AuditableTest extends SppgIsolationTestCase
{
    public function test_created_updated_deleted_restored_tercatat_tanpa_field_sensitif(): void
    {
        $user = $this->makeUser(Role::StafGudang, $this->sppgA);
        $this->actingAs($user);

        $item = RbacTestItem::query()->create(['nama' => 'Minyak', 'secret' => 'jangan-dicatat', 'catatan_rahasia' => 'tersembunyi']);
        $item->update(['nama' => 'Minyak Goreng', 'secret' => 'baru']);
        $item->delete();
        $item->restore();

        $logs = AuditLog::query()->where('auditable_type', $item->getMorphClass())->orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'deleted', 'restored'], $logs->pluck('event')->all());
        $this->assertSame($this->sppgA, $logs[0]->sppg_id);
        $this->assertSame($user->id, $logs[0]->user_id);
        $this->assertSame('Minyak', $logs[0]->new_values['nama']);
        $this->assertSame(['nama' => 'Minyak'], $logs[1]->old_values);
        $this->assertSame(['nama' => 'Minyak Goreng'], $logs[1]->new_values);

        $json = $logs->toJson();
        $this->assertStringNotContainsString('jangan-dicatat', $json);
        $this->assertStringNotContainsString('tersembunyi', $json);
        $this->assertStringNotContainsString('"secret"', $json);
    }

    public function test_perubahan_hanya_field_sensitif_tidak_membuat_log_updated(): void
    {
        $item = $this->item($this->sppgA);
        $item->update(['secret' => 'x']);

        $this->assertSame(0, AuditLog::query()->where('event', 'updated')->count());
    }

    public function test_audit_tanpa_login_bersumber_sistem_dan_bisa_diberi_sumber_ai(): void
    {
        $item = $this->item($this->sppgA);
        $this->assertNull(AuditLog::query()->first()->user_id);

        AuditLog::withSource('ai', fn () => $item->update(['nama' => 'Dari draf AI']));

        $this->assertSame('ai', AuditLog::query()->where('event', 'updated')->first()->sumber);
    }

    public function test_perubahan_user_tercatat(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->sppgA]);
        $user->update(['role' => Role::Akuntan]);

        $log = AuditLog::query()->where('auditable_type', $user->getMorphClass())->where('event', 'updated')->first();
        $this->assertSame(['role' => 'akuntan'], $log->new_values);
    }

    public function test_log_audit_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->item($this->sppgA);
        $log = AuditLog::query()->first();

        try {
            $log->forceFill(['event' => 'palsu'])->save();
            $this->fail('Log audit seharusnya tidak bisa diubah.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_log_audit_terisolasi_per_sppg(): void
    {
        $this->item($this->sppgA);
        $this->item($this->sppgB);

        $this->actingAs($this->makeUser(Role::KepalaSppg, $this->sppgA));

        $this->assertSame([$this->sppgA], AuditLog::query()->pluck('sppg_id')->unique()->values()->all());
    }
}

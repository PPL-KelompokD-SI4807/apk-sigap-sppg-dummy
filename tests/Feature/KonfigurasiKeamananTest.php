<?php

namespace Tests\Feature;

use App\Services\Users\InvitationService;
use Tests\TestCase;

class KonfigurasiKeamananTest extends TestCase
{
    public function test_disk_local_tidak_disajikan_dan_disk_uploads_privat(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertNull(config('filesystems.disks.uploads.url'));
        $this->assertNotTrue(config('filesystems.disks.uploads.serve'));
        $this->assertSame('private', config('filesystems.disks.uploads.visibility'));
    }

    public function test_undangan_berlaku_72_jam_sesuai_proposal(): void
    {
        $this->assertSame(72, InvitationService::TTL_HOURS);
    }
}

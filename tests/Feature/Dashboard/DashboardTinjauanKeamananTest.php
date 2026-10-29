<?php

namespace Tests\Feature\Dashboard;

use App\Services\Dashboard\DashboardService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** Temuan tinjauan keamanan R-07: log percobaan SPPG asing dan kunci cache memuat mode cakupan. */
class DashboardTinjauanKeamananTest extends DashboardTestCase
{
    public function test_sppg_asing_dicatat_log_notice_dengan_user_dan_nilai_terpotong(): void
    {
        $log = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$log): void {
            if ($e->message === 'sppg_aktif.ditolak') {
                $log[] = [$e->level, $e->context];
            }
        });

        // Peran satu-SPPG memilih SPPG lain lewat query (jalur yang melewati middleware header).
        $this->sebagai($this->kepalaA)->getJson(self::URL.'?sppg_id='.$this->sppgB)->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');
        $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$this->sppgC)->assertForbidden();

        $dariService = array_values(array_filter($log, fn (array $l) => array_key_exists('sppg_id', $l[1])));
        $this->assertCount(2, $dariService);

        foreach ($dariService as [$level, $konteks]) {
            $this->assertSame('notice', $level);
            $this->assertLessThanOrEqual(20, strlen((string) $konteks['sppg_id']));
        }

        $this->assertSame($this->kepalaA->id, $dariService[0][1]['user_id']);
        $this->assertSame((string) $this->sppgB, $dariService[0][1]['sppg_id']);
        $this->assertSame($this->investorAB->id, $dariService[1][1]['user_id']);
        $this->assertSame((string) $this->sppgC, $dariService[1][1]['sppg_id']);
    }

    public function test_kunci_cache_memuat_mode_cakupan(): void
    {
        // Hanya SPPG A yang tersisa: Admin tanpa pilihan = gabungan [A], Admin memilih A = satu [A] (himpunan id sama).
        DB::table('sppgs')->where('id', '!=', $this->sppgA)->update(['deleted_at' => now()]);
        $service = app(DashboardService::class);

        $gabungan = $service->bangun($this->admin, null);
        $satu = $service->bangun($this->admin, $this->sppgA);

        $this->assertSame('gabungan', $gabungan['mode']);
        $this->assertSame('satu', $satu['mode'], 'mode tidak boleh terbawa dari hasil cache cakupan dengan himpunan SPPG yang sama');
    }
}

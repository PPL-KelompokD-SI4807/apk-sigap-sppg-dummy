<?php

namespace Tests\Feature\Investor;

use App\Models\AuditLog;
use App\Services\Users\KepemilikanSppgService;
use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * R06a-S-01: tautkan()/lepas() menghitung himpunan target DI DALAM transaksi setelah baris investor
 * dikunci. Perubahan "bersamaan" disimulasikan dengan menulis pivot tepat saat transaksi layanan
 * dimulai (sebelum lockForUpdate), yaitu urutan yang membuat versi lama menimpa perubahan Admin lain.
 */
class KepemilikanBalapanTest extends InvestorTestCase
{
    private function sebelumKunci(Closure $perubahanLain): void
    {
        $sudah = false;

        Event::listen(TransactionBeginning::class, function () use (&$sudah, $perubahanLain): void {
            if (! $sudah) {
                $sudah = true;
                $perubahanLain();
            }
        });
    }

    /** @return list<int> */
    private function milik(): array
    {
        return DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->orderBy('sppg_id')
            ->pluck('sppg_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_lepas_bersamaan_tidak_dihidupkan_kembali_oleh_tautkan(): void
    {
        // Admin lain melepas SPPG B tepat sebelum tautkan(C) memperoleh kunci.
        $this->sebelumKunci(fn () => DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgB)->delete());

        $hasil = app(KepemilikanSppgService::class)->tautkan($this->admin, $this->investorAB, $this->sppgC);

        $this->assertSame([$this->sppgA, $this->sppgC], $this->milik(), 'SPPG B yang sudah dicabut tidak boleh hidup kembali.');
        $this->assertEqualsCanonicalizing([$this->sppgA, $this->sppgC], array_column($hasil, 'id'));

        $this->forgetActor();
        $audit = AuditLog::query()->where('event', KepemilikanSppgService::AUDIT_DIUBAH)->sole();
        $this->assertSame([$this->sppgA], $audit->new_values['sebelum']);
        $this->assertSame([$this->sppgC], $audit->new_values['ditambah']);
        $this->assertSame([], $audit->new_values['dihapus']);
    }

    public function test_tautkan_bersamaan_tidak_dihapus_oleh_lepas(): void
    {
        // Admin lain menautkan SPPG C tepat sebelum lepas(A) memperoleh kunci.
        $this->sebelumKunci(fn () => DB::table('investor_sppg')->insert([
            'user_id' => $this->investorAB->id, 'sppg_id' => $this->sppgC, 'created_at' => now(), 'updated_at' => now(),
        ]));

        app(KepemilikanSppgService::class)->lepas($this->admin, $this->investorAB, $this->sppgA);

        $this->assertSame([$this->sppgB, $this->sppgC], $this->milik(), 'SPPG C yang baru ditautkan tidak boleh ikut terhapus.');

        $this->forgetActor();
        $audit = AuditLog::query()->where('event', KepemilikanSppgService::AUDIT_DIUBAH)->sole();
        $this->assertSame([$this->sppgA, $this->sppgB, $this->sppgC], $audit->new_values['sebelum']);
        $this->assertSame([$this->sppgA], $audit->new_values['dihapus']);
        $this->assertSame([], $audit->new_values['ditambah']);
    }

    public function test_closure_target_menerima_himpunan_yang_dibaca_setelah_kunci(): void
    {
        $this->sebelumKunci(fn () => DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->delete());
        $dilihat = null;

        app(KepemilikanSppgService::class)->atur($this->admin, $this->investorAB, function (array $lama) use (&$dilihat): array {
            $dilihat = $lama;

            return [...$lama, $this->sppgC];
        });

        $this->assertSame([], $dilihat);
        $this->assertSame([$this->sppgC], $this->milik());
    }

    public function test_tautkan_lepas_berurutan_tetap_idempoten(): void
    {
        $layanan = app(KepemilikanSppgService::class);

        $layanan->tautkan($this->admin, $this->investorAB, $this->sppgA);
        $layanan->lepas($this->admin, $this->investorAB, $this->sppgC);
        $this->assertSame([$this->sppgA, $this->sppgB], $this->milik());

        $this->forgetActor();
        $this->assertSame(0, AuditLog::query()->where('event', KepemilikanSppgService::AUDIT_DIUBAH)->count());
    }
}

<?php

namespace Tests\Feature\Pembelian;

use App\Enums\Role;
use App\Events\AnggaranMelampaui;
use App\Events\AnggaranMencapai100;
use App\Events\AnggaranMencapai80;
use App\Events\KeluhanKritis;
use App\Events\PengeluaranDiajukan;
use App\Events\StokMenipis;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Notification\DomainEventNotifications;
use Illuminate\Http\Client\Request;

/** Notifikasi bot ke investor (R-06b-2): hanya pengeluaran diajukan, anggaran 80/100%, keluhan kritis (+ menu diajukan lama). */
class NotifikasiInvestorTest extends PembelianTestCase
{
    /** @return list<int> chat id penerima sendMessage, terurut */
    private function penerima(): array
    {
        return $this->telegramCalls('sendMessage')->map(fn (Request $r) => (int) $r['chat_id'])->sort()->values()->all();
    }

    private function chat(User $u): int
    {
        return (int) $u->telegram_id;
    }

    private function diajukan(int $sppgId, int $id = 41, int $versi = 1): PengeluaranDiajukan
    {
        return new PengeluaranDiajukan($id, $sppgId, $versi, 'energi', 750_000, '2026-11-10', '2026-11', 10_000_000, 2_500_000);
    }

    public function test_pengeluaran_diajukan_hanya_ke_investor_pemilik_tanpa_uraian(): void
    {
        event($this->diajukan($this->sppgA));

        $this->assertSame([$this->chat($this->investorAB)], $this->penerima());
        $pesan = $this->telegramCalls('sendMessage')->sole();
        $this->assertStringContainsString('Pengeluaran menunggu persetujuan', $pesan['text']);
        $this->assertStringContainsString('Energi', $pesan['text']);
        $this->assertStringContainsString('750.000', $pesan['text']);
        $this->assertStringContainsString('serapan 25%', $pesan['text']);
        $this->assertStringContainsString('sisa Rp 7.500.000', $pesan['text']);

        $tombol = $this->markupOf($pesan)['inline_keyboard'][0][0];
        $this->assertSame('Lihat di Mini App', $tombol['text']);
        $this->assertStringContainsString('/keuangan/pengeluaran/41', $tombol['web_app']['url']);
        $this->assertStringContainsString("?sppg={$this->sppgA}", $tombol['web_app']['url']);
    }

    public function test_pengeluaran_diajukan_sppg_lain_tidak_bocor_dan_dedup_per_versi(): void
    {
        event($this->diajukan($this->sppgC));
        $this->assertSame([$this->chat($this->investorC)], $this->penerima());

        $this->fakeTelegram();
        event($this->diajukan($this->sppgA, 42));
        event($this->diajukan($this->sppgA, 42)); // ganda: tidak dikirim ulang
        $this->assertSame([$this->chat($this->investorAB)], $this->penerima());

        event($this->diajukan($this->sppgA, 42, 2)); // diajukan ulang setelah diubah: versi baru
        $this->assertCount(2, $this->telegramCalls('sendMessage'));
    }

    public function test_alur_http_mengirim_notifikasi_ke_investor_dan_bukan_ke_peran_lain(): void
    {
        $this->forgetActor();
        $res = $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', ['tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Rahasia Pak Joko 0812-FIKTIF', 'nominal' => 600_000])->assertCreated();

        $this->assertSame([$this->chat($this->investorAB)], $this->penerima());
        $this->assertStringNotContainsString('Joko', json_encode($this->telegramCalls('sendMessage')->map(fn ($r) => $r->data())->all()));
        $this->assertNotNull($res->json('data.id'));
    }

    public function test_anggaran_80_dan_100_ke_investor_tetapi_melampaui_tidak(): void
    {
        foreach ([new AnggaranMencapai80($this->sppgA, '2026-11', 50_000_000, 41_000_000, 82.0), new AnggaranMencapai100($this->sppgA, '2026-11', 50_000_000, 50_000_000, 100.0)] as $event) {
            $this->fakeTelegram();
            event($event);

            $this->assertContains($this->chat($this->investorAB), $this->penerima(), $event::class);
            $this->assertContains($this->chat($this->akuntanA), $this->penerima());
            $this->assertContains($this->chat($this->kepalaA), $this->penerima());
            $this->assertNotContains($this->chat($this->investorC), $this->penerima());
            $tombol = $this->markupOf($this->messagesTo($this->chat($this->investorAB))->first())['inline_keyboard'][0][0];
            $this->assertStringContainsString("/keuangan?sppg={$this->sppgA}", $tombol['web_app']['url']);
        }

        $this->fakeTelegram();
        event(new AnggaranMelampaui($this->sppgA, '2026-11', 50_000_000, 52_000_000, 104.0));
        $this->assertNotContains($this->chat($this->investorAB), $this->penerima());
        $this->assertContains($this->chat($this->akuntanA), $this->penerima());
    }

    public function test_keluhan_kritis_investor_tanpa_deskripsi_dan_peran_lain_tetap_lengkap(): void
    {
        event(new KeluhanKritis(77, $this->sppgA, 'keracunan', 'kritis', null, 'SDN 4 Fiktif', null, 'Anak Budi Fiktif muntah, hubungi 0812-FIKTIF', '2026-11-10T10:00:00+07:00'));

        $this->assertEqualsCanonicalizing(
            [$this->chat($this->investorAB), $this->chat($this->kepalaA), $this->chat($this->ahliGiziA)],
            $this->penerima(),
        );

        $investor = $this->messagesTo($this->chat($this->investorAB))->sole();
        $this->assertStringContainsString('SDN 4 Fiktif', $investor['text']);
        $this->assertStringNotContainsString('Budi', $investor['text']);
        $this->assertStringNotContainsString('0812', $investor['text']);
        $this->assertStringNotContainsString('muntah', $investor['text']);
        $this->assertStringContainsString("/keluhan?sppg={$this->sppgA}", $this->markupOf($investor)['inline_keyboard'][0][0]['web_app']['url']);

        $kepala = $this->messagesTo($this->chat($this->kepalaA))->sole();
        $this->assertStringContainsString('muntah', $kepala['text']);
        $this->assertNotContains($this->chat($this->investorC), $this->penerima());

        // Dedup per varian: kirim ulang event yang sama tidak menggandakan pesan.
        event(new KeluhanKritis(77, $this->sppgA, 'keracunan', 'kritis', null, 'SDN 4 Fiktif', null, 'Anak Budi Fiktif muntah, hubungi 0812-FIKTIF', '2026-11-10T10:00:00+07:00'));
        $this->assertCount(3, $this->telegramCalls('sendMessage'));
    }

    public function test_keluhan_kritis_tanpa_kepala_dan_ahli_gizi_tetap_cadangan_admin_dan_investor_tidak_memicu_cadangan(): void
    {
        $sepi = $this->createSppg('SPPG Sepi');
        $this->investor([$sepi]);
        event(new KeluhanKritis(78, $sepi, 'rasa', 'kritis', null, null, null, 'Deskripsi fiktif', '2026-11-10T10:00:00+07:00'));

        // Investor menerima varian ringkas; varian lengkap tak punya penerima peran -> cadangan admin.
        $this->assertCount(2, $this->telegramCalls('sendMessage'));
    }

    public function test_event_lain_tidak_pernah_ke_investor_dan_tabel_penerima_sesuai_keputusan(): void
    {
        event(new StokMenipis($this->sppgA, 11, 'Beras', 'kg', '4.000', '10.000'));
        $this->assertNotContains($this->chat($this->investorAB), $this->penerima());

        $investor = array_keys(array_filter(DomainEventNotifications::RECIPIENTS, fn (array $roles) => in_array(Role::Investor, $roles, true)));
        $this->assertEqualsCanonicalizing(
            ['KeluhanKritis', 'AnggaranMencapai80', 'AnggaranMencapai100', 'MenuDiajukan', 'PengeluaranDiajukan'],
            $investor,
        );
        $this->assertContains('PengeluaranDiajukan', DomainEventNotifications::EVENTS);
        $this->assertContains(PengeluaranDiajukan::class, DomainEventNotifications::registeredEventClasses());
    }

    public function test_pembelian_bahan_dan_keputusan_pengeluaran_tidak_mengirim_notifikasi_baru(): void
    {
        $this->pencairan($this->sppgA, 10_000_000);
        $id = $this->catatPembelian();
        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();
        $this->assertSame([], $this->penerima());

        $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', ['tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Listrik', 'nominal' => 100_000])->assertCreated();
        $pengeluaranId = (int) Pengeluaran::query()->withoutGlobalScopes()->where('kategori', 'energi')->value('id');
        $sebelum = count($this->telegramCalls('sendMessage'));

        $this->sebagai($this->investorAB, $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$pengeluaranId}/keputusan", ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();
        $this->assertCount($sebelum, $this->telegramCalls('sendMessage'));
    }
}

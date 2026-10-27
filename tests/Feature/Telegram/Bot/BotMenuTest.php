<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotMenuCatalog;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Support\Rbac;

/** Menu tombol Bot per peran (R-02): disaring dari config/rbac.php, tanpa sesi penuh hanya petunjuk masuk. */
class BotMenuTest extends BotTestCase
{
    /** @return list<string> label web_app (tanpa "Buka SiGAP"/"Masuk lewat Mini App") pada layar terakhir */
    private function labelPintasan(User $user): array
    {
        $label = [];

        foreach ($this->tombolLayar((int) $user->telegram_id) as $t) {
            if (isset($t['web_app']) && ! in_array($t['text'], ['Buka SiGAP', TelegramUpdateHandler::LABEL_MASUK], true)) {
                $label[] = $t['text'];
            }
        }

        return $label;
    }

    private function menuUntuk(User $user): array
    {
        $this->sesiPenuh($user);
        $this->ketik($user, '/menu');

        return $this->labelPintasan($user);
    }

    public function test_katalog_konsisten_dengan_rbac_dan_batas_butir(): void
    {
        foreach (BotMenuCatalog::BUTIR as $id => [, $modul, $huruf]) {
            $this->assertTrue(Rbac::hasModule($modul), "Butir {$id} menunjuk modul tak dikenal {$modul}");
            $this->assertContains($huruf, Rbac::LETTERS);
        }

        foreach (BotMenuCatalog::PROFIL as $profil => $ids) {
            $this->assertLessThanOrEqual(BotMenuCatalog::MAKS_BUTIR, count($ids), $profil);

            foreach ($ids as $id) {
                $this->assertArrayHasKey($id, BotMenuCatalog::BUTIR, "{$profil}: {$id}");
            }
        }
    }

    public function test_setiap_butir_yang_tampil_lolos_rbac_untuk_semua_profil(): void
    {
        $semua = [
            $this->admin, $this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->investorAB, $this->investorC,
            $this->relawanA,
            $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi),
        ];

        foreach ($semua as $user) {
            foreach (BotMenuCatalog::untuk($user) as $butir) {
                $huruf = BotMenuCatalog::BUTIR[$butir['id']][2];
                $this->assertTrue(Rbac::can($user, $butir['modul'], $huruf), "{$user->role->value}: {$butir['id']}");
                $this->assertFalse(Rbac::isSelfOnly($user, $butir['modul'], $huruf));
            }
        }
    }

    public function test_matriks_menu_per_peran(): void
    {
        $this->assertSame(['Pengguna', 'Data SPPG', 'Titik Layanan', 'Keluhan', 'Rekap Harian'], $this->menuUntuk($this->admin));
        $this->assertSame(['Jadwal', 'Notifikasi Perjalanan', 'Bukti Distribusi', 'Keluhan', 'Rekap Harian', 'Keuangan'], $this->menuUntuk($this->kepalaA));
        $this->assertSame(['Menu dan Gizi', 'Persetujuan Menu', 'Jadwal', 'Mutu Pangan', 'Keluhan', 'Rekap Harian'], $this->menuUntuk($this->ahliGiziA));
        $this->assertSame(['Keuangan', 'Pengeluaran', 'Pembelian Bahan', 'Rekap Harian'], $this->menuUntuk($this->akuntanA));
        $this->assertSame(['Bahan Baku', 'Stok Menipis', 'Kedaluwarsa Dekat', 'Pembelian Bahan', 'Pemasok'], $this->menuUntuk($this->stafGudangA));
        $this->assertSame(['Persetujuan Menu', 'Pengeluaran', 'Keuangan', 'Rekap Harian', 'Keluhan', 'Mutu Pangan'], $this->menuUntuk($this->investorAB));
    }

    public function test_menu_relawan_mengikuti_unit_dan_tanpa_unit(): void
    {
        $this->assertSame(['Jadwal', 'Log Produksi', 'Mutu Pangan', 'Keluhan'], $this->menuUntuk($this->relawanA));
        $this->assertSame(
            ['Jadwal', 'Notifikasi Perjalanan', 'Bukti Distribusi', 'Keluhan'],
            $this->menuUntuk($this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi)),
        );

        $tanpaUnit = $this->userOf(Role::Relawan, $this->sppgA);
        $tanpaUnit->forceFill(['unit' => null])->saveQuietly();
        $this->assertSame(['Mutu Pangan', 'Keluhan', 'Armada'], $this->menuUntuk($tanpaUnit->fresh()));
    }

    public function test_peran_tanpa_hak_tidak_melihat_tombol_dan_hanya_investor_punya_tombol_keputusan(): void
    {
        $label = $this->menuUntuk($this->stafGudangA);
        $this->assertNotContains('Keuangan', $label);
        $this->assertNotContains('Pengguna', $label);
        $this->assertFalse($this->adaTombol($this->stafGudangA, 'Menunggu keputusan'));

        $this->menuUntuk($this->kepalaA);
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Menunggu keputusan'));

        $this->menuUntuk($this->investorAB);
        $this->assertTrue($this->adaTombol($this->investorAB, 'Menunggu keputusan'));
    }

    public function test_url_tombol_memuat_rute_di_query_dan_hash_dan_sppg_hanya_investor_satu_sppg(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->investorC, $this->investorAB);

        $this->ketik($this->kepalaA, '/menu');
        $url = collect($this->tombolLayar((int) $this->kepalaA->telegram_id))->firstWhere('text', 'Jadwal')['web_app']['url'];
        $this->assertSame('https://sigap.test/miniapp/?r=%2Fjadwal#/jadwal', $url);

        $this->ketik($this->investorC, '/menu');
        $url = collect($this->tombolLayar((int) $this->investorC->telegram_id))->firstWhere('text', 'Pengeluaran')['web_app']['url'];
        $this->assertSame("https://sigap.test/miniapp/?r=%2Fkeuangan%2Fpengeluaran&sppg={$this->sppgC}#/keuangan/pengeluaran", $url);

        // Dua SPPG: tanpa petunjuk, Mini App menampilkan pemilih SPPG.
        $this->ketik($this->investorAB, '/menu');
        $url = collect($this->tombolLayar((int) $this->investorAB->telegram_id))->firstWhere('text', 'Keuangan')['web_app']['url'];
        $this->assertStringNotContainsString('sppg=', $url);
    }

    public function test_menu_tidak_memuat_data_pribadi_atau_dinamis(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->ketik($this->kepalaA, '/menu');

        $teks = $this->teksLayar($this->kepalaA).json_encode($this->tombolLayar((int) $this->kepalaA->telegram_id));
        $this->assertStringNotContainsString((string) $this->kepalaA->name, $teks);
        $this->assertStringNotContainsString('SPPG A', $teks);
    }

    // ------------------------------------------------------------------ tanpa sesi penuh

    public function test_menu_tanpa_sesi_penuh_hanya_petunjuk_masuk(): void
    {
        $this->ketik($this->investorAB, '/menu');

        $balasan = $this->messagesTo((int) $this->investorAB->telegram_id)->sole();
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $balasan['text']);
        $tombol = $this->tombolLayar((int) $this->investorAB->telegram_id);
        $this->assertCount(1, $tombol);
        $this->assertSame('Masuk lewat Mini App', $tombol[0]['text']);
        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_menu_setelah_sesi_dicabut_kembali_petunjuk_masuk(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $this->ketik($this->akuntanA, '/menu');
        $this->assertNotEmpty($this->labelPintasan($this->akuntanA));

        app(SesiService::class)->keluarSemua($this->akuntanA);
        $this->ketik($this->akuntanA, '/menu');

        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->layar((int) $this->akuntanA->telegram_id)['text']);
        $this->assertSame([], $this->labelPintasan($this->akuntanA));
    }

    public function test_start_dan_bantuan_tanpa_sesi_hanya_tombol_masuk_dan_dengan_sesi_menu(): void
    {
        $uid = (int) $this->ahliGiziA->telegram_id;

        $this->ketik($this->ahliGiziA, '/start');
        $this->ketik($this->ahliGiziA, '/bantuan');
        foreach ($this->messagesTo($uid) as $kirim) {
            $this->assertCount(1, $this->markupOf($kirim)['inline_keyboard'], 'tanpa sesi: satu baris (Masuk lewat Mini App)');
        }

        $this->sesiPenuh($this->ahliGiziA);
        $this->ketik($this->ahliGiziA, '/bantuan');
        $this->assertContains('Persetujuan Menu', $this->labelPintasan($this->ahliGiziA));
    }

    public function test_pengguna_nonaktif_dan_tidak_terdaftar_tidak_mendapat_menu(): void
    {
        $this->forgetActor();
        $nonaktif = User::factory()->role(Role::StafGudang)->inactive()->create(['sppg_id' => $this->sppgA]);

        $this->ketik($nonaktif, '/menu');
        $this->assertStringContainsString('nonaktif', (string) $this->layar((int) $nonaktif->telegram_id)['text']);
        $this->assertSame([], $this->labelPintasan($nonaktif));

        $this->kirim($this->privateUpdate(999777, ['text' => '/menu']));
        $this->assertStringContainsString('belum terdaftar', (string) $this->layar(999777)['text']);
        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_menu_di_grup_diabaikan(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $this->kirim($this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => '/menu'], 'group'));

        $this->assertCount(0, $this->telegramCalls('sendMessage'));
    }
}

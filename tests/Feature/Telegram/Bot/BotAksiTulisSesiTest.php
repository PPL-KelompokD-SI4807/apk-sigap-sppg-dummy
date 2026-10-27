<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\StatusKirim;
use App\Events\PerjalananDimulai;
use App\Events\RekapHarianDibuat;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Aturan 7 untuk jalur PR-3 (aksi tulis): tanpa sesi login penuh SEMUA peran (termasuk investor satu/banyak SPPG)
 * pada SEMUA token aksi tulis (aksi, "Ya", Batal) dan layar Mulai Perjalanan hanya mendapat PERLU_MASUK + satu tombol
 * "Masuk lewat Mini App". Token tidak dihabiskan, layar asal dinetralkan, tidak ada data dan tidak ada efek.
 */
class BotAksiTulisSesiTest extends AksiTulisTestCase
{
    private const PERAN = ['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorAB', 'investorC'];

    private const JALUR = [
        'kirim_ulang_rekap', 'ya_kirim_ulang_rekap', 'picu_perjalanan', 'ya_picu_perjalanan',
        'tindak_keluhan', 'ya_tindak_keluhan', 'batal_aksi', 'n1_mulai_perjalanan',
    ];

    /** @return iterable<string, array{string, string, string}> */
    public static function kasus(): iterable
    {
        foreach (self::PERAN as $peran) {
            foreach (self::JALUR as $jalur) {
                yield "{$peran} x {$jalur}" => [$peran, $jalur, 'tanpa_token'];
            }
        }

        foreach (['kepalaA', 'relawanDistribusi', 'investorAB'] as $peran) {
            foreach (['ya_kirim_ulang_rekap', 'ya_picu_perjalanan', 'ya_tindak_keluhan'] as $jalur) {
                yield "{$peran} x {$jalur} x kedaluwarsa" => [$peran, $jalur, 'kedaluwarsa'];
                yield "{$peran} x {$jalur} x dicabut" => [$peran, $jalur, 'dicabut'];
            }
        }
    }

    #[DataProvider('kasus')]
    public function test_tanpa_sesi_penuh_hanya_tombol_masuk(string $peran, string $jalur, string $sesi): void
    {
        $rekap = $this->rekap($this->sppgA);
        $keluhan = $this->keluhan($this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA);
        $user = $this->pengguna($peran);
        $uid = (int) $user->telegram_id;

        if ($sesi !== 'tanpa_token') {
            $this->sesiPenuh($user);
            $sesi === 'kedaluwarsa'
                ? $user->tokens()->update(['expires_at' => now()->subMinute()])
                : app(SesiService::class)->keluarSemua($user);
        }

        $this->assertFalse(app(SesiService::class)->punyaSesiPenuh($user->fresh()));

        $subjek = match (true) {
            str_contains($jalur, 'rekap') => [BotAksi::SUBJEK_REKAP, $rekap->id],
            str_contains($jalur, 'perjalanan') => [BotAksi::SUBJEK_JADWAL_DISTRIBUSI, $jadwal->id],
            default => [BotAksi::SUBJEK_KELUHAN, $keluhan->id],
        };
        $data = $jalur === 'n1_mulai_perjalanan'
            ? BotNavCodec::data('mp', $this->sppgA, $uid)
            : $this->token($user, $jalur, $this->opsi($this->sppgA, $subjek[0], $subjek[1]));
        $this->fakeTelegram();

        $this->tekan($user, $data);

        // Hanya PERLU_MASUK + satu tombol web_app; edit pesan asal hanya teks netral tanpa keyboard.
        $metode = collect(Http::recorded())->map(fn (array $p) => basename((string) parse_url($p[0]->url(), PHP_URL_PATH)))->unique()->values()->all();
        $this->assertEmpty(array_diff($metode, ['sendMessage', 'answerCallbackQuery', 'editMessageText']), implode(',', $metode));

        foreach ($this->telegramCalls('editMessageText') as $edit) {
            $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_SESI, $edit['text']);
            $this->assertSame([], $this->markupOf($edit)['inline_keyboard'] ?? []);
        }

        $pesan = $this->messagesTo($uid);
        $this->assertCount(1, $pesan);
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $pesan->first()['text']);
        $this->assertSame([[['text' => TelegramUpdateHandler::LABEL_MASUK, 'web_app' => ['url' => 'https://sigap.test/miniapp/']]]], $this->markupOf($pesan->first())['inline_keyboard']);
        $this->assertSame(BotCallbackHandler::PERLU_MASUK_SINGKAT, $this->jawabanTerakhir());

        if ($jalur !== 'n1_mulai_perjalanan') {
            $this->assertSame(BotAksi::STATUS_AKTIF, $this->statusToken($data), 'Token tidak dihabiskan');
        }

        $this->assertSame(1, BotAksi::query()->count() + ($jalur === 'n1_mulai_perjalanan' ? 1 : 0), 'Tanpa token baru');
        Event::assertNotDispatched(RekapHarianDibuat::class);
        Event::assertNotDispatched(PerjalananDimulai::class);
        $this->assertSame(StatusKirim::Terkirim, $this->rekapRaw($rekap->id)->status_kirim);
        $this->assertSame('baru', $this->keluhanRaw($keluhan->id)->status->value);
        $this->assertSame(0, $this->jumlahPerjalanan($jadwal->id));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('sumber', 'bot')->count());
    }

    /** Kontrol: dengan sesi penuh, jalur yang sama untuk kepala menghasilkan layar konfirmasi (tes di atas tidak lolos kosong). */
    public function test_kontrol_dengan_sesi_penuh_konfirmasi_tampil(): void
    {
        $this->keluhan($this->sppgA);
        $this->sesiPenuh($this->kepalaA);

        $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan');
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Ya, lakukan'));
    }
}

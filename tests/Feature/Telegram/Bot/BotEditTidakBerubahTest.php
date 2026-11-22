<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\BotAksi;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/** Galat edit pesan: "message is not modified" diabaikan (tanpa pesan baru); galat edit lain -> fallback pesan baru. */
class BotEditTidakBerubahTest extends BotTestCase
{
    private const TIDAK_BERUBAH = 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sesiPenuh($this->investorAB);
    }

    private function galatEdit(string $deskripsi, int $kode = 400): array
    {
        return ['api.telegram.org/*/editMessageText' => Http::response(['ok' => false, 'error_code' => $kode, 'description' => $deskripsi], $kode)];
    }

    /** Dari /menu tekan "Menunggu keputusan" (pemilih SPPG diedit di tempat). */
    private function tekanDaftar(): void
    {
        $this->pengajuanMenu();
        $this->ketik($this->investorAB, '/menu');
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Menunggu keputusan'));
    }

    public function test_segarkan_tak_berubah_tidak_mengirim_pesan_baru_dan_callback_dijawab(): void
    {
        $this->pengajuanMenu();
        $this->fakeTelegram($this->galatEdit(self::TIDAK_BERUBAH));
        $uid = (int) $this->investorAB->telegram_id;

        $this->ketik($this->investorAB, '/menu');
        $kirimSebelum = $this->telegramCalls('sendMessage')->count();
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Menunggu keputusan'));

        $this->assertCount(1, $this->telegramCalls('editMessageText'), 'Edit dicoba satu kali');
        $this->assertSame($kirimSebelum, $this->telegramCalls('sendMessage')->count(), 'Tidak ada pesan baru');
        $this->assertCount(1, $this->telegramCalls('answerCallbackQuery'), 'Callback dijawab tepat sekali');
        $this->assertSame(1, $this->messagesTo($uid)->count());
        $this->assertSame('selesai', BotAksi::query()->where('jenis', BotAksi::JENIS_DAFTAR)->where('status', '!=', 'aktif')->sole()->status);

        $log = json_encode($this->logBot);
        $this->assertStringContainsString('bot.edit_tidak_berubah', $log);
        $this->assertStringNotContainsString('bot.edit_gagal', $log);
        $this->assertStringNotContainsString('Nasi Telur Uji', $log);
        $this->assertStringNotContainsString('exactly the same', $log, 'Deskripsi Telegram tidak ikut ke log');
    }

    /** @return array<string, array{string, int}> */
    public static function galatEditLain(): array
    {
        return [
            'pesan lebih dari 48 jam' => ["Bad Request: message can't be edited", 400],
            'pesan tidak ditemukan' => ['Bad Request: message to edit not found', 400],
            'tidak berubah tetapi bukan 400' => ['Forbidden: message is not modified', 403],
        ];
    }

    #[DataProvider('galatEditLain')]
    public function test_galat_edit_lain_fallback_ke_pesan_baru(string $deskripsi, int $kode): void
    {
        $this->pengajuanMenu();
        $this->fakeTelegram($this->galatEdit($deskripsi, $kode));
        $uid = (int) $this->investorAB->telegram_id;

        $this->ketik($this->investorAB, '/menu');
        $kirimSebelum = $this->telegramCalls('sendMessage')->count();
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Menunggu keputusan'));

        $this->assertCount(1, $this->telegramCalls('editMessageText'));
        $this->assertSame($kirimSebelum + 1, $this->telegramCalls('sendMessage')->count(), 'Fallback: satu pesan baru');
        $this->assertStringContainsString('Pilih SPPG', (string) $this->messagesTo($uid)->last()['text']);
        $this->assertStringContainsString('bot.edit_gagal', json_encode($this->logBot));
    }

    public function test_bot_service_membedakan_tidak_berubah_dari_galat_lain_dan_tidak_membocorkan_token(): void
    {
        config(['telegram.bot_token' => '123456789:TEST-ONLY-NOT-A-REAL-BOT-TOKEN-sigap']);
        $bot = app(TelegramBotService::class);

        $this->fakeTelegram($this->galatEdit(self::TIDAK_BERUBAH));

        try {
            $bot->editMessageText(1, 2, 'x');
            $this->fail('Seharusnya melempar.');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->isMessageNotModified());
            $this->assertSame(TelegramApiException::JENIS_TIDAK_BERUBAH, $e->jenis);
            $this->assertStringNotContainsString('TEST-ONLY-NOT-A-REAL', $e->getMessage());
        }

        foreach ([["Bad Request: message can't be edited", 400], ['Bad Request: chat not found', 400], ['Forbidden: bot was blocked by the user', 403], ['Too Many Requests', 429]] as [$deskripsi, $kode]) {
            $this->fakeTelegram($this->galatEdit($deskripsi, $kode));

            try {
                $bot->editMessageText(1, 2, 'x');
                $this->fail('Seharusnya melempar.');
            } catch (TelegramApiException $e) {
                $this->assertFalse($e->isMessageNotModified(), $deskripsi);
            }
        }
    }
}

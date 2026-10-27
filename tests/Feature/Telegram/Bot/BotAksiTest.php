<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\BotAksi;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\Bot\BotKodeCodec;
use App\Services\Telegram\TelegramBotService;
use App\Support\SppgAktif;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/** Fondasi Bot R-02: callback_data ber-HMAC, token sekali pakai atomik, SppgAktif::untuk, pembersihan. */
class BotAksiTest extends BotTestCase
{
    private function svc(): BotAksiService
    {
        return app(BotAksiService::class);
    }

    public function test_format_callback_data_ber_hmac_pendek_dan_tanpa_makna_bisnis(): void
    {
        $aksi = $this->svc()->terbit($this->investorAB, (int) $this->investorAB->telegram_id, BotAksi::JENIS_TINJAU, [
            'sppg_id' => $this->sppgA, 'subjek_tipe' => BotAksi::SUBJEK_PENGELUARAN, 'subjek_id' => 4242, 'subjek_versi' => 7,
        ]);
        $data = $this->svc()->callbackData($aksi);

        $this->assertMatchesRegularExpression('/^b1\.[A-Za-z0-9_-]{11}\.[A-Za-z0-9_-]{8}$/D', $data);
        $this->assertLessThanOrEqual(64, strlen($data));
        $this->assertStringNotContainsString('4242', $data);
        $this->assertStringNotContainsString('tinjau', $data);
    }

    public function test_mac_terikat_telegram_id_dan_pemalsuan_ditolak(): void
    {
        $aksi = $this->svc()->terbit($this->investorAB, (int) $this->investorAB->telegram_id, BotAksi::JENIS_DAFTAR);
        $data = $this->svc()->callbackData($aksi);
        $tid = (int) $this->investorAB->telegram_id;

        $this->assertSame($aksi->kode, BotKodeCodec::urai($data, $tid));
        $this->assertNull(BotKodeCodec::urai($data, $tid + 1));
        $this->assertNull(BotKodeCodec::urai(substr($data, 0, -1).($data[-1] === 'A' ? 'B' : 'A'), $tid));
        $this->assertNull(BotKodeCodec::urai('b1.'.$aksi->kode.'.AAAAAAAA', $tid));
        $this->assertNull(BotKodeCodec::urai('b2.'.$aksi->kode.'.'.BotKodeCodec::mac($aksi->kode, $tid), $tid));
        $this->assertNull(BotKodeCodec::urai(str_repeat('x', 100), $tid));
        $this->assertNull(BotKodeCodec::urai(null, $tid));
        $this->assertNull($this->svc()->temukan($data, $tid + 1));
        $this->assertNotNull($this->svc()->temukan($data, $tid));
    }

    public function test_konsumsi_atomik_hanya_satu_pemenang_untuk_dua_instance_paralel(): void
    {
        $aksi = $this->svc()->terbit($this->investorAB, (int) $this->investorAB->telegram_id, BotAksi::JENIS_SETUJU);
        $a = BotAksi::query()->findOrFail($aksi->id);
        $b = BotAksi::query()->findOrFail($aksi->id);

        $this->assertTrue($this->svc()->konsumsi($a));
        $this->assertFalse($this->svc()->konsumsi($b));
        $this->assertSame('dipakai', BotAksi::query()->findOrFail($aksi->id)->status);
    }

    public function test_token_kedaluwarsa_tidak_dapat_dikonsumsi(): void
    {
        $aksi = $this->svc()->terbit($this->investorAB, (int) $this->investorAB->telegram_id, BotAksi::JENIS_SETUJU);
        BotAksi::query()->whereKey($aksi->id)->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse($this->svc()->konsumsi($aksi->fresh()));
    }

    public function test_token_saudara_satu_layar_kedaluwarsa_setelah_satu_dipakai(): void
    {
        $grup = BotAksiService::grupBaru();
        $uid = (int) $this->investorAB->telegram_id;
        $a = $this->svc()->terbit($this->investorAB, $uid, BotAksi::JENIS_SETUJU, [], $grup);
        $b = $this->svc()->terbit($this->investorAB, $uid, BotAksi::JENIS_TOLAK, [], $grup);

        $this->assertTrue($this->svc()->konsumsi($a));
        $this->assertSame('kedaluwarsa', $b->fresh()->status);
        $this->assertFalse($this->svc()->konsumsi($b->fresh()));
    }

    public function test_batas_token_aktif_per_pengguna_mengedaluwarsakan_yang_tertua(): void
    {
        config(['telegram.aksi_aktif_maks' => 3]);
        $uid = (int) $this->investorAB->telegram_id;
        $semua = [];

        for ($i = 0; $i < 5; $i++) {
            $semua[] = $this->svc()->terbit($this->investorAB, $uid, BotAksi::JENIS_DAFTAR);
        }

        $this->assertSame(3, $this->jumlahAksi('aktif'));
        $this->assertSame('kedaluwarsa', $semua[0]->fresh()->status);
        $this->assertSame('aktif', $semua[4]->fresh()->status);
    }

    public function test_berlaku_memeriksa_ikatan_from_chat_dan_user(): void
    {
        $uid = (int) $this->investorAB->telegram_id;
        $aksi = $this->svc()->terbit($this->investorAB, $uid, BotAksi::JENIS_DAFTAR);

        $this->assertTrue($this->svc()->berlaku($aksi, $uid, $uid, $this->investorAB));
        $this->assertFalse($this->svc()->berlaku($aksi, $uid + 1, $uid, $this->investorAB));
        $this->assertFalse($this->svc()->berlaku($aksi, $uid, $uid + 1, $this->investorAB));
        $this->assertFalse($this->svc()->berlaku($aksi, $uid, $uid, $this->investorC));
        $this->assertFalse($this->svc()->berlaku($aksi, $uid, $uid, null));
    }

    // ------------------------------------------------------------------ SppgAktif::untuk

    public function test_untuk_investor_menetapkan_sppg_milik_dan_dibersihkan_setelahnya(): void
    {
        $aktif = app(SppgAktif::class);

        // Investor dua SPPG tanpa header: tidak ada SPPG aktif (fail closed).
        $this->assertNull($this->investorAB->sppgAktifId());

        $di = $aktif->untuk($this->investorAB, $this->sppgB, fn () => $this->investorAB->sppgAktifId());
        $this->assertSame($this->sppgB, $di);
        $this->assertNull($this->investorAB->sppgAktifId());
    }

    public function test_untuk_menolak_sppg_bukan_milik_admin_dan_peran_lain(): void
    {
        $aktif = app(SppgAktif::class);

        foreach ([
            [$this->investorAB, $this->sppgC],
            [$this->investorC, $this->sppgA],
            [$this->admin, $this->sppgA],
            [$this->kepalaA, $this->sppgB],
        ] as [$user, $sppg]) {
            try {
                $aktif->untuk($user, $sppg, fn () => $this->fail('closure tidak boleh jalan'));
                $this->fail('Seharusnya ditolak');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame($this->sppgA, $aktif->untuk($this->kepalaA, $this->sppgA, fn () => $this->kepalaA->sppgAktifId()));
    }

    public function test_untuk_dibersihkan_saat_exception_dan_bersarang(): void
    {
        $aktif = app(SppgAktif::class);

        try {
            $aktif->untuk($this->investorAB, $this->sppgA, function (): void {
                throw new RuntimeException('galat uji');
            });
        } catch (RuntimeException) {
        }

        $this->assertNull($this->investorAB->sppgAktifId());

        $hasil = $aktif->untuk($this->investorAB, $this->sppgA, function () use ($aktif) {
            $dalam = $aktif->untuk($this->investorAB, $this->sppgB, fn () => $this->investorAB->sppgAktifId());

            return [$dalam, $this->investorAB->sppgAktifId()];
        });

        $this->assertSame([$this->sppgB, $this->sppgA], $hasil);
        $this->assertNull($this->investorAB->sppgAktifId());
    }

    // ------------------------------------------------------------------ pembersihan

    public function test_perintah_bersihkan_menghapus_baris_lebih_dari_retensi(): void
    {
        $uid = (int) $this->investorAB->telegram_id;
        $lama = $this->svc()->terbit($this->investorAB, $uid, BotAksi::JENIS_DAFTAR);
        $baru = $this->svc()->terbit($this->investorAB, $uid, BotAksi::JENIS_DAFTAR);
        BotAksi::query()->whereKey($lama->id)->update(['created_at' => now()->subDays(31)]);

        $this->artisan('sigap:bersihkan-aksi-bot')->assertSuccessful();

        $this->assertNull(BotAksi::query()->find($lama->id));
        $this->assertNotNull(BotAksi::query()->find($baru->id));
    }

    // ------------------------------------------------------------------ TelegramBotService

    public function test_edit_message_dan_force_reply_membentuk_permintaan_yang_benar(): void
    {
        $bot = app(TelegramBotService::class);
        $bot->editMessageText(123, 9, 'Teks <b>baru</b>', ['parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => [[['text' => 'A', 'callback_data' => 'x']]]]]);
        $bot->editMessageText(123, 9, 'Tanpa tombol');
        $bot->editMessageReplyMarkup(123, 9, null);
        $bot->sendMessage(123, 'Alasan?', ['reply_markup' => TelegramBotService::forceReply('Alasan penolakan')]);

        $edit = $this->telegramCalls('editMessageText');
        $this->assertSame('HTML', $edit[0]['parse_mode']);
        $this->assertSame('9', (string) $edit[0]['message_id']);
        $this->assertSame(['inline_keyboard' => []], json_decode($edit[1]['reply_markup'], true));
        $this->assertSame(['inline_keyboard' => []], json_decode($this->telegramCalls('editMessageReplyMarkup')->sole()['reply_markup'], true));
        $force = json_decode($this->telegramCalls('sendMessage')->sole()['reply_markup'], true);
        $this->assertTrue($force['force_reply']);
        $this->assertSame('Alasan penolakan', $force['input_field_placeholder']);
    }
}

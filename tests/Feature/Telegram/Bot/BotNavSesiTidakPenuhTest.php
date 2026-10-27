<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Aturan 7 untuk jalur baru PR-2 (R02-A1): tanpa sesi login penuh, perintah pintasan dan callback `n1`
 * (termasuk Beranda, kedaluwarsa, dan Segarkan) HANYA menghasilkan pesan PERLU_MASUK + satu tombol masuk,
 * untuk semua peran/profil termasuk investor satu dan banyak SPPG. Layar tidak dirender, tidak ada token/data.
 */
class BotNavSesiTidakPenuhTest extends BotTestCase
{
    private const MODUL = [
        'jd' => 'jadwal', 'st' => 'bahan-baku', 'kl' => 'keluhan', 'rk' => 'rekap-harian',
        'ku' => 'keuangan', 'ag' => 'keuangan', 'ds' => 'bukti-distribusi', 'mt' => 'mutu',
    ];

    private const PERAN = ['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorAB', 'investorC'];

    /** @return list<string> */
    private static function jalur(): array
    {
        return [
            ...array_map(fn (string $p) => 'cmd_'.$p, array_keys(BotLayarRegistry::PERINTAH)),
            'n1_layar', 'n1_beranda', 'n1_pilih_sppg', 'n1_tanya_ai', 'n1_bantuan', 'n1_kedaluwarsa', 'n1_segarkan', 'n1_sesi_kedaluwarsa', 'n1_sesi_dicabut',
        ];
    }

    /** @return iterable<string, array{string, string}> */
    public static function peranDanJalur(): iterable
    {
        foreach (self::PERAN as $peran) {
            foreach (self::jalur() as $jalur) {
                yield "{$peran} x {$jalur}" => [$peran, $jalur];
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        LayarUji::$render = [];
        $layar = BotLayarRegistry::layarBawaan();

        // 'rk' (rekap-harian R) dapat dibaca setiap peran sehingga hanya sesi yang menolak; layar lain tinggal pintasan.
        foreach (BotLayarRegistry::PERINTAH as $id) {
            $layar[] = new LayarUji($id, self::MODUL[$id]);
        }

        $this->app->instance(BotLayarRegistry::class, new BotLayarRegistry($layar));
    }

    private function pengguna(string $peran): User
    {
        if ($peran === 'relawanDistribusi') {
            return $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        }

        if ($peran === 'relawanTanpaUnit') {
            $user = $this->userOf(Role::Relawan, $this->sppgA);
            $user->forceFill(['unit' => null])->save();

            return $user->fresh();
        }

        return $this->{$peran};
    }

    #[DataProvider('peranDanJalur')]
    public function test_tanpa_sesi_penuh_jalur_baru_hanya_tombol_masuk(string $peran, string $jalur): void
    {
        $user = $this->pengguna($peran);
        $uid = (int) $user->telegram_id;
        $sppg = $peran === 'investorC' ? $this->sppgC : $this->sppgA;
        $data = BotNavCodec::data('rk', $sppg, $uid);

        // Variasi data sesi: tanpa token, token kedaluwarsa, sesi dicabut setelah tombol dirender.
        if ($jalur === 'n1_sesi_kedaluwarsa') {
            $this->sesiPenuh($user);
            $user->tokens()->update(['expires_at' => now()->subMinute()]);
        } elseif ($jalur === 'n1_sesi_dicabut') {
            $this->sesiPenuh($user);
            app(SesiService::class)->keluarSemua($user);
        }

        $this->assertFalse(app(SesiService::class)->punyaSesiPenuh($user->fresh()));
        $this->fakeTelegram();

        match (true) {
            str_starts_with($jalur, 'cmd_') => $this->ketik($user, '/'.substr($jalur, 4)),
            $jalur === 'n1_beranda' => $this->tekan($user, BotNavCodec::data(BotLayarRegistry::BERANDA, null, $uid)),
            $jalur === 'n1_pilih_sppg' => $this->tekan($user, BotNavCodec::data(BotLayarRegistry::PILIH_SPPG, null, $uid)),
            $jalur === 'n1_tanya_ai' => $this->tekan($user, BotNavCodec::data(BotLayarRegistry::TANYA_AI, $sppg, $uid)),
            $jalur === 'n1_bantuan' => $this->tekan($user, BotNavCodec::data(BotLayarRegistry::BANTUAN, $sppg, $uid)),
            $jalur === 'n1_kedaluwarsa' => $this->tekan($user, BotNavCodec::data('rk', $sppg, $uid, time() - 31 * 60)),
            $jalur === 'n1_segarkan' => [$this->tekan($user, $data), $this->tekan($user, $data)],
            default => $this->tekan($user, $data),
        };

        $harap = $jalur === 'n1_segarkan' ? 2 : 1;
        $this->assertHanyaTombolMasuk($user, $uid, $harap);
        $this->assertSame([], LayarUji::$render, 'Layar tidak dirender');
        $this->assertSame(0, BotAksi::query()->count(), 'Tanpa token aksi');

        if (str_starts_with($jalur, 'n1_')) {
            $this->assertSame(BotCallbackHandler::PERLU_MASUK_SINGKAT, $this->jawabanTerakhir());
            $this->assertCount($harap, $this->telegramCalls('answerCallbackQuery'));
        }
    }

    /** Kontrol: dengan sesi penuh jalur yang sama merender layar (tes di atas tidak lolos secara kosong). */
    public function test_kontrol_dengan_sesi_penuh_layar_dirender(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->investorC);

        $this->ketik($this->kepalaA, '/rekap');
        $this->tekan($this->kepalaA, BotNavCodec::data('rk', $this->sppgA, (int) $this->kepalaA->telegram_id));
        $this->tekan($this->investorC, BotNavCodec::data('rk', $this->sppgC, (int) $this->investorC->telegram_id));

        $this->assertCount(3, LayarUji::$render);
    }

    /** Edit pesan asal (bila ada) hanya teks netral tanpa data dan tanpa keyboard. */
    private function assertNeutral(): void
    {
        foreach ($this->telegramCalls('editMessageText') as $edit) {
            $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_SESI, $edit['text']);
            $this->assertSame([], $this->markupOf($edit)['inline_keyboard'] ?? [], 'Edit netral tanpa keyboard');
        }
    }

    private function assertHanyaTombolMasuk(User $user, int $uid, int $jumlah): void
    {
        $metode = collect(Http::recorded())->map(fn (array $p) => basename((string) parse_url($p[0]->url(), PHP_URL_PATH)))->unique()->values()->all();
        $this->assertEmpty(array_diff($metode, ['sendMessage', 'answerCallbackQuery', 'editMessageText']), 'Metode tak terduga: '.implode(',', $metode));
        $this->assertNeutral();

        $pesan = $this->messagesTo($uid);
        $this->assertCount($jumlah, $pesan);

        $pesan->each(function (Request $p) use ($user): void {
            $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $p['text']);

            foreach (['SPPG A', 'SPPG B', 'SPPG C', 'LAYAR-', 'Menu SiGAP', $user->name] as $terlarang) {
                $this->assertStringNotContainsString($terlarang, (string) $p['text']);
            }

            $this->assertSame(
                [[['text' => TelegramUpdateHandler::LABEL_MASUK, 'web_app' => ['url' => 'https://sigap.test/miniapp/']]]],
                $this->markupOf($p)['inline_keyboard'],
            );
        });
    }
}

<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\User;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Aturan sesi tidak penuh (R-02): tanpa SesiService::punyaSesiPenuh bot HANYA menampilkan tombol masuk
 * ke Mini App, tanpa data apa pun, untuk SEMUA peran pada SEMUA jalur (perintah, callback, balasan catatan, teks bebas).
 * Tidak satu pun pengguna dalam kelas ini diberi sesi penuh.
 */
class BotSesiTidakPenuhTest extends BotTestCase
{
    private const PERAN = ['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorAB', 'investorC'];

    private const JALUR = [
        'start', 'menu', 'bantuan', 'batal', 'teks_bebas', 'balasan_catatan', 'balasan_foto_catatan',
        'cb_daftar', 'cb_pilih_sppg', 'cb_tinjau', 'cb_setuju', 'cb_tolak',
    ];

    private FakeLlmClient $llm;

    /** @return iterable<string, array{string, string}> */
    public static function peranDanJalur(): iterable
    {
        foreach (self::PERAN as $peran) {
            foreach (self::JALUR as $jalur) {
                yield "{$peran} x {$jalur}" => [$peran, $jalur];
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeLlmClient([LlmResponse::text('jawaban AI')]);
        $this->app->instance(LlmClient::class, $this->llm);
    }

    #[DataProvider('peranDanJalur')]
    public function test_tanpa_sesi_penuh_hanya_tombol_masuk(string $peran, string $jalur): void
    {
        $user = $this->pengguna($peran);
        $uid = (int) $user->telegram_id;

        // Data bisnis nyata ada (pengeluaran menunggu + pengajuan menu) agar kebocoran akan terlihat.
        $idKeluar = $this->pengeluaranMenunggu();
        $idMenu = $this->pengajuanMenu();

        $this->assertFalse(app(SesiService::class)->punyaSesiPenuh($user->fresh()));

        // Bersihkan rekaman HTTP dari penyiapan data (mis. notifikasi) agar hanya keluaran bot yang diperiksa.
        $this->fakeTelegram();

        match ($jalur) {
            'start' => $this->ketik($user, '/start'),
            'menu' => $this->ketik($user, '/menu'),
            'bantuan' => $this->ketik($user, '/bantuan'),
            'batal' => $this->ketik($user, '/batal'),
            'teks_bebas' => $this->ketik($user, 'berapa pengeluaran hari ini'),
            'balasan_catatan' => $this->balasCatatan($user, $idKeluar, 'Alasan yang sah dan cukup panjang'),
            'balasan_foto_catatan' => $this->balasCatatan($user, $idKeluar, null),
            default => $this->tekanTanpaSesi($user, substr($jalur, 3), $idKeluar),
        };

        $this->assertHanyaTombolMasuk($user, $uid, $jalur === 'start');

        // Tidak ada tombol callback / token baru, tidak ada keputusan, Agen AI tidak dipanggil.
        $this->assertSame([], $this->llm->calls);
        $this->assertSame('diajukan', $this->keluarRaw($idKeluar)->status_persetujuan->value);
        $this->assertSame('diajukan', $this->menuRaw($idMenu)->status->value);
        $this->assertSame(0, $this->jumlahAksi(BotAksi::STATUS_DIPAKAI) + $this->jumlahAksi(BotAksi::STATUS_SELESAI));

        if (str_starts_with($jalur, 'cb_')) {
            $this->assertSame(1, $this->jumlahAksi(BotAksi::STATUS_AKTIF), 'Token tidak dihabiskan tanpa sesi');
            $this->assertSame('Masuk lewat Mini App terlebih dahulu.', $this->jawabanTerakhir());
        } elseif (str_starts_with($jalur, 'balasan_')) {
            $this->assertSame(0, $this->jumlahAksi(BotAksi::STATUS_MENUNGGU_CATATAN), 'Jendela catatan ditutup');
        } else {
            $this->assertSame(0, BotAksi::query()->count(), 'Tanpa sesi tidak menerbitkan token apa pun');
        }
    }

    public function test_sesi_dicabut_kembali_ke_tombol_masuk_untuk_semua_peran(): void
    {
        foreach (self::PERAN as $peran) {
            $user = $this->pengguna($peran);
            $this->sesiPenuh($user);
            $this->ketik($user, '/menu');
            $this->assertNotSame(TelegramUpdateHandler::PERLU_MASUK, $this->teksLayar($user), "{$peran}: dengan sesi dapat menu");

            app(SesiService::class)->keluarSemua($user);
            $this->ketik($user, '/menu');
            $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->teksLayar($user), $peran);
            $this->ketik($user, '/bantuan');
            $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->teksLayar($user), $peran);
        }
    }

    // ------------------------------------------------------------------ pembantu

    private function pengguna(string $peran): User
    {
        return match ($peran) {
            'relawanDistribusi' => $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi),
            'relawanTanpaUnit' => $this->tanpaUnit(),
            default => $this->{$peran},
        };
    }

    private function tanpaUnit(): User
    {
        $user = $this->userOf(Role::Relawan, $this->sppgA);
        $user->forceFill(['unit' => null])->save();

        return $user->fresh();
    }

    /** Balasan ke prompt "alasan penolakan" yang masih terbuka, tetapi sesi sudah tidak penuh. */
    private function balasCatatan(User $user, int $idKeluar, ?string $teks): void
    {
        $prompt = 7001;
        $aksi = app(BotAksiService::class)->terbit($user, (int) $user->telegram_id, BotAksi::JENIS_TOLAK, $this->opsiKeluar($idKeluar));
        BotAksi::query()->whereKey($aksi->id)->update([
            'status' => BotAksi::STATUS_MENUNGGU_CATATAN,
            'catatan_expires_at' => now()->addMinutes(5),
            'prompt_message_id' => $prompt,
        ]);

        if ($teks !== null) {
            $this->ketik($user, $teks, $prompt);

            return;
        }

        $uid = (int) $user->telegram_id;
        $this->kirim($this->privateUpdate($uid, [
            'photo' => [['file_id' => 'f1', 'file_size' => 10]],
            'caption' => 'alasan di foto',
            'reply_to_message' => ['message_id' => $prompt, 'chat' => ['id' => $uid, 'type' => 'private'], 'date' => time()],
        ]));
    }

    private function tekanTanpaSesi(User $user, string $kunci, int $idKeluar): void
    {
        $jenis = match ($kunci) {
            'daftar' => BotAksi::JENIS_DAFTAR,
            'pilih_sppg' => BotAksi::JENIS_PILIH_SPPG,
            'tinjau' => BotAksi::JENIS_TINJAU,
            'setuju' => BotAksi::JENIS_SETUJU,
            'tolak' => BotAksi::JENIS_TOLAK,
        };
        $opsi = $this->opsiKeluar($idKeluar);
        $svc = app(BotAksiService::class);

        $this->tekan($user, $svc->callbackData($svc->terbit($user, (int) $user->telegram_id, $jenis, $opsi)));
    }

    /** @return array{sppg_id: int, subjek_tipe: string, subjek_id: int, subjek_versi: int} */
    private function opsiKeluar(int $id): array
    {
        return ['sppg_id' => $this->sppgA, 'subjek_tipe' => BotAksi::SUBJEK_PENGELUARAN, 'subjek_id' => $id, 'subjek_versi' => 1];
    }

    /**
     * Seluruh keluaran bot ke chat = satu pesan dengan satu tombol web_app "Masuk lewat Mini App" ke URL dasar Mini App.
     * Teks persis PERLU_MASUK (/start: salam netral SALAM_TANPA_SESI, tanpa nama pengguna/peran/SPPG/jumlah). Tanpa edit pesan.
     */
    private function assertHanyaTombolMasuk(User $user, int $uid, bool $salam): void
    {
        $semua = collect(Http::recorded())->map(fn (array $p) => $p[0]);
        $metode = $semua->map(fn (Request $r) => basename((string) parse_url($r->url(), PHP_URL_PATH)))->unique()->values()->all();
        $this->assertEmpty(array_diff($metode, ['sendMessage', 'answerCallbackQuery']), 'Metode Bot API tak terduga: '.implode(',', $metode));

        $pesan = $this->messagesTo($uid);
        $this->assertCount(1, $pesan, 'Tepat satu balasan');
        $teks = (string) $pesan->first()['text'];

        if ($salam) {
            $this->assertSame(TelegramUpdateHandler::SALAM_TANPA_SESI, $teks);
        } else {
            $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $teks);
        }

        // Tanpa data bisnis, nama SPPG, peran, atau jumlah.
        foreach (['SPPG A', 'SPPG B', 'SPPG C', $user->name, $user->role->label(), 'Rp', 'Gas elpiji', 'Nasi Telur Uji', '750'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $teks);
        }
        $this->assertSame(0, preg_match('/\d/', $teks), 'Tanpa angka/jumlah');

        $markup = $this->markupOf($pesan->first());
        $this->assertCount(1, $markup['inline_keyboard'], 'Satu baris');
        $this->assertCount(1, $markup['inline_keyboard'][0], 'Satu tombol');
        $tombol = $markup['inline_keyboard'][0][0];
        $this->assertSame('Masuk lewat Mini App', $tombol['text']);
        $this->assertSame('https://sigap.test/miniapp/', $tombol['web_app']['url']);
        $this->assertArrayNotHasKey('callback_data', $tombol);
    }
}

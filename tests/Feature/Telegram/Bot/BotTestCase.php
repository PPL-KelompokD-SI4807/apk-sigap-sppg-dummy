<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\BotAksi;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Pembelian\PembelianTestCase;

/**
 * Dasar tes Bot R-02. Investor AB memiliki SPPG A dan B (dua SPPG -> pemilih), investor C hanya SPPG C.
 * Semua data fiktif. Webhook dijalankan sinkron tanpa pengguna login (seperti job antrean sungguhan).
 */
abstract class BotTestCase extends PembelianTestCase
{
    private int $cbSeq = 0;

    /** @var list<array{message: string, context: array<string, mixed>}> */
    protected array $logBot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetActor();
        $this->logBot = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logBot[] = ['message' => $e->message, 'context' => $e->context];
        });
    }

    // ------------------------------------------------------------------ pengiriman update

    protected function kirim(array $update): void
    {
        $this->forgetActor();
        $this->flushHeaders();
        $this->webhook($update)->assertOk();
    }

    protected function ketik(User $user, string $text, ?int $balasKe = null): void
    {
        $this->kirim($this->privateUpdate((int) $user->telegram_id, array_filter([
            'text' => $text,
            'reply_to_message' => $balasKe === null ? null : ['message_id' => $balasKe, 'chat' => ['id' => (int) $user->telegram_id, 'type' => 'private'], 'date' => time()],
        ])));
    }

    /** Tekan tombol callback sebagai $user (chat pribadi $user sendiri kecuali $chatId diberikan). */
    protected function tekan(User $user, string $data, ?int $chatId = null, int $messageId = 55, string $chatType = 'private'): void
    {
        $this->kirim([
            'update_id' => 500000 + (++$this->cbSeq),
            'callback_query' => [
                'id' => 'cb'.$this->cbSeq,
                'from' => ['id' => (int) $user->telegram_id, 'is_bot' => false, 'first_name' => 'Siti'],
                'message' => ['message_id' => $messageId, 'date' => time(), 'chat' => ['id' => $chatId ?? (int) $user->telegram_id, 'type' => $chatType]],
                'chat_instance' => 'ci',
                'data' => $data,
            ],
        ]);
    }

    // ------------------------------------------------------------------ membaca keluaran bot

    /** Layar terakhir (sendMessage/editMessageText) ke chat. */
    protected function layar(int $chatId): ?Request
    {
        $hasil = null;

        foreach (Http::recorded() as [$request]) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ((str_ends_with($path, '/sendMessage') || str_ends_with($path, '/editMessageText')) && (string) $request['chat_id'] === (string) $chatId) {
                $hasil = $request;
            }
        }

        return $hasil;
    }

    /** @return list<array<string, mixed>> semua tombol pada layar terakhir */
    protected function tombolLayar(int $chatId): array
    {
        $layar = $this->layar($chatId);
        $markup = $layar === null ? null : $this->markupOf($layar);

        return $markup === null ? [] : array_merge(...($markup['inline_keyboard'] ?: [[]]));
    }

    protected function dataTombol(User $user, string $awalLabel): string
    {
        foreach ($this->tombolLayar((int) $user->telegram_id) as $tombol) {
            if (str_starts_with((string) $tombol['text'], $awalLabel) && isset($tombol['callback_data'])) {
                return (string) $tombol['callback_data'];
            }
        }

        $this->fail("Tombol '{$awalLabel}' tidak ada. Ada: ".implode(' | ', array_column($this->tombolLayar((int) $user->telegram_id), 'text')));
    }

    protected function adaTombol(User $user, string $awalLabel): bool
    {
        foreach ($this->tombolLayar((int) $user->telegram_id) as $tombol) {
            if (str_starts_with((string) $tombol['text'], $awalLabel)) {
                return true;
            }
        }

        return false;
    }

    protected function teksLayar(User $user): string
    {
        return (string) ($this->layar((int) $user->telegram_id)['text'] ?? '');
    }

    /** Teks toast jawaban callback terakhir. */
    protected function jawabanTerakhir(): ?string
    {
        $teks = null;

        foreach ($this->telegramCalls('answerCallbackQuery') as $request) {
            $teks = $request['text'] ?? null;
        }

        return $teks;
    }

    // ------------------------------------------------------------------ data uji

    protected function sesiPenuh(User ...$users): void
    {
        foreach ($users as $user) {
            $this->beriSesiPenuh($user);
        }
    }

    /** Pengajuan menu SPPG A berstatus diajukan; mengembalikan id pengajuan. */
    protected function pengajuanMenu(?int $sppgId = null): int
    {
        $menu = $this->menuSiapAjukan($sppgId);
        $this->forgetActor();

        return $this->ajukan($menu);
    }

    /** Pengeluaran SPPG A menunggu persetujuan (ambang 0 = semua diajukan); mengembalikan id. */
    protected function pengeluaranMenunggu(int $nominal = 750_000): int
    {
        $this->pencairan($this->sppgA, 10_000_000);

        $id = (int) $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', [
            'tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Gas elpiji 12 kg rahasia-uraian', 'nominal' => $nominal,
        ])->assertCreated()->json('data.id');
        $this->forgetActor();
        $this->flushHeaders();

        return $id;
    }

    protected function menuRaw(int $id): PengajuanMenu
    {
        return PengajuanMenu::query()->withoutGlobalScope(SppgScope::class)->findOrFail($id);
    }

    protected function keluarRaw(int $id): Pengeluaran
    {
        return Pengeluaran::query()->withoutGlobalScope(SppgScope::class)->findOrFail($id);
    }

    /** Dari /menu investor: tekan "Menunggu keputusan". */
    protected function bukaDaftar(User $investor): void
    {
        $this->ketik($investor, '/menu');
        $this->tekan($investor, $this->dataTombol($investor, 'Menunggu keputusan'));
    }

    /** Sampai layar tinjau pertama yang labelnya diawali $awal ("Tinjau menu" / "Tinjau pengeluaran"). */
    protected function bukaTinjau(User $investor, string $awal, ?string $sppgLabel = 'SPPG A'): void
    {
        $this->bukaDaftar($investor);

        if ($sppgLabel !== null && $this->adaTombol($investor, $sppgLabel)) {
            $this->tekan($investor, $this->dataTombol($investor, $sppgLabel));
        }

        $this->tekan($investor, $this->dataTombol($investor, $awal));
    }

    protected function jumlahAksi(string $status): int
    {
        return BotAksi::query()->where('status', $status)->count();
    }
}

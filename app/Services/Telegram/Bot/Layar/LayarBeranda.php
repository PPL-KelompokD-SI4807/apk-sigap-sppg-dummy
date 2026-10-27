<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\BotAksi;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotRingkasanService;
use App\Services\Telegram\Bot\BotTombol;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;

/**
 * Beranda Bot (layar `h`, R02-A2): ringkasan hari ini sesuai peran + tombol utama 2-3 kolom disaring dari
 * config/rbac.php lewat BotLayarRegistry::tombolBeranda + baris tetap (Tanya Agen AI, Bantuan, Segarkan, Buka Mini App).
 *
 * - Hanya sesi penuh (ditegakkan BotNavigasi); layar data hanya diberi tombol bila SPPG efektif sudah ada.
 * - Admin dan investor >1 SPPG tanpa SPPG terpilih: sapaan + Pilih SPPG saja (tanpa ringkasan, tanpa tombol data).
 * - Tombol keputusan investor tetap callback `b1` sekali pakai (BotAksiService); admin tidak pernah mendapatkannya.
 * - Tidak memuat nama pengguna atau data pribadi (sambutan bernama dibuat pemanggil di /start).
 */
final class LayarBeranda extends LayarBacaDasar
{
    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly BotRingkasanService $ringkasan,
        private readonly BotAksiService $aksi,
    ) {}

    public function id(): string
    {
        return BotLayarRegistry::BERANDA;
    }

    public function modul(): ?string
    {
        return null;
    }

    public function hak(): string
    {
        return self::HAK_SESI;
    }

    public function induk(): ?string
    {
        return null;
    }

    public function butuhSppg(): bool
    {
        return false;
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $registry = app(BotLayarRegistry::class);
        $e = TelegramBotService::escapeHtml(...);
        $zona = (string) config('operasional.timezone', 'Asia/Jakarta');
        $nama = $sppgId === null ? null : Sppg::query()->withoutGlobalScopes()->whereKey($sppgId)->value('nama');

        $teks = ['<b>Menu SiGAP</b>'.($nama === null ? '' : ' - <b>'.$e((string) $nama).'</b>')];

        if ($konteks->catatan !== null) {
            $teks[] = '<i>'.$e($konteks->catatan).'</i>';
        }

        if ($sppgId === null) {
            $teks[] = BotLayarRegistry::bisaMemilihSppg($user)
                ? 'Pilih SPPG terlebih dahulu untuk melihat ringkasan dan menu data.'
                : 'Akun Anda belum terhubung ke SPPG mana pun. Hubungi Admin.';
        } else {
            $baris = $this->ringkasan->untuk($user, $sppgId);

            if ($baris !== []) {
                $teks[] = "\n<b>Ringkasan hari ini</b> (".Carbon::now($zona)->format('d/m/Y').')';
                $teks[] = implode("\n", array_map(fn (string $b) => '- '.$b, $baris));
            }

            $teks[] = "\nPilih menu di bawah.";
        }

        $teks[] = '<i>Diperbarui '.Carbon::now($zona)->format('H:i').'</i>';

        $tombol = [];

        foreach ($registry->tombolBeranda($user, $sppgId !== null) as $t) {
            $tombol[] = $t['keputusan']
                ? $this->aksi->tombol($t['label'], $this->aksi->terbitAtauPakai($user, $konteks->chatId, BotAksi::JENIS_DAFTAR, ['sppg_id' => $sppgId]))
                : $konteks->tombol($t['label'], $t['layar'], $sppgId);
        }

        $baris = BotTombol::susun($tombol);

        // Baris tetap semua peran: Tanya Agen AI (hanya bila ada tool untuk peran ini) dan Bantuan; Segarkan dan Buka Mini App.
        $atas = array_values(array_filter([
            $registry->cari(BotLayarRegistry::TANYA_AI) !== null && LayarTanyaAi::tersediaUntuk($user)
                ? $konteks->tombol('Tanya Agen AI', BotLayarRegistry::TANYA_AI, $sppgId) : null,
            $registry->cari(BotLayarRegistry::BANTUAN) !== null ? $konteks->tombol('Bantuan', BotLayarRegistry::BANTUAN, $sppgId) : null,
        ]));
        $bawah = array_values(array_filter([
            $konteks->tombol('Segarkan', BotLayarRegistry::BERANDA, $sppgId),
            TelegramBotService::webAppButton('Buka Mini App', $this->bot->miniAppUrl()),
        ]));

        foreach ([$atas, $bawah] as $b) {
            if ($b !== []) {
                $baris[] = $b;
            }
        }

        return new BotLayar(implode("\n", $teks), ['inline_keyboard' => $baris], true, true);
    }
}

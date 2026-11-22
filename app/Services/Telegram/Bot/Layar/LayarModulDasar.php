<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Enums\Role;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotLayarService;
use App\Services\Telegram\Bot\BotTombol;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Dasar layar baca modul (R02-B1): kerangka teks (judul + nama SPPG, isi, "Diperbarui HH:mm" agar Segarkan
 * biasanya berubah), tombol paginasi, kaki navigasi (Kembali/Beranda/Segarkan), dan tombol "Buka di Mini App".
 *
 * Subkelas HANYA menyajikan: hak (sesi, RBAC modul, SPPG) sudah ditegakkan BotNavigasi sebelum render() dipanggil,
 * dan data hanya boleh berasal dari tool baca katalog (BotLayarService) atau service dengan sppg_id eksplisit.
 */
abstract class LayarModulDasar extends LayarBacaDasar
{
    /** Judul layar (teks biasa). */
    abstract protected function judul(): string;

    /** Rute Mini App untuk tombol "Buka di Mini App" (mis. '/jadwal'); null = tanpa tombol. */
    protected function rute(): ?string
    {
        return null;
    }

    /** Halaman maksimum layar ini (bawaan: batas tool baca). */
    protected function halamanMaks(): int
    {
        return BotLayarService::HALAMAN_MAKS;
    }

    /**
     * @param  list<string>  $isi  baris HTML yang SUDAH di-escape (tanggung jawab subkelas)
     * @param  bool  $berhalaman  layar punya paginasi (halaman dari $konteks->halaman)
     * @param  bool  $adaLagi  ada halaman berikutnya
     * @param  list<list<array<string, mixed>>>  $barisTombol  tombol tambahan di atas paginasi dan kaki
     * @param  bool  $namaSppg  tampilkan nama SPPG terpilih pada judul
     */
    protected function susun(User $user, ?int $sppgId, KonteksLayar $konteks, array $isi, bool $berhalaman = false, bool $adaLagi = false, array $barisTombol = [], bool $namaSppg = true): BotLayar
    {
        $halaman = $berhalaman ? $konteks->halaman : 0;
        $nama = $sppgId === null || ! $namaSppg ? null : Sppg::query()->withoutGlobalScopes()->whereKey($sppgId)->value('nama');
        $judul = '<b>'.TelegramBotService::escapeHtml($this->judul()).'</b>'
            .($nama === null ? '' : ' - <b>'.TelegramBotService::escapeHtml((string) $nama).'</b>')
            .($halaman > 0 ? ' (hal. '.($halaman + 1).')' : '');

        $teks = [$judul, ...$isi];

        if ($berhalaman && $adaLagi && $halaman >= $this->halamanMaks() - 1) {
            $teks[] = '<i>Daftar dibatasi '.(BotLayarService::PER_HALAMAN * $this->halamanMaks()).' baris. Selengkapnya di Mini App.</i>';
        }

        $teks[] = '<i>Diperbarui '.Carbon::now(FormatLayar::zona())->format('H:i').'</i>';

        $papan = $barisTombol;

        if ($berhalaman) {
            $navigasi = array_values(array_filter([
                $halaman > 0 ? $konteks->tombolHalaman('Sebelumnya', $this->id(), $sppgId, $halaman - 1) : null,
                $adaLagi && $halaman < $this->halamanMaks() - 1 ? $konteks->tombolHalaman('Berikutnya', $this->id(), $sppgId, $halaman + 1) : null,
            ]));

            if ($navigasi !== []) {
                $papan[] = $navigasi;
            }
        }

        $papan = [...$papan, ...BotTombol::kaki($konteks, BotLayarRegistry::idHalaman($this->id(), $halaman), $this->induk(), $sppgId, $this->tombolAplikasi($user, $sppgId))];

        return new BotLayar(implode("\n", $teks), ['inline_keyboard' => $papan], true, true);
    }

    /** @return array<string, mixed>|null */
    private function tombolAplikasi(User $user, ?int $sppgId): ?array
    {
        $rute = $this->rute();

        if ($rute === null) {
            return null;
        }

        // Investor: petunjuk ?sppg= (server tetap memvalidasi pivot investor_sppg).
        $query = ['r' => $rute] + ($user->role === Role::Investor && $sppgId !== null ? ['sppg' => $sppgId] : []);

        return TelegramBotService::webAppButton('Buka di Mini App', app(TelegramBotService::class)->miniAppUrl($query, $rute));
    }

    /**
     * Policy viewAny untuk SEMUA model (pemeriksaan tambahan di atas RBAC modul; dijalankan BotNavigasi di dalam ActingUser).
     *
     * @param  class-string  ...$model
     */
    protected function bolehLihat(User $user, string ...$model): bool
    {
        foreach ($model as $kelas) {
            if (! Gate::forUser($user)->allows('viewAny', $kelas)) {
                return false;
            }
        }

        return true;
    }

    protected static function e(mixed $nilai): string
    {
        return TelegramBotService::escapeHtml((string) $nilai);
    }
}

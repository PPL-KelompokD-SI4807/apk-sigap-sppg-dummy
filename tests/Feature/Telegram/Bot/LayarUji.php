<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Ai\Catalog\SppgBound;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\Bot\Layar\LayarBaca;
use Closure;

/**
 * Layar baca palsu untuk menguji router navigasi (R02-A1) tanpa membangun layar sungguhan (A2/B1).
 * render() mencatat pemanggilan dan menampilkan penanda data per SPPG (jumlah titik layanan lewat SppgBound)
 * sehingga kebocoran/salah SPPG terlihat di keluaran.
 */
final class LayarUji implements LayarBaca
{
    /** @var list<array{layar: string, user: int, sppg: ?int}> */
    public static array $render = [];

    /**
     * @param  (Closure(User, ?int): bool)|null  $izinkan
     * @param  (Closure(User, ?int): void)|null  $efek  efek samping di dalam render (mis. mencoba menulis)
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $modul,
        private readonly string $hak = self::HAK_BACA,
        private readonly bool $butuhSppg = true,
        private readonly int $jeda = 0,
        private readonly ?Closure $izinkan = null,
        private readonly ?Closure $efek = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function modul(): ?string
    {
        return $this->modul;
    }

    public function hak(): string
    {
        return $this->hak;
    }

    public function induk(): ?string
    {
        return BotLayarRegistry::BERANDA;
    }

    /**
     * Layar uji untuk SEMUA entri layar data pada BotLayarRegistry::MENU (modul/hak persis seperti matriks),
     * dipakai tes matriks tombol per peran.
     *
     * @return list<self>
     */
    public static function untukMenu(): array
    {
        $unik = [];

        foreach (BotLayarRegistry::MENU as $entri) {
            foreach ($entri as [$id, , $modul, $hak]) {
                // Dashboard (`db`) adalah layar sungguhan HAK_SESI (LayarDashboard), bukan layar uji bermodul.
                if (! in_array($hak, [BotLayarRegistry::HAK_PILIH, BotLayarRegistry::HAK_PUTUSKAN, BotLayarRegistry::HAK_DASHBOARD], true)) {
                    $unik[$id] ??= new self($id, $modul, $hak);
                }
            }
        }

        return array_values($unik);
    }

    public function butuhSppg(): bool
    {
        return $this->butuhSppg;
    }

    public function jedaDetik(): int
    {
        return $this->jeda;
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return $this->izinkan === null || ($this->izinkan)($user, $sppgId);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        self::$render[] = ['layar' => $this->id, 'user' => (int) $user->getKey(), 'sppg' => $sppgId];

        if ($this->efek !== null) {
            ($this->efek)($user, $sppgId);
        }

        $titik = SppgBound::apply(TitikLayanan::query(), $user)->count();

        return new BotLayar(
            "LAYAR-{$this->id} SPPG-".($sppgId ?? 'none')." TITIK-{$titik}",
            ['inline_keyboard' => [[
                $konteks->tombol('Segarkan', $this->id, $sppgId),
                $konteks->tombol('Beranda', 'h', $sppgId),
            ]]],
        );
    }
}

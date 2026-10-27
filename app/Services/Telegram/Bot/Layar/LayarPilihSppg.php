<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\Sppg;
use App\Models\User;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Support\SppgAktif;

/**
 * Pemilih SPPG (layar `ps`): admin (seluruh SPPG aktif) dan investor >1 SPPG (hanya SPPG miliknya menurut pivot).
 * Memilih = membuka beranda dengan SPPG itu pada token `n1`; hak SPPG tetap diverifikasi ulang BotNavigasi
 * pada klik berikutnya (pivot untuk investor; keberadaan SPPG untuk admin). Hanya nama SPPG yang ditampilkan.
 * Paginasi: 8 SPPG per halaman (urut nama, id), sampai BotLayarRegistry::HALAMAN_MAKS halaman.
 */
final class LayarPilihSppg extends LayarModulDasar
{
    public const MAKS = 8;

    public function id(): string
    {
        return BotLayarRegistry::PILIH_SPPG;
    }

    public function modul(): ?string
    {
        return null;
    }

    public function hak(): string
    {
        return self::HAK_SESI;
    }

    public function butuhSppg(): bool
    {
        return false;
    }

    protected function judul(): string
    {
        return 'Pilih SPPG';
    }

    protected function halamanMaks(): int
    {
        return BotLayarRegistry::HALAMAN_MAKS;
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return BotLayarRegistry::bisaMemilihSppg($user);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        if ($user->isAdmin()) {
            $dasar = Sppg::query()->aktif();
        } else {
            $dasar = Sppg::query()->whereIn('id', app(SppgAktif::class)->milik($user) ?: [0]);
        }

        $halaman = min($konteks->halaman, $this->halamanMaks() - 1);
        $total = (clone $dasar)->count();
        $daftar = $dasar->orderBy('nama')->orderBy('id')->offset($halaman * self::MAKS)->limit(self::MAKS)->get(['id', 'nama']);
        $adaLagi = $total > ($halaman + 1) * self::MAKS;

        $tombol = $daftar->map(fn (Sppg $s) => [$konteks->tombol(mb_substr((string) $s->nama, 0, 40), BotLayarRegistry::BERANDA, (int) $s->id)])->all();
        $isi = ['Pilih SPPG yang ingin dilihat. Tampilan bersifat baca-saja.'];

        if ($total === 0) {
            $isi[] = 'Tidak ada SPPG yang dapat dipilih.';
        } elseif ($total > $this->halamanMaks() * self::MAKS) {
            $isi[] = self::e(sprintf('Hanya %d SPPG pertama yang dapat dipilih dari bot (total %d).', $this->halamanMaks() * self::MAKS, $total));
        }

        return $this->susun($user, $sppgId, $konteks, $isi, true, $adaLagi, $tombol, namaSppg: false);
    }
}

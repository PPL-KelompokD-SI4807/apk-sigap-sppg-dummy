<?php

namespace App\Services\Telegram\Bot;

use App\Services\Telegram\Bot\Layar\KonteksLayar;

/**
 * Pembangun papan tombol inline Bot (R-02 v2): susunan 2-3 kolom dan kaki navigasi (Kembali / Beranda / Segarkan).
 * Hanya menyusun tombol; tidak memberi hak apa pun (tujuan `n1` dicek ulang saat diklik oleh BotNavigasi).
 */
final class BotTombol
{
    /** Label sependek ini (karakter) boleh 3 kolom; selebihnya 2 kolom. */
    public const LEBAR_PENDEK = 12;

    public const KOLOM_MAKS = 3;

    public const KOLOM_PANJANG = 2;

    /**
     * Susun tombol menjadi baris 2-3 kolom: 3 kolom hanya bila semua label dalam baris pendek, selain itu 2.
     *
     * @param  list<array<string, mixed>|null>  $tombol  null (mis. web_app non-HTTPS) dilewati
     * @return list<list<array<string, mixed>>>
     */
    public static function susun(array $tombol): array
    {
        $baris = [];
        $kini = [];

        foreach (array_values(array_filter($tombol)) as $t) {
            $kandidat = [...$kini, $t];
            $kapasitas = self::semuaPendek($kandidat) ? self::KOLOM_MAKS : self::KOLOM_PANJANG;

            if (count($kandidat) > $kapasitas) {
                $baris[] = $kini;
                $kini = [$t];
            } else {
                $kini = $kandidat;
            }
        }

        if ($kini !== []) {
            $baris[] = $kini;
        }

        return $baris;
    }

    /**
     * Kaki layar turunan: Kembali (ke induk), Beranda (hanya bila induk bukan beranda), Segarkan (layar ini).
     * `$bukaDiAplikasi` = baris tambahan tombol web_app "Buka di Mini App" (null = tidak ada).
     *
     * @param  array<string, mixed>|null  $bukaDiAplikasi
     * @return list<list<array<string, mixed>>>
     */
    public static function kaki(KonteksLayar $konteks, string $layarId, ?string $induk, ?int $sppgId, ?array $bukaDiAplikasi = null): array
    {
        $induk ??= BotLayarRegistry::BERANDA;
        $baris = [$konteks->tombol('Kembali', $induk, $sppgId)];

        if ($induk !== BotLayarRegistry::BERANDA) {
            $baris[] = $konteks->tombol('Beranda', BotLayarRegistry::BERANDA, $sppgId);
        }

        $baris[] = $konteks->tombol('Segarkan', $layarId, $sppgId);

        return array_values(array_filter([$baris, $bukaDiAplikasi === null ? null : [$bukaDiAplikasi]]));
    }

    /** @param list<array<string, mixed>> $baris */
    private static function semuaPendek(array $baris): bool
    {
        foreach ($baris as $t) {
            if (mb_strlen((string) ($t['text'] ?? '')) > self::LEBAR_PENDEK) {
                return false;
            }
        }

        return true;
    }
}

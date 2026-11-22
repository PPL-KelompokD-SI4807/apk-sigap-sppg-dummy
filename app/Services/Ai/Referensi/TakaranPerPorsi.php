<?php

namespace App\Services\Ai\Referensi;

/**
 * Takaran komposisi menu dari chat ("10 gram", "0,01 kg", "5 ml") -> nilai kolom `gram_per_porsi` (decimal 10,2).
 *
 * Konvensi sistem (config produksi.konversi_gram, BiayaMenuService): nilai komposisi bahan bersatuan stok massa
 * atau satuan lain (butir, ikat) = GRAM; bahan bersatuan stok volume (ml/liter) = ML (dibandingkan dengan stok
 * sebagai 1 ml = 1 satuan dasar). Karena itu:
 *   - takaran massa untuk bahan bersatuan volume, atau takaran volume untuk bahan bersatuan non-volume = TIDAK
 *     kompatibel -> ditanyakan (tidak pernah memakai massa jenis tebakan);
 *   - satuan tak dikenal (butir, sdm, potong) -> ditanyakan;
 *   - hasil > 2 desimal, <= 0, atau > 100000 -> ditanyakan (tidak dibulatkan diam-diam).
 */
final class TakaranPerPorsi
{
    public const MAKS = 100000;

    /**
     * @return array{0: int|float|null, 1: string|null} [nilai gram_per_porsi, pesan galat untuk ditanyakan]
     */
    public static function hitung(string $angka, string $satuan, KandidatReferensi $bahan): array
    {
        $kenal = KonversiSatuan::kenali($satuan);
        $label = $bahan->label();

        if ($kenal === null) {
            return [null, 'Satuan "'.self::potong($satuan).'" untuk '.$label.' belum dapat dikonversi; sebutkan takaran dalam '.self::saran($bahan).'.'];
        }

        [$dimensi, $pangkat] = $kenal;
        $volumeMaster = KonversiSatuan::dimensi($bahan->satuan) === KonversiSatuan::VOLUME;

        if ($dimensi === KonversiSatuan::VOLUME && ! $volumeMaster) {
            return [null, $label.' tidak tercatat dalam satuan volume; sebutkan takaran dalam '.self::saran($bahan).'.'];
        }

        if ($dimensi === KonversiSatuan::MASSA && $volumeMaster) {
            return [null, $label.' tercatat dalam satuan volume; sebutkan takaran dalam ml atau liter, bukan berat.'];
        }

        $dasar = KonversiSatuan::geser($angka, $pangkat);

        if (KonversiSatuan::jumlahDesimal($dasar) > 2 || (float) $dasar <= 0) {
            return [null, 'Takaran '.$label.' terlalu kecil/terlalu presisi (maks 2 desimal '.KonversiSatuan::labelDasar($dimensi).').'];
        }

        if ((float) $dasar > self::MAKS) {
            return [null, 'Takaran '.$label.' melebihi batas '.self::MAKS.' '.KonversiSatuan::labelDasar($dimensi).' per porsi.'];
        }

        return [KonversiSatuan::keJson($dasar), null];
    }

    /** Label satuan nilai tersimpan untuk bahan ini ("gram" atau "ml"). */
    public static function labelNilai(?string $satuanMaster): string
    {
        return KonversiSatuan::labelDasar(KonversiSatuan::dimensi($satuanMaster) === KonversiSatuan::VOLUME ? KonversiSatuan::VOLUME : KonversiSatuan::MASSA);
    }

    private static function saran(KandidatReferensi $bahan): string
    {
        return KonversiSatuan::dimensi($bahan->satuan) === KonversiSatuan::VOLUME ? 'ml atau liter' : 'gram atau kg';
    }

    private static function potong(string $teks): string
    {
        return mb_substr(ResolverReferensi::normalisasi($teks), 0, 20);
    }
}

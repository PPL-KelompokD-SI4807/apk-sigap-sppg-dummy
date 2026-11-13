<?php

namespace App\Services\Ai\Catalog;

use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Referensi\JenisReferensi;
use App\Services\Ai\Referensi\KandidatReferensi;
use App\Services\Ai\Referensi\ResolverReferensi;
use App\Services\Pembelian\PembelianBahanService;

/**
 * `buat_pembelian_bahan` / `ubah_pembelian_bahan`: skema, validasi, hak, dan eksekusi SAMA dengan CatalogWriteTool
 * (draf saja; nota diunggah di Mini App). Yang berbeda hanya RINGKASAN yang dibangun server untuk chat: nama bahan +
 * satuan dan nama pemasok dibaca dari basis data SPPG pengguna (ResolverReferensi::banyakId, hak baca dicek), total
 * dihitung dengan rumus server (PembelianBahanService::hitungTotal). Tanpa id internal dan tanpa skor keyakinan.
 */
class PembelianBahanWriteTool extends CatalogWriteTool
{
    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $dasar = parent::ringkasan($user, $draft);
        $p = (array) $draft->payload;
        [$bahan, $pemasok] = $this->rujukanDraf($user, $p);
        [$jumlah, $harga, $total] = self::angkaDraf($p);
        $baris = [];

        if (array_key_exists('bahan_baku_id', $p)) {
            $baris[] = 'Bahan: '.($bahan?->nama ?? '(belum dipilih)');
        }

        if (array_key_exists('pemasok_id', $p)) {
            $baris[] = 'Pemasok: '.($pemasok?->nama ?? '(belum dipilih)');
        }

        if (is_string($p['tanggal_beli'] ?? null)) {
            $baris[] = 'Tanggal beli: '.self::satuBaris($p['tanggal_beli'], 20);
        }

        if ($jumlah !== null) {
            $baris[] = 'Jumlah: '.self::angka($jumlah).($bahan?->satuan !== null ? ' '.$bahan->satuan : '');
        }

        if ($harga !== null) {
            $baris[] = 'Harga satuan: '.self::rupiah($harga);
        }

        if ($total !== null) {
            $baris[] = 'Total: '.self::rupiah($total);
        }

        if (is_string($p['catatan'] ?? null) && trim($p['catatan']) !== '') {
            $baris[] = 'Catatan: '.self::satuBaris($p['catatan'], 120);
        }

        return new RingkasanDraf($dasar->judul, $baris, $dasar->target, $dasar->peringatan);
    }

    /** Mis. "Draf pembelian: Ayam 10 kg x Rp30.000 = Rp300.000, pemasok Pasar Segar". */
    public function kalimatDraf(User $user, AiDraft $draft): string
    {
        $p = (array) $draft->payload;
        [$bahan, $pemasok] = $this->rujukanDraf($user, $p);
        [$jumlah, $harga, $total] = self::angkaDraf($p);

        $isi = trim(implode(' ', array_filter([
            $bahan?->nama ?? (array_key_exists('bahan_baku_id', $p) ? '(bahan belum dipilih)' : null),
            $jumlah === null ? null : self::angka($jumlah).($bahan?->satuan !== null ? ' '.$bahan->satuan : ''),
            $harga === null ? null : 'x '.self::rupiah($harga),
            $total === null ? null : '= '.self::rupiah($total),
        ])));

        if ($pemasok !== null) {
            $isi .= ($isi === '' ? '' : ', ').'pemasok '.$pemasok->nama;
        }

        $awal = $this->action === 'U' ? 'Draf ubah pembelian' : 'Draf pembelian';

        return self::satuBaris($isi === '' ? $awal : $awal.': '.$isi, 300);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{0: ?KandidatReferensi, 1: ?KandidatReferensi}
     */
    private function rujukanDraf(User $user, array $p): array
    {
        $resolver = app(ResolverReferensi::class);
        $idBahan = ResolverReferensi::idBulat($p['bahan_baku_id'] ?? null);
        $idPemasok = ResolverReferensi::idBulat($p['pemasok_id'] ?? null);

        $bahan = $idBahan === null ? [] : $resolver->banyakId($user, JenisReferensi::bahanBaku(), [$idBahan]);
        $pemasok = $idPemasok === null ? [] : $resolver->banyakId($user, JenisReferensi::semua()['pemasok'], [$idPemasok]);

        return [$bahan[$idBahan] ?? null, $pemasok[$idPemasok] ?? null];
    }

    /**
     * Jumlah dan harga satuan sebagai string desimal; total = rumus server (dibulatkan ke rupiah) bila keduanya ada.
     *
     * @param  array<string, mixed>  $p
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private static function angkaDraf(array $p): array
    {
        $jumlah = self::desimal($p['jumlah'] ?? null, 3);
        $harga = self::desimal($p['harga_satuan'] ?? null, 2);
        $total = $jumlah !== null && $harga !== null ? PembelianBahanService::hitungTotal($jumlah, $harga) : null;

        return [$jumlah, $harga, $total];
    }

    /** Angka dari payload -> string desimal biasa (tanpa notasi ilmiah), null bila bukan angka sah. */
    private static function desimal(mixed $nilai, int $skala): ?string
    {
        $teks = match (true) {
            is_int($nilai) => (string) $nilai,
            is_float($nilai) && is_finite($nilai) => sprintf('%.'.$skala.'F', $nilai),
            is_string($nilai) => trim($nilai),
            default => '',
        };

        return preg_match('/^\d{1,15}(?:\.\d{1,6})?$/', $teks) === 1 ? $teks : null;
    }

    /** "12500.50" -> "12.500,5"; "10.000" -> "10" (format Indonesia, nol di belakang koma dibuang). */
    private static function angka(string $nilai): string
    {
        [$bulat, $pecahan] = array_pad(explode('.', $nilai, 2), 2, '');
        $bulat = ltrim($bulat, '0');
        $pecahan = rtrim($pecahan, '0');
        $bulat = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $bulat === '' ? '0' : $bulat);

        return $bulat.($pecahan === '' ? '' : ','.$pecahan);
    }

    private static function rupiah(string $nilai): string
    {
        return 'Rp'.self::angka($nilai);
    }
}

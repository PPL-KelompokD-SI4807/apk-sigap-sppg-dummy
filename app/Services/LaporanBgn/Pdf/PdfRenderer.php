<?php

namespace App\Services\LaporanBgn\Pdf;

/**
 * Antarmuka render PDF laporan BGN (R07-3). Implementasi saat ini: FpdfRenderer (setasign/fpdf).
 * Pustaka dapat diganti tanpa mengubah agregator LaporanBgnService maupun job pengirim.
 */
interface PdfRenderer
{
    /**
     * Tulis PDF satu laporan ke berkas $tujuan.
     *
     * @param  array<string, mixed>  $laporan  keluaran LaporanBgnService::susun()
     * @param  iterable<array{label: string, jpeg: string|null}>  $bukti  foto nota (JPEG sudah diperkecil), dibaca satu per satu
     * @param  array{bukti_dilewati?: int}  $opsi
     */
    public function render(array $laporan, iterable $bukti, string $tujuan, array $opsi = []): void;
}

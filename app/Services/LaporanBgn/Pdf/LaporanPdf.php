<?php

namespace App\Services\LaporanBgn\Pdf;

use FPDF;

/**
 * Dokumen FPDF laporan BGN: Folio (mm), font inti Helvetica (cp1252), nomor halaman dengan alias acak
 * per dokumen sehingga teks pengguna tidak dapat memicu penggantian "{nb}". Pemotongan halaman tabel dikelola
 * TabelPdf; auto page break aktif untuk teks bebas.
 */
class LaporanPdf extends FPDF
{
    public string $orientasi;

    private readonly string $alias;

    /** @param array{0: float, 1: float} $kertas lebar x tinggi potret (mm) */
    public function __construct(string $orientasi, array $kertas, public readonly float $margin = 12.0)
    {
        parent::__construct($orientasi, 'mm', $kertas);
        $this->orientasi = $orientasi;
        $this->alias = '{sigap'.bin2hex(random_bytes(6)).'}';
        $this->SetMargins($margin, $margin, $margin);
        $this->SetAutoPageBreak(true, 14);
        $this->SetCreator('SiGAP SPPG');
        $this->SetAuthor('SiGAP SPPG');
        $this->SetCompression(true);
        $this->AliasNbPages($this->alias);
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetFont('Helvetica', 'I', 6.5);
        $this->SetTextColor(90, 90, 90);
        $lebar = $this->GetPageWidth() - 2 * $this->margin;
        $this->Cell($lebar * 0.75, 4, Teks::tetap('Dihasilkan oleh SiGAP SPPG, bukan keluaran aplikasi resmi BGN'), 0, 0, 'L');
        $this->Cell($lebar * 0.25, 4, 'Halaman '.$this->PageNo().' dari '.$this->alias, 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    public function halaman(): void
    {
        $this->AddPage($this->orientasi);
    }

    public function lebarIsi(): float
    {
        return $this->GetPageWidth() - 2 * $this->margin;
    }

    /** Sisa tinggi (mm) sampai batas pemotongan halaman. */
    public function sisaRuang(): float
    {
        return $this->PageBreakTrigger - $this->GetY();
    }

    /** Pastikan $tinggi muat; bila tidak, halaman baru. */
    public function butuhRuang(float $tinggi): void
    {
        if ($this->sisaRuang() < $tinggi) {
            $this->halaman();
        }
    }

    public function teksBaris(string $teks, float $tinggi = 4.5, string $gaya = '', float $ukuran = 9, string $rata = 'L'): void
    {
        $this->SetFont('Helvetica', $gaya, $ukuran);
        $this->MultiCell(0, $tinggi, $teks, 0, $rata);
    }
}

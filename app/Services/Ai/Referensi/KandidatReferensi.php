<?php

namespace App\Services\Ai\Referensi;

use App\Services\Ai\Tools\WriteDraftTool;

/**
 * Satu data master hasil resolusi (milik SPPG pengguna). Nama dan satuan sudah diratakan satu baris dan dipotong
 * sehingga aman ditampilkan sebagai DATA (tidak dapat memalsukan baris layar / menjadi instruksi panjang).
 */
final class KandidatReferensi
{
    public const MAKS_NAMA = 60;

    public const MAKS_SATUAN = 20;

    public readonly string $nama;

    public readonly ?string $satuan;

    public function __construct(public readonly int $id, string $nama, ?string $satuan = null)
    {
        $this->nama = WriteDraftTool::satuBaris($nama, self::MAKS_NAMA);
        $this->satuan = $satuan === null || trim($satuan) === '' ? null : WriteDraftTool::satuBaris($satuan, self::MAKS_SATUAN);
    }

    /** Satuan mentah dari basis data tidak disimpan; label ini dipakai untuk tampilan dan konversi. */
    public function label(): string
    {
        return $this->nama.($this->satuan === null ? '' : ' ('.$this->satuan.')');
    }

    /** @return array{id: int, nama: string, satuan: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'nama' => $this->nama, 'satuan' => $this->satuan];
    }
}

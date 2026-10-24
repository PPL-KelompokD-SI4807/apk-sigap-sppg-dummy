<?php

namespace App\Services\Ai\Tools\TitikLayanan;

use App\Enums\JenisTitikLayanan;
use App\Http\Requests\TitikLayanan\StoreTitikLayananRequest;
use App\Models\TitikLayanan;
use App\Services\Ai\Tools\WriteDraftTool;

/**
 * Contoh tool TULIS (pola WriteDraftTool): menghasilkan AiDraft modul titik-layanan aksi create.
 * Payload divalidasi dry-run dengan StoreTitikLayananRequest; pengguna menyimpan lewat
 * POST /api/v1/titik-layanan {..., ai_draft_id} dari Mini App.
 * Contoh masukan: "Tambah SDN 2 Sukamaju, 3,5 km, 240 siswa, PIC Bu Rina".
 */
class BuatTitikLayananTool extends WriteDraftTool
{
    protected string $name = 'buat_titik_layanan';

    protected string $description = 'Siapkan DRAF titik layanan baru (sekolah/PAUD/posyandu) untuk diperiksa pengguna di Mini App. Data belum tersimpan sampai pengguna menekan Simpan. Isi hanya kolom yang disebut pengguna.';

    protected string $module = 'titik-layanan';

    protected string $action = 'C';

    protected ?string $model = TitikLayanan::class;

    protected function formRequest(): ?string
    {
        return StoreTitikLayananRequest::class;
    }

    protected function fields(): array
    {
        return [
            'properties' => [
                'nama' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Nama titik layanan, mis. SDN 2 Sukamaju.'],
                'jenis' => ['type' => 'string', 'enum' => JenisTitikLayanan::values()],
                'alamat' => ['type' => 'string', 'maxLength' => 255],
                'jarak_km' => ['type' => 'number', 'minimum' => 0, 'maximum' => 9999.99, 'description' => 'Jarak dari SPPG (km).'],
                'jumlah_sasaran' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                'pic_nama' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Nama PIC penerima di titik layanan.'],
                'pic_telepon' => ['type' => 'string', 'maxLength' => 20],
            ],
            'required' => ['nama'],
        ];
    }
}

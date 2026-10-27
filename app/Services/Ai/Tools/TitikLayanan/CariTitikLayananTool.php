<?php

namespace App\Services\Ai\Tools\TitikLayanan;

use App\Enums\JenisTitikLayanan;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Ai\Catalog\RecordPresenter;
use App\Services\Ai\Catalog\SppgBound;
use App\Services\Ai\Tools\ReadTool;

/**
 * Contoh tool BACA (pola ReadTool). Tersedia bagi peran dengan R pada modul titik-layanan.
 * Query biasa (SppgScope aktif lewat ActingUser) + Policy viewAny; data PIC diminimalkan
 * (nomor telepon dan chat id tidak dikirim ke LLM).
 */
class CariTitikLayananTool extends ReadTool
{
    protected string $name = 'cari_titik_layanan';

    protected string $description = 'Cari titik layanan (sekolah, PAUD, posyandu) milik SPPG pengguna berdasarkan nama/alamat/PIC. Mengembalikan id, nama, jenis, alamat, jarak_km, jumlah_sasaran, nama PIC, dan status aktif.';

    protected string $module = 'titik-layanan';

    protected ?string $model = TitikLayanan::class;

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Kata kunci nama, alamat, atau nama PIC.'],
                'jenis' => ['type' => 'string', 'enum' => JenisTitikLayanan::values()],
                'aktif' => ['type' => 'boolean', 'description' => 'Filter status aktif.'],
                'batas' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Jumlah hasil (default 10).'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    protected function handle(User $user, array $arguments): array
    {
        $query = SppgBound::apply(TitikLayanan::query(), $user)
            ->search($arguments['q'] ?? null)
            ->orderBy('nama')
            ->orderBy('id');

        if (isset($arguments['jenis'])) {
            $query->where('jenis', $arguments['jenis']);
        }

        if (isset($arguments['aktif'])) {
            $query->where('is_active', (bool) $arguments['aktif']);
        }

        $total = (clone $query)->count();
        $items = $query->limit((int) ($arguments['batas'] ?? 10))->get();

        return [
            'total' => $total,
            'hasil' => $items->map(fn (TitikLayanan $t) => [
                'id' => $t->id,
                'nama' => $t->nama,
                'jenis' => $t->jenis->value,
                'alamat' => $t->alamat,
                'jarak_km' => $t->jarak_km === null ? null : (float) $t->jarak_km,
                'jumlah_sasaran' => $t->jumlah_sasaran,
                'pic_nama' => RecordPresenter::initials($t->pic_nama),
                'pic_terhubung' => $t->picTerhubung(),
                'aktif' => $t->is_active,
            ])->all(),
        ];
    }
}

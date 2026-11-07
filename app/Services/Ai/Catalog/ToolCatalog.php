<?php

namespace App\Services\Ai\Catalog;

use App\Services\Ai\Tools\AiTool;

/**
 * Membangun tool Agen AI generik (cari_/buat_/ubah_/hapus_) dari CatalogDefinitions. Tool tulis hanya
 * dibangun bila Form Request-nya ada (hapus tidak butuh). Dimuat ToolRegistry; nama yang sudah ditulis
 * sebagai kelas sendiri ada di ResourceSpec::$skip, dan config ai.tools.disabled tetap berlaku.
 */
final class ToolCatalog
{
    /** @return list<AiTool> */
    public static function tools(): array
    {
        $tools = [];

        foreach (CatalogDefinitions::all() as $spec) {
            // Subkelas buat_/ubah_ (mis. MenuWriteTool: nama bahan -> ID di server); hapus_ selalu generik.
            $tulis = $spec->kelasTulis !== null && is_subclass_of($spec->kelasTulis, CatalogWriteTool::class) ? $spec->kelasTulis : CatalogWriteTool::class;

            foreach ($spec->actions as $letter) {
                if (in_array($spec->toolName($letter), $spec->skip, true)) {
                    continue;
                }

                $tools[] = match ($letter) {
                    'R' => new CatalogReadTool($spec),
                    'C' => $spec->store === null ? null : new $tulis($spec, 'C'),
                    'U' => $spec->update === null ? null : new $tulis($spec, 'U'),
                    'D' => new CatalogWriteTool($spec, 'D'),
                };
            }
        }

        $tools[] = new CatatMutasiStokTool(CatalogDefinitions::mutasiStok());

        return array_values(array_filter($tools));
    }
}

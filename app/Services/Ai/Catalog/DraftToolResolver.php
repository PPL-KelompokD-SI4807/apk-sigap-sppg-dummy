<?php

namespace App\Services\Ai\Catalog;

use App\Models\AiDraft;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\WriteDraftTool;

/**
 * Menentukan tool tulis pembuat sebuah draf. Slug modul + aksi tidak selalu unik (mis. modul keuangan:
 * pencairan & pengeluaran; jadwal: produksi & distribusi), sehingga tool bertanda sub-modul dipilih
 * lewat penanda `_resource` pada payload draf; tanpa penanda dipakai tool bawaan modul (tanpa penanda).
 */
final class DraftToolResolver
{
    public function __construct(private readonly ToolRegistry $registry) {}

    public function resolve(AiDraft $draft): ?WriteDraftTool
    {
        $candidates = array_values(array_filter(
            $this->registry->all(),
            fn ($tool) => $tool instanceof WriteDraftTool
                && $tool->module() === $draft->module
                && $tool->draftAction()->value === $draft->action->value,
        ));

        if (count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }

        $marker = ((array) $draft->payload)['_resource'] ?? null;

        foreach ($candidates as $tool) {
            $own = $tool instanceof CatalogWriteTool ? $tool->draftResource() : null;

            if ($own === $marker) {
                return $tool;
            }
        }

        return $candidates[0];
    }
}

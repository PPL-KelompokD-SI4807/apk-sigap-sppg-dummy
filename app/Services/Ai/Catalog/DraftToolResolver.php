<?php

namespace App\Services\Ai\Catalog;

use App\Models\AiDraft;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\WriteDraftTool;

/**
 * Menentukan tool tulis pembuat sebuah draf. Slug modul + aksi tidak selalu unik (mis. modul keuangan:
 * pencairan & pengeluaran; jadwal: produksi & distribusi), sehingga tool bertanda sub-modul dipilih
 * lewat penanda `_resource` pada payload draf; tanpa penanda dipakai tool bawaan modul (tanpa penanda).
 * Fail closed: penanda yang tidak cocok dengan tool mana pun -> null (draf tidak divalidasi dengan
 * Form Request yang salah).
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

        $marker = ((array) $draft->payload)['_resource'] ?? null;

        foreach ($candidates as $tool) {
            $own = $tool instanceof CatalogWriteTool ? $tool->draftResource() : null;

            if ($own === $marker) {
                return $tool;
            }
        }

        return null;
    }
}

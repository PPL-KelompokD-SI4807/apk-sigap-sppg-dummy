<?php

namespace App\Services\Ai\Catalog;

use App\Models\AiDraft;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\WriteDraftTool;

/**
 * Menentukan tool tulis pembuat sebuah draf. Slug modul + aksi tidak selalu unik (mis. modul keuangan:
 * pencairan & pengeluaran; jadwal: produksi & distribusi; pengguna: ubah peran / nonaktifkan / aktifkan).
 *
 * - Draf baru menyimpan nama tool pembuat (ai_drafts.tool, diisi server). Tool itu dipakai HANYA bila modul,
 *   aksi, dan penanda sub-modul (`_resource`) draf masih cocok dengannya.
 * - Draf lama (tanpa nama tool): tool bertanda sub-modul dipilih lewat penanda `_resource` pada payload; tanpa
 *   penanda dipakai tool bawaan modul (tanpa penanda).
 * Fail closed: tidak cocok dengan tool mana pun -> null (draf tidak divalidasi/dijalankan dengan tool yang salah).
 */
final class DraftToolResolver
{
    public function __construct(private readonly ToolRegistry $registry) {}

    public function resolve(AiDraft $draft): ?WriteDraftTool
    {
        $marker = ((array) $draft->payload)['_resource'] ?? null;
        $cocok = fn ($tool) => $tool instanceof WriteDraftTool
            && $tool->module() === $draft->module
            && $tool->draftAction()->value === $draft->action->value
            && $tool->draftResource() === $marker;

        $tools = $this->registry->all();

        if (is_string($draft->tool) && $draft->tool !== '') {
            $tool = $tools[$draft->tool] ?? null;

            return $cocok($tool) ? $tool : null;
        }

        foreach ($tools as $tool) {
            if ($cocok($tool)) {
                return $tool;
            }
        }

        return null;
    }
}

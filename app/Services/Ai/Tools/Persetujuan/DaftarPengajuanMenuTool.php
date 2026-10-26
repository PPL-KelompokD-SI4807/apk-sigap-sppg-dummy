<?php

namespace App\Services\Ai\Tools\Persetujuan;

use App\Enums\StatusPengajuanMenu;
use App\Models\PengajuanMenu;
use App\Models\User;
use App\Services\Ai\Tools\ReadTool;

/**
 * Tool BACA pengajuan persetujuan menu (R-06b-1). Mewarisi matriks modul persetujuan-menu
 * (admin, kepala, ahli gizi, investor). Tidak ada tool tulis: keputusan hanya di Mini App.
 * Data minimal (tanpa rincian bahan/nama pengguna) karena hasil dikirim ke LLM.
 */
class DaftarPengajuanMenuTool extends ReadTool
{
    protected string $name = 'daftar_pengajuan_menu';

    protected string $description = 'Daftar pengajuan persetujuan menu ke investor milik SPPG pengguna: nama menu, status (diajukan/disetujui/ditolak/perlu_revisi/dibatalkan), biaya per porsi, jumlah porsi, total biaya, dan catatan keputusan. Hanya baca; keputusan dilakukan investor di Mini App.';

    protected string $module = 'persetujuan-menu';

    protected ?string $model = PengajuanMenu::class;

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => StatusPengajuanMenu::values()],
                'batas' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Jumlah hasil (default 10).'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    protected function handle(User $user, array $arguments): array
    {
        $query = PengajuanMenu::query()->orderByDesc('diajukan_pada')->orderByDesc('id');

        if (isset($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $total = (clone $query)->count();
        $items = $query->limit((int) ($arguments['batas'] ?? 10))->get();

        return [
            'total' => $total,
            'hasil' => $items->map(fn (PengajuanMenu $p) => [
                'id' => $p->id,
                'menu_id' => $p->menu_id,
                'nama_menu' => $p->rincian['menu']['nama_menu'] ?? null,
                'nomor' => $p->nomor,
                'status' => $p->status->value,
                'biaya_per_porsi' => (float) $p->biaya_per_porsi,
                'jumlah_porsi' => $p->jumlah_porsi,
                'total_biaya' => (float) $p->total_biaya,
                'diajukan_pada' => $p->diajukan_pada?->toDateString(),
                'catatan_keputusan' => $p->catatan_keputusan === null ? null : mb_substr($p->catatan_keputusan, 0, 200),
            ])->all(),
        ];
    }
}

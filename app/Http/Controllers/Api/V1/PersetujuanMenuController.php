<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusPengajuanMenu;
use App\Http\Requests\Persetujuan\KeputusanPengajuanRequest;
use App\Http\Resources\Persetujuan\PengajuanMenuResource;
use App\Models\PengajuanMenu;
use App\Services\Persetujuan\PersetujuanMenuService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Persetujuan menu oleh investor (R-06b-1). Hanya baca + keputusan: pengajuan imutabel, tanpa
 * endpoint ubah/hapus. Data dibatasi SPPG aktif (SppgScope + Policy); keputusan hanya investor pemilik.
 */
class PersetujuanMenuController extends ApiController
{
    public function __construct(private readonly PersetujuanMenuService $persetujuan) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PengajuanMenu::class);

        $query = PengajuanMenu::query()->with(['pengaju', 'pemutus'])->orderByDesc('diajukan_pada')->orderByDesc('id');

        $status = $request->query('status');

        if (is_string($status) && in_array($status, StatusPengajuanMenu::values(), true)) {
            $query->where('status', $status);
        }

        if (($menuId = filter_var($request->query('menu_id'), FILTER_VALIDATE_INT)) !== false) {
            $query->where('menu_id', $menuId);
        }

        if ($request->user()->isAdmin() && ($sppgId = filter_var($request->query('sppg_id'), FILTER_VALIDATE_INT)) !== false) {
            $query->where('sppg_id', $sppgId);
        }

        return PengajuanMenuResource::collection($this->paginate($query, $request));
    }

    public function show(PengajuanMenu $pengajuan): PengajuanMenuResource
    {
        $this->authorize('view', $pengajuan);

        return new PengajuanMenuResource($pengajuan->load(['menu', 'pengaju', 'pemutus']));
    }

    public function keputusan(KeputusanPengajuanRequest $request, PengajuanMenu $pengajuan): PengajuanMenuResource
    {
        $data = $request->validated();

        $hasil = $this->persetujuan->putuskan(
            $request->user(),
            $pengajuan,
            $data['keputusan'],
            $data['catatan'] ?? null,
            (int) $data['versi_menu'],
        );

        return new PengajuanMenuResource($hasil->load(['menu', 'pengaju', 'pemutus']));
    }
}

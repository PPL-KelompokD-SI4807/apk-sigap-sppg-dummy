<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusPermintaanReset;
use App\Http\Resources\PermintaanResetResource;
use App\Models\PermintaanResetPassword;
use App\Models\Scopes\SppgScope;
use App\Services\Auth\PermintaanResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Permintaan reset password untuk Admin (R-03). Policy PermintaanResetPasswordPolicy: hanya Admin
 * (hak ubah Data Pengguna) untuk pengguna dalam scope-nya; tanpa hak / di luar scope -> 403.
 * Admin tidak pernah melihat atau menetapkan password.
 */
class PermintaanResetController extends ApiController
{
    public function __construct(private readonly PermintaanResetService $permintaan) {}

    /** GET /api/v1/pengguna/permintaan-reset?status=menunggu|disetujui|ditolak|selesai|kedaluwarsa|semua */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PermintaanResetPassword::class);
        $actor = $request->user();

        $query = PermintaanResetPassword::query()->with('user')->latest();
        $menungguAtauDisetujui = [StatusPermintaanReset::Menunggu->value, StatusPermintaanReset::Disetujui->value];

        match ($request->query('status', StatusPermintaanReset::Menunggu->value)) {
            'semua' => null,
            'kedaluwarsa' => $query->whereIn('status', $menungguAtauDisetujui)->where('kedaluwarsa_pada', '<=', now()),
            'menunggu', 'disetujui' => $query->where('status', $request->query('status', 'menunggu'))->where('kedaluwarsa_pada', '>', now()),
            'ditolak', 'selesai' => $query->where('status', $request->query('status')),
            default => $query->whereRaw('1 = 0'),
        };

        // Hanya permintaan yang boleh diputus aktor (scope Policy, bukan milik sendiri).
        $query->where('user_id', '!=', $actor->getKey());
        $paginator = $this->paginate($query, $request);
        $paginator->setCollection($paginator->getCollection()->filter(fn ($p) => $actor->can('putuskan', $p))->values());

        return PermintaanResetResource::collection($paginator);
    }

    /** POST /api/v1/pengguna/permintaan-reset/{permintaan}/setujui */
    public function setujui(Request $request, string $permintaan): JsonResponse
    {
        $model = $this->temukan($permintaan);
        $this->authorize('putuskan', $model);

        $hasil = $this->permintaan->setujui($request->user(), $model);

        return response()->json([
            'message' => 'Permintaan disetujui. Pengguna diberi tahu lewat bot untuk menetapkan password baru dengan token.',
            'data' => (new PermintaanResetResource($hasil))->resolve($request),
        ]);
    }

    /** POST /api/v1/pengguna/permintaan-reset/{permintaan}/tolak */
    public function tolak(Request $request, string $permintaan): JsonResponse
    {
        $model = $this->temukan($permintaan);
        $this->authorize('putuskan', $model);

        $hasil = $this->permintaan->tolak($request->user(), $model);

        return response()->json([
            'message' => 'Permintaan ditolak. Pengguna diberi tahu lewat bot.',
            'data' => (new PermintaanResetResource($hasil))->resolve($request),
        ]);
    }

    /**
     * Dicari tanpa scope SPPG lalu diputus Policy: Admin di luar scope mendapat 403 (bukan 404);
     * id ULID tidak berurutan sehingga tidak membuka enumerasi.
     */
    private function temukan(string $id): PermintaanResetPassword
    {
        return PermintaanResetPassword::query()->withoutGlobalScope(SppgScope::class)->findOrFail($id);
    }
}

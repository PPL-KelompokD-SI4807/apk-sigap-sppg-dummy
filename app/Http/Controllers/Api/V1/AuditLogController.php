<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AuditLog\DaftarAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ModulAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Log audit khusus Admin (AuditLogPolicy). Hanya baca; cara audit dicatat tidak diubah.
 */
class AuditLogController extends ApiController
{
    /** GET /api/v1/audit-log?modul=&user_id=&aksi=&from=&to=&page=&per_page= */
    public function index(DaftarAuditLogRequest $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()->with('user')->orderByDesc('id');

        if ($modul = $request->validated('modul')) {
            $query->where('auditable_type', ModulAudit::kelas($modul));
        }

        if ($userId = $request->validated('user_id')) {
            $query->where('user_id', (int) $userId);
        }

        if ($aksi = $request->validated('aksi')) {
            $query->where('event', $aksi);
        }

        $zona = config('app.timezone');

        if ($from = $request->validated('from')) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $from, $zona)->startOfDay());
        }

        if ($to = $request->validated('to')) {
            $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $to, $zona)->endOfDay());
        }

        return AuditLogResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** GET /api/v1/audit-log/{id} */
    public function show(int $id): AuditLogResource
    {
        $this->authorize('viewAny', AuditLog::class);

        $log = AuditLog::query()->with('user')->findOrFail($id);
        $this->authorize('view', $log);

        return new AuditLogResource($log);
    }

    /** GET /api/v1/audit-log/opsi: daftar modul dan aksi untuk dropdown filter. */
    public function opsi(): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $modul = [];
        foreach (ModulAudit::MODUL as $slug => [, $label]) {
            $modul[] = ['kode' => $slug, 'label' => $label];
        }

        $dariData = AuditLog::query()->select('event')->distinct()->orderBy('event')->limit(200)->pluck('event')->all();
        $aksi = array_values(array_unique([...ModulAudit::AKSI_BAWAAN, ...$dariData]));

        return response()->json(['data' => ['modul' => $modul, 'aksi' => $aksi]]);
    }
}

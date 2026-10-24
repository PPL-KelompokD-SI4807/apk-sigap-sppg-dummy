<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Lampiran\StoreLampiranRequest;
use App\Http\Resources\LampiranResource;
use App\Models\Lampiran;
use App\Services\Storage\SecureUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Unggah lampiran tambahan ke record modul dan sajikan berkas secara privat.
 *
 * - GET /lampiran/{id}: Lampiran ber-SppgScope (SPPG lain -> 404), induk harus ada di allowlist
 *   dan belum dihapus (-> 404), lalu Policy view induk (-> 403). Header tetap dan generik.
 * - POST /lampiran: induk dicari ber-scope (-> 404), hak config('unggahan.modul.*.ability')
 *   pada induk (-> 403), berkas diverifikasi SecureUploadService (-> 422).
 */
class LampiranController extends ApiController
{
    public function __construct(private readonly SecureUploadService $unggahan) {}

    public function show(Lampiran $lampiran): Response
    {
        $induk = $this->unggahan->induk($lampiran);
        abort_if($induk === null, 404);
        $this->authorize('view', $induk);

        $isi = $this->unggahan->baca($lampiran);
        abort_if($isi === null, 404);

        $ext = (string) config('unggahan.mime.'.$lampiran->mime);

        return response($isi, 200, [
            'Content-Type' => $lampiran->mime,
            'Content-Length' => (string) strlen($isi),
            'Content-Disposition' => 'inline; filename="lampiran.'.$ext.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public function store(StoreLampiranRequest $request): JsonResponse
    {
        $data = $request->validated();
        $def = config('unggahan.modul.'.$data['modul']);
        $induk = $def['model']::query()->find((int) $data['lampiranable_id']);
        abort_if($induk === null, 404);
        $this->authorize($def['ability'] ?? 'update', $induk);

        $gambar = $this->unggahan->siapkan($request->file('berkas'), 'berkas');

        $lampiran = DB::transaction(fn () => $this->unggahan->simpan(
            $gambar, $induk, $data['modul'], $request->user(), $data['kategori'] ?? null,
        ));

        return (new LampiranResource($lampiran))->response()->setStatusCode(201);
    }
}

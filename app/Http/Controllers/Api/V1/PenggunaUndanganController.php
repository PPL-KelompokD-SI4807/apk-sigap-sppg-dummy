<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipeUndangan;
use App\Http\Requests\Pengguna\StoreUndanganRequest;
use App\Http\Resources\Pengguna\UndanganDibuatResource;
use App\Http\Resources\Pengguna\UndanganResource;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Users\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Undangan Telegram pengguna. Kebijakan: hanya Admin (hak C modul pengguna); token mentah hanya
 * tampil pada respons penerbitan.
 */
class PenggunaUndanganController extends ApiController
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('create', User::class);

        $query = Invitation::query()->with(['user', 'titikLayanan'])->orderByDesc('id');

        if (in_array($tipe = $request->query('tipe'), TipeUndangan::values(), true)) {
            $query->where('tipe', $tipe);
        }

        match ($request->query('status')) {
            'aktif' => $query->aktif(),
            'terpakai' => $query->whereNotNull('used_at'),
            'dicabut' => $query->whereNotNull('revoked_at'),
            'kedaluwarsa' => $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
            default => null,
        };

        return UndanganResource::collection($this->paginate($query, $request));
    }

    public function store(StoreUndanganRequest $request): JsonResponse
    {
        $issued = $this->invitations->issue($request->user(), TipeUndangan::Pengguna, $request->validated());

        return UndanganDibuatResource::created($issued, $request);
    }

    public function destroy(Invitation $invitation): Response
    {
        $this->authorize('create', User::class);

        $this->invitations->revoke($invitation);

        return response()->noContent();
    }
}

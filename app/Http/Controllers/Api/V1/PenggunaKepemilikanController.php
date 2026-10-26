<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pengguna\TautkanKepemilikanSppgRequest;
use App\Http\Requests\Pengguna\UpdateKepemilikanSppgRequest;
use App\Models\User;
use App\Services\Users\KepemilikanSppgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kepemilikan SPPG investor (R-06a). Hanya Admin (UserPolicy::kelolaKepemilikan); peran lain 403,
 * pengguna di luar scope 404. Target wajib berperan investor (422).
 */
class PenggunaKepemilikanController extends ApiController
{
    public function __construct(private readonly KepemilikanSppgService $kepemilikan) {}

    /** GET /api/v1/pengguna/{user}/kepemilikan-sppg */
    public function show(User $user): JsonResponse
    {
        $this->authorize('kelolaKepemilikan', $user);

        return $this->respons($user, $user->isInvestor() ? $this->kepemilikan->daftar($user) : []);
    }

    /** PUT /api/v1/pengguna/{user}/kepemilikan-sppg {"sppg_ids": [1, 2]} */
    public function update(UpdateKepemilikanSppgRequest $request, User $user): JsonResponse
    {
        $daftar = $this->kepemilikan->atur($request->user(), $user, $request->validated('sppg_ids'));

        return $this->respons($user, $daftar);
    }

    /** POST /api/v1/pengguna/{user}/kepemilikan-sppg {"sppg_id": 2} — tautkan (idempoten). */
    public function store(TautkanKepemilikanSppgRequest $request, User $user): JsonResponse
    {
        $daftar = $this->kepemilikan->tautkan($request->user(), $user, (int) $request->validated('sppg_id'));

        return $this->respons($user, $daftar);
    }

    /** DELETE /api/v1/pengguna/{user}/kepemilikan-sppg/{sppg} — lepas (idempoten). */
    public function destroy(Request $request, User $user, int $sppg): JsonResponse
    {
        $this->authorize('kelolaKepemilikan', $user);

        if (! $user->isInvestor()) {
            throw ValidationException::withMessages(['user' => ['Kepemilikan SPPG hanya untuk pengguna berperan investor.']]);
        }

        return $this->respons($user, $this->kepemilikan->lepas($request->user(), $user, $sppg));
    }

    /** @param list<array{id: int, kode: string, nama: string}> $daftar */
    private function respons(User $user, array $daftar): JsonResponse
    {
        return response()->json(['data' => [
            'user_id' => $user->id,
            'role' => $user->role->value,
            'sppg' => $daftar,
        ]]);
    }
}

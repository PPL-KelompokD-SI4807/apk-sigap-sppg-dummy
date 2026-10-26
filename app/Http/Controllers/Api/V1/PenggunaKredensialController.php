<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Auth\KredensialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tindakan Admin atas kredensial pengguna (R-03), Policy UserPolicy::update (hanya Admin: CRUD
 * pengguna). Admin tidak pernah melihat atau menentukan password: reset = password dikosongkan
 * dan pengguna mengatur sendiri lewat aktivasi (+ token bot).
 */
class PenggunaKredensialController extends ApiController
{
    public function __construct(private readonly KredensialService $kredensial) {}

    /** POST /api/v1/pengguna/{user}/reset-password */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $this->kredensial->resetPassword($user, $request->user());

        return response()->json([
            'message' => 'Password direset dan semua sesi pengguna dicabut. Pengguna wajib mengatur password baru saat membuka Mini App.',
        ]);
    }

    /** DELETE /api/v1/pengguna/{user}/sesi */
    public function cabutSesi(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $jumlah = $this->kredensial->cabutSesiOlehAdmin($request->user(), $user);

        return response()->json([
            'message' => 'Semua sesi pengguna dicabut.',
            'jumlah_sesi' => $jumlah,
        ]);
    }
}

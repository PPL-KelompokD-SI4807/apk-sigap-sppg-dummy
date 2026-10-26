<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\KredensialService;
use App\Services\Auth\SesiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tindakan Admin atas kredensial & sesi pengguna (R-03). Policy UserPolicy::update / kelolaSesi
 * (hanya Admin: CRUD pengguna; pengguna di luar scope tidak terlihat -> 404). Admin tidak pernah
 * melihat atau menentukan password: reset = password dikosongkan dan pengguna mengatur sendiri
 * lewat aktivasi (+ token bot).
 */
class PenggunaKredensialController extends ApiController
{
    public function __construct(
        private readonly KredensialService $kredensial,
        private readonly SesiService $sesi,
    ) {}

    /** POST /api/v1/pengguna/{user}/reset-password */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $this->kredensial->resetPassword($user, $request->user());

        return response()->json([
            'message' => 'Password direset dan semua sesi pengguna dicabut. Pengguna wajib mengatur password baru saat membuka Mini App.',
        ]);
    }

    /** GET /api/v1/pengguna/{user}/sesi */
    public function daftarSesi(User $user): JsonResponse
    {
        $this->authorize('kelolaSesi', $user);

        return response()->json(['data' => $this->sesi->daftarSesi($user, null)]);
    }

    /** DELETE /api/v1/pengguna/{user}/sesi */
    public function cabutSesi(Request $request, User $user): JsonResponse
    {
        $this->authorize('kelolaSesi', $user);

        $jumlah = $this->kredensial->cabutSesiOlehAdmin($request->user(), $user);

        return response()->json([
            'message' => 'Semua sesi pengguna dicabut.',
            'jumlah_sesi' => $jumlah,
        ]);
    }

    /** DELETE /api/v1/pengguna/{user}/sesi/{sesi} — sesi harus milik {user} (selain itu 404). */
    public function cabutSatuSesi(Request $request, User $user, int $sesi): JsonResponse
    {
        $this->authorize('kelolaSesi', $user);

        $this->sesi->cabutSatu($user, $sesi) ?? throw new NotFoundHttpException;
        AuditAuth::catat($user, AuditAuth::SESI_DICABUT_ADMIN, ['jumlah_sesi' => 1], $request->user());

        return response()->json(['message' => 'Sesi pengguna dicabut.', 'jumlah_sesi' => 1]);
    }
}

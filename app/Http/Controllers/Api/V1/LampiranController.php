<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Lampiran\StoreLampiranRequest;
use App\Http\Resources\LampiranResource;
use App\Models\Lampiran;
use App\Services\Storage\PengikatLampiran;
use App\Services\Storage\SecureUploadService;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unggah lampiran tambahan ke record modul dan sajikan berkas secara privat.
 *
 * - GET /lampiran/{id}: Lampiran ber-SppgScope (SPPG lain -> 404), induk harus ada di allowlist
 *   dan belum dihapus (-> 404), lalu Policy view induk (-> 403). Header tetap dan generik.
 * - POST /lampiran: induk dicari ber-scope (-> 404), hak config('unggahan.modul.*.ability')
 *   pada induk (-> 403), berkas diverifikasi SecureUploadService (-> 422).
 *   Tanpa lampiranable_id (modul 'tertunda'): berkas disimpan belum terikat (hak C/U modul, -> 403),
 *   lalu diikat lewat lampiran_ids pada store/update modul. Lampiran belum terikat hanya dapat
 *   dibaca pengunggahnya. Unggahan tertunda aktif per pengguna dibatasi (maks_tertunda_per_pengguna).
 * - Jumlah lampiran per record dibatasi 'maks' (default PengikatLampiran::MAKS_DEFAULT), dihitung
 *   di dalam transaksi dengan baris induk terkunci.
 */
class LampiranController extends ApiController
{
    /** Batas bawaan unggahan tertunda (belum diikat) per pengguna. */
    public const MAKS_TERTUNDA = 10;

    public function __construct(private readonly SecureUploadService $unggahan) {}

    public function show(Request $request, Lampiran $lampiran): Response
    {
        if ($lampiran->lampiranable_type === null) {
            // Belum terikat: hanya pengunggah (LampiranPolicy); selain itu 404 agar keberadaannya tidak bocor.
            abort_unless($request->user()->can('view', $lampiran), 404);
        } else {
            $induk = $this->unggahan->induk($lampiran);
            abort_if($induk === null, 404);
            $this->authorize('view', $induk);
        }

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
        $def = (array) config('unggahan.modul.'.$data['modul']);
        $user = $request->user();
        $kategori = $data['kategori'] ?? (is_array($def['kategori'] ?? null) ? $def['kategori'][0] : null);

        if (! isset($data['lampiranable_id'])) {
            return $this->simpanTertunda($request, $def, $data['modul'], $kategori);
        }

        $induk = $def['model']::query()->find((int) $data['lampiranable_id']);
        abort_if($induk === null, 404);
        $this->authorize($def['ability'] ?? 'update', $induk);

        $maks = (int) ($def['maks'] ?? PengikatLampiran::MAKS_DEFAULT);
        $gambar = $this->unggahan->siapkan($request->file('berkas'), 'berkas');

        $lampiran = DB::transaction(function () use ($gambar, $induk, $data, $user, $kategori, $maks) {
            // Kunci baris induk agar dua unggahan bersamaan tidak sama-sama lolos batas jumlah.
            $induk->newQueryWithoutScopes()->whereKey($induk->getKey())->lockForUpdate()->first();

            if ($induk->lampirans()->count() >= $maks) {
                throw ValidationException::withMessages(['lampiranable_id' => ["Jumlah lampiran maksimal {$maks} per data."]]);
            }

            return $this->unggahan->simpan($gambar, $induk, $data['modul'], $user, $kategori);
        });

        return (new LampiranResource($lampiran))->response()->setStatusCode(201);
    }

    /** @param array<string, mixed> $def */
    private function simpanTertunda(StoreLampiranRequest $request, array $def, string $modul, ?string $kategori): JsonResponse
    {
        $user = $request->user();

        if (! ($def['tertunda'] ?? false)) {
            throw ValidationException::withMessages(['lampiranable_id' => ['ID record induk wajib diisi untuk modul ini.']]);
        }

        $rbac = (string) $def['rbac'];
        abort_unless(Rbac::can($user, $rbac, 'C') || Rbac::can($user, $rbac, 'U'), 403);

        $sppgId = $user->isAdmin() ? (int) $request->validated('sppg_id') : (int) $user->sppg_id;
        abort_if($sppgId < 1, 403);

        $gambar = $this->unggahan->siapkan($request->file('berkas'), 'berkas');

        $lampiran = DB::transaction(function () use ($gambar, $sppgId, $modul, $user, $kategori) {
            // Kunci baris pengguna: kuota unggahan tertunda aktif dihitung tanpa race.
            $user->newQueryWithoutScopes()->whereKey($user->getKey())->lockForUpdate()->first();
            $maks = (int) config('unggahan.maks_tertunda_per_pengguna', self::MAKS_TERTUNDA);

            $aktif = Lampiran::query()->withoutGlobalScopes()
                ->whereNull('lampiranable_type')
                ->where('uploaded_by', $user->getKey())
                ->count();

            if ($aktif >= $maks) {
                throw ValidationException::withMessages(['berkas' => ["Maksimal {$maks} foto belum tersimpan. Simpan atau tunggu foto sebelumnya kedaluwarsa."]]);
            }

            return $this->unggahan->simpanTertunda($gambar, $sppgId, $modul, $user, $kategori);
        });

        return (new LampiranResource($lampiran))->response()->setStatusCode(201);
    }
}

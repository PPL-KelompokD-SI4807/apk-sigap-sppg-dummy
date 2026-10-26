<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusKirim;
use App\Events\RekapHarianDibuat;
use App\Http\Requests\Rekap\StoreRekapHarianRequest;
use App\Http\Requests\Rekap\UpdateRekapHarianRequest;
use App\Http\Resources\RekapHarianResource;
use App\Models\RekapHarian;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Rekap\DailySummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RekapHarianController extends ApiController
{
    public function __construct(private readonly DailySummaryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RekapHarian::class);

        $query = RekapHarian::query()->with('penyusun');

        if ($request->user()->isAdmin() && ctype_digit((string) $request->query('sppg_id', ''))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        $this->applyDateRange($query, $request, 'tanggal');
        $query->orderByDesc('tanggal')->orderByDesc('id');

        return RekapHarianResource::collection($this->paginate($query, $request));
    }

    /** Ringkasan langsung untuk dasbor (dihitung saat itu, tidak disimpan). */
    public function ringkasan(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RekapHarian::class);

        $user = $request->user();
        $data = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->service->hariIni()->toDateString()],
            'sppg_id' => $user->isAdmin()
                ? ['required', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')]
                : ['exclude'],
        ]);

        $sppgId = $user->isAdmin() ? (int) $data['sppg_id'] : (int) $user->sppg_id;
        abort_if($sppgId === 0, 403);

        return response()->json(['data' => $this->service->hitung($sppgId, $data['tanggal'] ?? null)]);
    }

    public function show(RekapHarian $rekapHarian): RekapHarianResource
    {
        $this->authorize('view', $rekapHarian);

        return new RekapHarianResource($rekapHarian->load('penyusun'));
    }

    /** Susun atau susun ulang rekap satu hari (idempoten per SPPG + tanggal). */
    public function store(StoreRekapHarianRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        abort_if($user->sppg_id === null, 403);

        $tanggal = $this->service->parseTanggal($data['tanggal'] ?? null);
        $sudahAda = RekapHarian::query()->whereDate('tanggal', $tanggal->toDateString())->first();

        if ($sudahAda !== null) {
            $this->authorize('update', $sudahAda);
        }

        $kirim = $request->boolean('kirim');

        $rekap = DB::transaction(function () use ($user, $data, $tanggal, $confirmer, $kirim) {
            $hasil = $this->service->susun(
                (int) $user->sppg_id,
                $tanggal,
                $user,
                $data['catatan'] ?? null,
                array_key_exists('catatan', $data),
            );
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'rekap-harian');

            if ($kirim) {
                event(RekapHarianDibuat::dari($hasil));
            }

            return $hasil;
        });

        return (new RekapHarianResource($rekap->load('penyusun')))->response()->setStatusCode($sudahAda === null ? 201 : 200);
    }

    public function update(UpdateRekapHarianRequest $request, RekapHarian $rekapHarian, AiDraftConfirmer $confirmer): RekapHarianResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $rekapHarian, $data, $confirmer) {
            $rekapHarian->catatan = $data['catatan'];
            $rekapHarian->save();
            $confirmer->confirm($data['ai_draft_id'] ?? null, $request->user(), 'rekap-harian', (int) $rekapHarian->id);
        });

        return new RekapHarianResource($rekapHarian->load('penyusun'));
    }

    public function kirimUlang(RekapHarian $rekapHarian): RekapHarianResource
    {
        $this->authorize('update', $rekapHarian);

        DB::transaction(function () use ($rekapHarian) {
            $rekapHarian->forceFill(['status_kirim' => StatusKirim::Menunggu, 'dikirim_pada' => null])->save();
            event(RekapHarianDibuat::dari($rekapHarian));
        });

        return new RekapHarianResource($rekapHarian->load('penyusun'));
    }
}

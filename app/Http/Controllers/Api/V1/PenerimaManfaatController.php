<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KelompokSasaran;
use App\Http\Controllers\Api\V1\Concerns\FiltersMasterData;
use App\Http\Requests\PenerimaManfaat\StorePenerimaManfaatRequest;
use App\Http\Requests\PenerimaManfaat\UpdatePenerimaManfaatRequest;
use App\Http\Resources\PenerimaManfaat\PenerimaManfaatResource;
use App\Models\PenerimaManfaat;
use App\Models\TitikLayanan;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\PenerimaManfaat\AgregatPenerimaService;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Modul 5 Penerima Manfaat (Admin CRUD, Kepala SPPG CRU, Ahli Gizi R). Tanpa NIK.
 */
class PenerimaManfaatController extends ApiController
{
    use FiltersMasterData;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PenerimaManfaat::class);

        $query = PenerimaManfaat::query()->with('titikLayanan')
            ->search($request->query('q'))->orderBy('nama')->orderBy('id');
        $this->filterSppg($query, $request);

        if (($titikId = filter_var($request->query('titik_layanan_id'), FILTER_VALIDATE_INT)) !== false) {
            $query->where('titik_layanan_id', $titikId);
        }

        if (($kelompok = $this->enumQuery($request, 'kelompok_sasaran', KelompokSasaran::class)) !== null) {
            $query->where('kelompok_sasaran', $kelompok);
        }

        if (($active = $this->boolQuery($request, 'is_active')) !== null) {
            $query->where('is_active', $active);
        }

        if ($this->boolQuery($request, 'ada_alergi') === true) {
            $query->whereNotNull('catatan_alergi')->where('catatan_alergi', '!=', '');
        }

        return PenerimaManfaatResource::collection($this->paginate($query, $request));
    }

    /**
     * GET /penerima-manfaat/agregat (R-06a): jumlah penerima AKTIF per kelompok sasaran dan per
     * titik layanan untuk SPPG aktif. HANYA hitungan: tanpa nama, kelas, catatan alergi, atau ID
     * penerima. Admin wajib ?sppg_id=; peran lain memakai SPPG aktif (investor: header X-SPPG-Id).
     * Peran yang hanya punya hak agregat (investor) menerima sel kecil tersamar (R06a-S-02).
     */
    public function agregat(Request $request, AgregatPenerimaService $agregat): JsonResponse
    {
        $this->authorize('viewAgregat', PenerimaManfaat::class);

        $user = $request->user();
        $data = $request->validate([
            'sppg_id' => $user->isAdmin()
                ? ['required', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')]
                : ['exclude'],
        ]);

        $sppgId = $user->isAdmin() ? (int) $data['sppg_id'] : (int) $user->sppgAktifId();
        abort_if($sppgId === 0, 403);

        $samarkan = in_array('penerima-manfaat', Rbac::aggregateOnlyModules($user), true);

        return response()->json(['data' => $agregat->hitung($sppgId, $samarkan)]);
    }

    public function show(PenerimaManfaat $penerimaManfaat): PenerimaManfaatResource
    {
        $this->authorize('view', $penerimaManfaat);

        return new PenerimaManfaatResource($penerimaManfaat->load('titikLayanan'));
    }

    public function store(StorePenerimaManfaatRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $confirmer): PenerimaManfaat {
            $data = $request->safe()->except('ai_draft_id');
            $titik = TitikLayanan::query()->findOrFail($data['titik_layanan_id']);

            $record = new PenerimaManfaat($data);
            // sppg_id selalu mengikuti titik layanan (bukan body); BelongsToSppg tetap memaksa untuk non-admin.
            $record->sppg_id = $titik->sppg_id;
            $record->save();
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'penerima-manfaat');

            return $record;
        });

        return (new PenerimaManfaatResource($record->refresh()->load('titikLayanan')))->response()->setStatusCode(201);
    }

    public function update(UpdatePenerimaManfaatRequest $request, PenerimaManfaat $penerimaManfaat, AiDraftConfirmer $confirmer): PenerimaManfaatResource
    {
        DB::transaction(function () use ($request, $penerimaManfaat, $confirmer): void {
            $data = $request->safe()->except('ai_draft_id');

            $penerimaManfaat->fill($data);

            if (isset($data['titik_layanan_id'])) {
                $penerimaManfaat->sppg_id = TitikLayanan::query()->findOrFail($data['titik_layanan_id'])->sppg_id;
            }

            $penerimaManfaat->save();
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'penerima-manfaat', $penerimaManfaat->getKey());
        });

        return new PenerimaManfaatResource($penerimaManfaat->refresh()->load('titikLayanan'));
    }

    public function destroy(PenerimaManfaat $penerimaManfaat): Response
    {
        $this->authorize('delete', $penerimaManfaat);

        $penerimaManfaat->delete();

        return response()->noContent();
    }
}

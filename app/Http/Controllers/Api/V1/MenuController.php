<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KelompokSasaran;
use App\Enums\StatusJadwalProduksi;
use App\Http\Requests\MenuGizi\StoreMenuRequest;
use App\Http\Requests\MenuGizi\UpdateMenuRequest;
use App\Http\Resources\MenuGizi\MenuResource;
use App\Http\Resources\MenuGizi\StandarGiziResource;
use App\Models\Menu;
use App\Models\StandarGizi;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Gizi\GiziService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Modul 8 Data Menu dan Gizi (FR-05). */
class MenuController extends ApiController
{
    private const MODULE = 'menu-gizi';

    public function __construct(
        private readonly GiziService $gizi,
        private readonly AiDraftConfirmer $draf,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Menu::class);

        $query = Menu::query()->withCount('bahanBakus');
        $this->applySearch($query, $request, ['nama_menu', 'siklus']);
        $this->applyDateRange($query, $request, 'tanggal_sajian');

        if (in_array($request->query('kelompok_sasaran'), KelompokSasaran::values(), true)) {
            $query->where('kelompok_sasaran', $request->query('kelompok_sasaran'));
        }

        if ($request->user()->isAdmin() && is_numeric($request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return MenuResource::collection($this->paginate($query->orderByDesc('tanggal_sajian')->orderBy('nama_menu')->orderBy('id'), $request));
    }

    public function store(StoreMenuRequest $request): JsonResponse
    {
        $this->authorize('create', Menu::class);
        $data = $request->validated();

        $menu = DB::transaction(function () use ($request, $data): Menu {
            $menu = new Menu;
            $menu->fill($data)->save();

            if (array_key_exists('bahan', $data)) {
                $this->gizi->sinkronKomposisi($menu, $data['bahan']);
            }

            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE);

            return $menu;
        });

        return (new MenuResource($menu->load('bahanBakus')))->response()->setStatusCode(201);
    }

    public function show(Menu $menu): MenuResource
    {
        $this->authorize('view', $menu);

        return new MenuResource($menu->load('bahanBakus'));
    }

    public function update(UpdateMenuRequest $request, Menu $menu): MenuResource
    {
        $this->authorize('update', $menu);
        $data = $request->validated();

        DB::transaction(function () use ($request, $menu, $data): void {
            $menu->fill($data)->save();

            if (array_key_exists('bahan', $data)) {
                $this->gizi->sinkronKomposisi($menu, $data['bahan']);
            }

            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $menu->id);
        });

        return new MenuResource($menu->load('bahanBakus'));
    }

    public function destroy(Menu $menu): Response
    {
        $this->authorize('delete', $menu);

        $dipakai = $menu->jadwalProduksis()
            ->whereIn('status', [StatusJadwalProduksi::Direncanakan->value, StatusJadwalProduksi::Dimasak->value])
            ->exists();

        if ($dipakai) {
            throw ValidationException::withMessages([
                'menu' => ['Menu masih dipakai jadwal produksi yang belum selesai. Batalkan atau selesaikan jadwal terlebih dahulu.'],
            ]);
        }

        $menu->delete();

        return response()->noContent();
    }

    public function ringkasanGizi(Menu $menu): JsonResponse
    {
        $this->authorize('view', $menu);
        $ringkasan = $this->gizi->ringkasan($menu);

        return response()->json(['data' => [
            ...$ringkasan,
            'standar' => $ringkasan['standar'] instanceof StandarGizi
                ? (new StandarGiziResource($ringkasan['standar']))->resolve()
                : null,
        ]]);
    }
}

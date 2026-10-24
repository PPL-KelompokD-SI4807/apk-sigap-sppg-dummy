<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Ai\AiDraftResource;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Drafts\DraftService;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Draf Agen AI untuk Mini App (FR-11, Gambar 12). Hanya PEMILIK draf (SPPG sama) yang dapat
 * membaca/membuang; selain itu 404 (tidak membocorkan keberadaan draf). Penyimpanan data tetap
 * lewat endpoint modul dengan `ai_draft_id` (AiDraftConfirmer).
 */
class AiDraftController extends ApiController
{
    public function __construct(
        private readonly DraftService $drafts,
        private readonly ToolRegistry $registry,
    ) {}

    /** GET /ai-drafts : draf pending milik sendiri (maks config ai.drafts.max_pending_per_user). */
    public function index(Request $request): JsonResponse
    {
        return AiDraftResource::collection($this->drafts->pendingFor($request->user()))->response();
    }

    /** GET /ai-drafts/{uuid} */
    public function show(Request $request, string $uuid): AiDraftResource
    {
        $user = $request->user();
        $draft = $this->drafts->findOwned($user, $uuid) ?? throw new NotFoundHttpException;
        $this->drafts->assertUsable($draft);

        $resource = new AiDraftResource($draft);
        $tool = $this->registry->writeToolFor($draft->module, $draft->action->value);

        if ($tool !== null) {
            if (! $tool->isAvailableFor($user)) {
                throw new AccessDeniedHttpException('Anda tidak lagi berhak menyimpan draf ini.');
            }

            $result = ActingUser::run($user, fn () => $tool->revalidate($user, $draft));

            if ($result !== null && ! $result->authorized) {
                throw new AccessDeniedHttpException('Anda tidak lagi berhak menyimpan draf ini.');
            }

            $resource->errors = $result?->errors ?? [];
        }

        return $resource;
    }

    /** DELETE /ai-drafts/{uuid} : buang draf (idempoten). */
    public function destroy(Request $request, string $uuid): Response
    {
        $draft = $this->drafts->findOwned($request->user(), $uuid) ?? throw new NotFoundHttpException;
        $this->drafts->discard($draft);

        return response()->noContent();
    }
}

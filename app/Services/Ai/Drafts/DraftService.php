<?php

namespace App\Services\Ai\Drafts;

use App\Enums\AksiAiDraft;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

/**
 * Siklus hidup AiDraft (FR-11): dibuat oleh tool tulis, dibaca/dibuang HANYA oleh pemiliknya
 * (SPPG sama), kedaluwarsa setelah config ai.drafts.ttl_minutes, dikonfirmasi oleh
 * AiDraftConfirmer saat endpoint modul menyimpan data dengan ai_draft_id.
 */
class DraftService
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, float>  $confidence
     *
     * @throws DraftLimitException
     */
    public function create(User $user, string $module, AksiAiDraft $action, array $payload, array $confidence, ?int $interactionId = null, ?int $targetId = null): AiDraft
    {
        if ($user->sppg_id === null) {
            throw new DraftLimitException('Draf AI membutuhkan SPPG pengguna.');
        }

        return DB::transaction(function () use ($user, $module, $action, $payload, $confidence, $interactionId, $targetId): AiDraft {
            $this->expireFor($user);

            $pending = $this->ownQuery($user)->pending()->where('expires_at', '>', now())->lockForUpdate()->count();
            $max = (int) config('ai.drafts.max_pending_per_user', 10);

            if ($pending >= $max) {
                throw new DraftLimitException("Batas {$max} draf menunggu konfirmasi tercapai. Simpan atau buang draf lama di Mini App.");
            }

            $draft = new AiDraft([
                'ai_interaction_id' => $interactionId,
                'module' => $module,
                'action' => $action,
                'target_id' => $targetId,
                'payload' => $payload,
                'confidence' => $confidence,
                'status' => StatusAiDraft::Pending,
                'expires_at' => now()->addMinutes(max(1, (int) config('ai.drafts.ttl_minutes', 30))),
            ]);
            // Pemilik & SPPG selalu dari pengguna login, tidak pernah dari argumen model.
            $draft->forceFill(['sppg_id' => $user->sppg_id, 'user_id' => $user->getKey()]);
            $draft->save();

            return $draft;
        });
    }

    /** Draf milik $user (SPPG sama). Null bila tidak ada / milik orang lain / SPPG lain. */
    public function findOwned(User $user, string $uuid): ?AiDraft
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) !== 1) {
            return null;
        }

        return $this->ownQuery($user)->where('uuid', strtolower($uuid))->first();
    }

    /**
     * Draf yang masih dapat dipakai; 410 bila kedaluwarsa/dipakai/dibuang.
     */
    public function assertUsable(AiDraft $draft): AiDraft
    {
        if ($draft->status === StatusAiDraft::Pending && $draft->isKedaluwarsa()) {
            $draft->forceFill(['status' => StatusAiDraft::Expired])->save();
        }

        return match ($draft->status) {
            StatusAiDraft::Pending => $draft,
            StatusAiDraft::Expired => throw new GoneHttpException('Draf sudah kedaluwarsa. Kirim ulang pesan ke bot atau isi formulir manual.'),
            StatusAiDraft::Confirmed => throw new GoneHttpException('Draf sudah disimpan sebelumnya.'),
            StatusAiDraft::Discarded => throw new GoneHttpException('Draf sudah dibuang.'),
        };
    }

    /** Buang draf pending (idempoten). Draf terkonfirmasi tidak dapat dibuang (409). */
    public function discard(AiDraft $draft): void
    {
        if ($draft->status === StatusAiDraft::Confirmed) {
            throw new ConflictHttpException('Draf sudah disimpan dan tidak dapat dibuang.');
        }

        if ($draft->status !== StatusAiDraft::Discarded) {
            $draft->forceFill(['status' => StatusAiDraft::Discarded])->save();
        }
    }

    /** @return Collection<int, AiDraft> draf pending yang belum kedaluwarsa, terbaru dulu. */
    public function pendingFor(User $user): Collection
    {
        $this->expireFor($user);

        return $this->ownQuery($user)->pending()->where('expires_at', '>', now())
            ->orderByDesc('id')->limit((int) config('ai.drafts.max_pending_per_user', 10))->get();
    }

    /** Tandai expired semua draf pending yang lewat masa berlaku (seluruh sistem bila $user null). */
    public function expireFor(?User $user = null): int
    {
        $query = $user === null
            ? AiDraft::query()->withoutGlobalScope(SppgScope::class)
            : $this->ownQuery($user);

        return $query->sudahKedaluwarsa()->update(['status' => StatusAiDraft::Expired->value, 'updated_at' => now()]);
    }

    /** @return Builder<AiDraft> */
    private function ownQuery(User $user)
    {
        return AiDraft::query()
            ->withoutGlobalScope(SppgScope::class)
            ->where('user_id', $user->getKey())
            ->where('sppg_id', $user->sppg_id ?? 0);
    }
}

<?php

namespace App\Http\Resources\Pengguna;

use App\Services\Users\IssuedInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respons penerbitan undangan: satu-satunya tempat token mentah dan deep_link tampil.
 *
 * @property IssuedInvitation $resource
 */
class UndanganDibuatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(
            (new UndanganResource($this->resource->invitation))->toArray($request),
            ['token' => $this->resource->token, 'deep_link' => $this->resource->deepLink],
        );
    }

    public static function created(IssuedInvitation $issued, Request $request): JsonResponse
    {
        return (new self($issued))->response($request)
            ->setStatusCode(201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}

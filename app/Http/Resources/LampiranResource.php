<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsWib;
use App\Models\Lampiran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata lampiran. `url` adalah endpoint API (butuh Bearer token), bukan URL publik;
 * jalur penyimpanan internal tidak pernah dikirim.
 *
 * @mixin Lampiran
 */
class LampiranResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kategori' => $this->kategori,
            'mime' => $this->mime,
            'ukuran' => $this->ukuran,
            'sha256' => $this->sha256,
            'url' => $this->url(),
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->wib($this->created_at),
        ];
    }
}

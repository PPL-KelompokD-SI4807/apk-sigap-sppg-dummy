<?php

namespace App\Http\Requests\BahanBaku;

use App\Models\BahanBaku;

class UpdateBahanBakuRequest extends StoreBahanBakuRequest
{
    private function bahan(): ?BahanBaku
    {
        $bahan = $this->route('bahan_baku');

        return $bahan instanceof BahanBaku ? $bahan : null;
    }

    protected function sppgId(): ?int
    {
        return $this->bahan()?->sppg_id;
    }

    protected function ignoreId(): ?int
    {
        return $this->bahan()?->getKey();
    }
}

<?php

namespace App\Http\Resources\Concerns;

use Carbon\CarbonInterface;

/** Format waktu ISO 8601 pada zona bisnis (Asia/Jakarta) untuk respons API. */
trait FormatsWib
{
    protected function wib(?CarbonInterface $waktu): ?string
    {
        return $waktu?->copy()->setTimezone(config('operasional.timezone', 'Asia/Jakarta'))->toIso8601String();
    }
}

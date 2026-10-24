<?php

namespace App\Services\Users;

use App\Enums\TipeUndangan;
use App\Models\Invitation;
use App\Models\TitikLayanan;
use App\Models\User;

/** Hasil InvitationService::consumeAny(): `user` untuk undangan pengguna, `titikLayanan` untuk undangan PIC. */
final class ConsumedInvitation
{
    public function __construct(
        public readonly TipeUndangan $tipe,
        public readonly Invitation $invitation,
        public readonly ?User $user = null,
        public readonly ?TitikLayanan $titikLayanan = null,
    ) {}
}

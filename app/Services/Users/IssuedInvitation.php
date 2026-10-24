<?php

namespace App\Services\Users;

use App\Models\Invitation;

/**
 * Hasil InvitationService::issue(). `token` adalah satu-satunya salinan token mentah:
 * jangan disimpan, di-log, atau ditampilkan ulang.
 */
final class IssuedInvitation
{
    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $token,
        public readonly ?string $deepLink,
    ) {}

    public function __debugInfo(): array
    {
        return ['invitation' => $this->invitation->getKey(), 'token' => '[redacted]'];
    }
}

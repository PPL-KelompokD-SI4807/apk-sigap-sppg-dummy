<?php

namespace App\Services\Ai;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Menjalankan kode atas nama pengguna (queue worker/bot tidak punya sesi HTTP) sehingga
 * SppgScope, BelongsToSppg, dan Policy berlaku persis seperti permintaan Mini App.
 * Pengguna sebelumnya dipulihkan setelah selesai agar worker tidak "membawa" identitas ke job lain.
 */
final class ActingUser
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(User $user, Closure $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            } else {
                Auth::forgetGuards();
            }
        }
    }
}

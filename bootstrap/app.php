<?php

use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveSppgAktif;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ApiSecurityHeaders::class);
        $middleware->alias(['active' => EnsureUserIsActive::class]);
        // SPPG aktif (R-06a) harus tervalidasi sebelum route model binding ber-SppgScope.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveSppgAktif::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Format galat API konsisten: {"message": "...", "errors"?: {...}}.
         * Detail internal (kelas model, SQL, stack trace) tidak pernah dikirim kecuali
         * APP_DEBUG=true di luar production.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'Data yang dikirim tidak valid.',
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Tidak terautentikasi. Silakan buka ulang Mini App.',
                ], 401);
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            $message = match (true) {
                $status === 403 => in_array($e->getMessage(), ['', 'This action is unauthorized.'], true)
                    ? 'Anda tidak memiliki hak akses untuk tindakan ini.'
                    : $e->getMessage(),
                $status === 404 => 'Data tidak ditemukan.',
                $status === 405 => 'Metode HTTP tidak didukung untuk endpoint ini.',
                $status === 429 => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.',
                $status >= 500 => 'Terjadi kesalahan pada server.',
                default => $e->getMessage() !== '' ? $e->getMessage() : 'Permintaan tidak dapat diproses.',
            };

            $body = ['message' => $message];

            if ($status >= 500 && config('app.debug') && ! app()->isProduction()) {
                $body['debug'] = ['exception' => $e::class, 'detail' => $e->getMessage()];
            }

            return response()->json($body, $status, $headers);
        });
    })->create();

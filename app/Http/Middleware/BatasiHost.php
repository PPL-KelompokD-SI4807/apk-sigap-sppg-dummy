<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BatasiHost
{
    public function handle(Request $request, Closure $next, string $konfigurasi): Response
    {
        abort_unless(self::sesuai($request, $konfigurasi), 404);

        return $next($request);
    }

    public static function sesuai(Request $request, string $konfigurasi): bool
    {
        $host = parse_url((string) config($konfigurasi), PHP_URL_HOST);

        return is_string($host) && $request->getHost() === strtolower($host);
    }
}

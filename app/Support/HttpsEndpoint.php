<?php

namespace App\Support;

/**
 * Validasi URL endpoint layanan eksternal (LLM, STT) yang HANYA berasal dari config (mitigasi SSRF):
 * wajib https, host ada, tanpa userinfo, tanpa query, tanpa fragmen, tanpa spasi/karakter kontrol.
 * Pengalihan (redirect) dimatikan di sisi klien HTTP masing-masing.
 */
final class HttpsEndpoint
{
    public static function valid(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || strpbrk($url, '@?#') !== false) {
            return false;
        }

        return true;
    }
}

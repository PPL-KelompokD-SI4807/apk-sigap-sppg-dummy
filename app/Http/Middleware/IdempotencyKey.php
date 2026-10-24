<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Idempotency-Key untuk POST (NFR-07: kirim ulang dari Mini App tidak menggandakan data).
 *
 * Pasang langsung pada rute: Route::post(...)->middleware(IdempotencyKey::class).
 *
 * - Tanpa header `Idempotency-Key`: diteruskan apa adanya.
 * - Header harus UUID, jika tidak 422.
 * - Kunci cache = (pengguna, kunci, path). Respons 2xx disimpan 24 jam (status + body +
 *   Content-Type) beserta sidik jari payload (sha256 input + sha256 isi tiap berkas).
 * - Pengulangan dengan payload sama -> respons tersimpan dikembalikan (header
 *   `Idempotent-Replayed: true`), aksi TIDAK dijalankan lagi.
 * - Kunci sama dengan payload berbeda -> 422.
 * - Permintaan kembar yang masih diproses -> 409 (lock atomik cache).
 * - Respons non-2xx tidak disimpan sehingga klien boleh memperbaiki data dengan kunci sama.
 */
class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const TTL_MENIT = 1440;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->headers->has(self::HEADER)) {
            return $next($request);
        }

        $kunci = trim((string) $request->headers->get(self::HEADER));

        if (! Str::isUuid($kunci)) {
            return response()->json([
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => [self::HEADER => ['Header Idempotency-Key harus berupa UUID.']],
            ], 422);
        }

        $pengguna = $request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip();
        $cacheKey = 'idempotency:'.hash('sha256', $pengguna.'|'.strtolower($kunci).'|'.$request->path());
        $sidikJari = $this->sidikJari($request);

        if (($tersimpan = Cache::get($cacheKey)) !== null) {
            return $this->ulang($tersimpan, $sidikJari);
        }

        $lock = Cache::lock($cacheKey.':lock', 60);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Permintaan dengan Idempotency-Key yang sama sedang diproses. Coba lagi sebentar lagi.',
            ], 409);
        }

        try {
            if (($tersimpan = Cache::get($cacheKey)) !== null) {
                return $this->ulang($tersimpan, $sidikJari);
            }

            $response = $next($request);

            if ($response->isSuccessful()
                && ! $response instanceof StreamedResponse
                && ! $response instanceof BinaryFileResponse) {
                Cache::put($cacheKey, [
                    'sidik_jari' => $sidikJari,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'application/json'),
                ], now()->addMinutes(self::TTL_MENIT));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /** @param array{sidik_jari: string, status: int, body: string, content_type: string} $tersimpan */
    private function ulang(array $tersimpan, string $sidikJari): Response
    {
        if (! hash_equals($tersimpan['sidik_jari'], $sidikJari)) {
            return response()->json([
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => [self::HEADER => ['Idempotency-Key sudah dipakai untuk permintaan dengan data berbeda.']],
            ], 422);
        }

        return response($tersimpan['body'], $tersimpan['status'], [
            'Content-Type' => $tersimpan['content_type'],
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function sidikJari(Request $request): string
    {
        $input = $request->except(array_keys($request->allFiles()));
        $this->urutkan($input);

        $berkas = [];

        foreach ($request->allFiles() as $nama => $file) {
            $berkas[$nama] = $this->hashBerkas($file);
        }

        ksort($berkas);

        return hash('sha256', $request->method().'|'.$request->path().'|'
            .json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR).'|'
            .json_encode($berkas));
    }

    /** @return string|array<array-key, mixed> */
    private function hashBerkas(mixed $file): string|array
    {
        if (is_array($file)) {
            return array_map(fn ($f) => $this->hashBerkas($f), $file);
        }

        if ($file instanceof UploadedFile && ($path = $file->getRealPath()) !== false && is_file($path)) {
            return (string) hash_file('sha256', $path);
        }

        return '-';
    }

    /** @param array<array-key, mixed> $data */
    private function urutkan(array &$data): void
    {
        ksort($data);

        foreach ($data as &$nilai) {
            if (is_array($nilai)) {
                $this->urutkan($nilai);
            }
        }
    }
}

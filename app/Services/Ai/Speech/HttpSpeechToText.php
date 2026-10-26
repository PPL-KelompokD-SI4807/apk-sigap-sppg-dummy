<?php

namespace App\Services\Ai\Speech;

use App\Support\HttpsEndpoint;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * STT HTTP generik bergaya OpenAI /audio/transcriptions: POST multipart (file, model, language),
 * Authorization: Bearer <STT_API_KEY>, balasan JSON {"text": "..."}. Tidak terikat vendor; cocok
 * dengan Groq (STT_URL=https://api.groq.com/openai/v1/audio/transcriptions,
 * STT_MODEL=whisper-large-v3-turbo).
 *
 * Keamanan: URL hanya dari config, wajib https tanpa userinfo/query, redirect tidak diikuti.
 * Kunci tidak pernah di-log; galat dibungkus pesan aman per status (tanpa body respons).
 */
class HttpSpeechToText implements SpeechToTextService
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $apiKey,
        private readonly string $model = 'whisper-1',
        private readonly string $language = 'id',
        private readonly int $timeout = 20,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('ai.stt');

        return new self(
            is_string($c['url'] ?? null) ? trim($c['url']) : null,
            is_string($c['api_key'] ?? null) ? trim($c['api_key']) : null,
            (string) ($c['model'] ?? 'whisper-1'),
            (string) ($c['language'] ?? 'id'),
            (int) ($c['timeout'] ?? 20),
        );
    }

    public function isAvailable(): bool
    {
        return HttpsEndpoint::valid($this->url);
    }

    public function transcribe(string $audio, string $mimeType = 'audio/ogg', string $filename = 'voice.ogg'): string
    {
        if (! $this->isAvailable()) {
            throw new SpeechToTextException(NullSpeechToText::MESSAGE);
        }

        try {
            $request = Http::timeout(max(1, $this->timeout))->connectTimeout(5)->acceptJson()->withoutRedirecting();

            if ($this->apiKey !== null && $this->apiKey !== '') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request
                ->attach('file', $audio, $filename, ['Content-Type' => $mimeType])
                ->post((string) $this->url, ['model' => $this->model, 'language' => $this->language]);
        } catch (Throwable) {
            throw new SpeechToTextException('Layanan transkripsi suara tidak dapat dihubungi. Silakan ketik pesan.');
        }

        if (! $response->successful()) {
            throw new SpeechToTextException(match (true) {
                in_array($response->status(), [401, 403, 404], true) => 'Layanan transkripsi suara belum dikonfigurasi dengan benar. Silakan ketik pesan.',
                $response->status() === 413 => 'Pesan suara terlalu besar untuk ditranskripsi. Kirim pesan yang lebih pendek atau ketik pesan.',
                $response->status() === 429 => 'Layanan transkripsi suara sedang sibuk. Coba lagi sebentar lagi atau ketik pesan.',
                default => 'Pesan suara tidak dapat ditranskripsi. Silakan ketik pesan.',
            });
        }

        $text = $response->json('text');

        if (! is_string($text) || trim($text) === '') {
            throw new SpeechToTextException('Pesan suara tidak dapat ditranskripsi. Silakan ketik pesan.');
        }

        return trim($text);
    }
}

<?php

namespace App\Services\Ai\Speech;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * STT HTTP generik bergaya OpenAI /audio/transcriptions: POST multipart (file, model, language),
 * Authorization: Bearer <STT_API_KEY>, balasan JSON {"text": "..."}. Tidak terikat vendor.
 * Kunci tidak pernah di-log; galat dibungkus pesan aman.
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
            is_string($c['url'] ?? null) ? $c['url'] : null,
            is_string($c['api_key'] ?? null) ? $c['api_key'] : null,
            (string) ($c['model'] ?? 'whisper-1'),
            (string) ($c['language'] ?? 'id'),
            (int) ($c['timeout'] ?? 20),
        );
    }

    public function isAvailable(): bool
    {
        return is_string($this->url) && str_starts_with(strtolower($this->url), 'https://');
    }

    public function transcribe(string $audio, string $mimeType = 'audio/ogg', string $filename = 'voice.ogg'): string
    {
        if (! $this->isAvailable()) {
            throw new SpeechToTextException(NullSpeechToText::MESSAGE);
        }

        try {
            $request = Http::timeout($this->timeout)->connectTimeout(5)->acceptJson();

            if ($this->apiKey !== null && $this->apiKey !== '') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request
                ->attach('file', $audio, $filename, ['Content-Type' => $mimeType])
                ->post((string) $this->url, ['model' => $this->model, 'language' => $this->language]);
        } catch (Throwable) {
            throw new SpeechToTextException('Layanan transkripsi suara tidak dapat dihubungi.');
        }

        $text = $response->successful() ? $response->json('text') : null;

        if (! is_string($text) || trim($text) === '') {
            throw new SpeechToTextException('Pesan suara tidak dapat ditranskripsi. Silakan ketik pesan.');
        }

        return trim($text);
    }
}

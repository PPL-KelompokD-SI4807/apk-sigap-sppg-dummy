<?php

namespace App\Services\Ai\Speech;

/** STT belum dikonfigurasi: bot membalas bahwa pesan suara belum didukung. */
class NullSpeechToText implements SpeechToTextService
{
    public const MESSAGE = 'Pesan suara belum didukung. Silakan ketik pesan atau kirim foto.';

    public function isAvailable(): bool
    {
        return false;
    }

    public function transcribe(string $audio, string $mimeType = 'audio/ogg', string $filename = 'voice.ogg'): string
    {
        throw new SpeechToTextException(self::MESSAGE);
    }
}

<?php

namespace App\Services\Ai\Speech;

/**
 * Transkripsi pesan suara (KT-11/KT-12). Implementasi dipilih lewat config('ai.stt.driver'):
 * '' -> NullSpeechToText (belum didukung), 'http' -> HttpSpeechToText (endpoint generik).
 */
interface SpeechToTextService
{
    public function isAvailable(): bool;

    /**
     * @param  string  $audio  biner audio (Telegram voice: OGG/Opus)
     *
     * @throws SpeechToTextException
     */
    public function transcribe(string $audio, string $mimeType = 'audio/ogg', string $filename = 'voice.ogg'): string;
}

<?php

namespace App\Services\Ai;

use App\Enums\JenisInputAi;

/**
 * Masukan Agen AI dari chat bot. Seluruh isi (teks, caption, gambar, transkrip) = DATA TAK TEPERCAYA.
 */
final class AiInput
{
    /**
     * @param  list<array{media_type: string, data: string}>  $images  gambar base64 (foto nota/surat jalan)
     */
    public function __construct(
        public readonly JenisInputAi $type,
        public readonly string $text = '',
        public readonly array $images = [],
    ) {}

    public static function text(string $text): self
    {
        return new self(JenisInputAi::Teks, $text);
    }

    /** @param list<array{media_type: string, data: string}> $images */
    public static function photo(array $images, string $caption = ''): self
    {
        return new self(JenisInputAi::Foto, $caption, $images);
    }

    public static function voice(string $transcript): self
    {
        return new self(JenisInputAi::Suara, $transcript);
    }
}

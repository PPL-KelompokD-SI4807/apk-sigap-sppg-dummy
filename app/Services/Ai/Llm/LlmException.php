<?php

namespace App\Services\Ai\Llm;

use RuntimeException;

/**
 * Galat penyedia LLM (timeout, kuota, respons rusak, belum dikonfigurasi). Pesan aman untuk
 * dicatat: tidak memuat kunci API maupun isi percakapan.
 */
class LlmException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Agen AI belum dikonfigurasi.');
    }
}

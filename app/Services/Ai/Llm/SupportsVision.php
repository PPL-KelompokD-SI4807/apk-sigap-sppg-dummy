<?php

namespace App\Services\Ai\Llm;

/**
 * Kapabilitas opsional "baca gambar" untuk LlmClient yang memakai model vision TERPISAH dari model
 * teks (mis. OpenAiCompatibleLlmClient + LLM_VISION_MODEL). LlmClient sendiri tidak berubah.
 *
 * Klien yang mengimplementasikan antarmuka ini diproses dua tahap oleh AiAgentService (D6):
 *   1. readImages(): model vision TANPA tool mengekstrak teks/uraian gambar;
 *   2. teks hasil baca diperlakukan sebagai DATA tak tepercaya (dibungkus nonce seperti teks
 *      pengguna) lalu diproses model teks yang memegang tool.
 * Klien yang TIDAK mengimplementasikan antarmuka ini (Anthropic, Fake) tetap menerima gambar
 * langsung di pesan pengguna (perilaku lama).
 */
interface SupportsVision
{
    /** False = gambar tidak didukung (LLM_VISION_MODEL kosong): bot membalas ramah sebelum mengunduh foto. */
    public function supportsVision(): bool;

    /** Nama model vision untuk log (bukan rahasia), null bila tidak didukung. */
    public function visionModelName(): ?string;

    /**
     * Ekstrak teks/uraian dari gambar. Hasil = DATA TAK TEPERCAYA (bisa memuat perintah injeksi).
     *
     * @param  list<array{media_type: string, data: string}>  $images  base64, sudah lolos cek MIME/ukuran
     * @param  array<string, mixed>  $options  max_tokens, deadline (microtime float)
     *
     * @throws LlmException
     */
    public function readImages(array $images, string $instruction, array $options = []): LlmResponse;
}

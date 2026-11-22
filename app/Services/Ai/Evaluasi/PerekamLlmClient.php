<?php

namespace App\Services\Ai\Evaluasi;

use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Llm\ToolCall;

/**
 * Pembungkus LlmClient untuk evaluasi akurasi Agen AI (NFR-11, `sigap:ai-evaluasi`). Dipasang SATU per kasus.
 *
 * - Meneruskan panggilan pertama ke klien asli (tool + prompt persis seperti produksi) dan MEREKAM pemanggilan tool
 *   yang dipilih model beserta argumen mentahnya (sebelum disaring/divalidasi pipeline).
 * - Begitu model memilih tool, panggilan berikutnya (iterasi ringkasan setelah hasil tool) dijawab teks tetap TANPA
 *   menghubungi penyedia: evaluasi hanya sampai tahap pemilihan tool + argumen, sehingga kuota tidak terbuang.
 * - Galat penyedia (429/timeout/dll.) direkam lalu dilempar ulang agar pipeline menanganinya seperti biasa.
 */
final class PerekamLlmClient implements LlmClient
{
    /** Jumlah panggilan yang BENAR-BENAR diteruskan ke klien asli. */
    public int $panggilan = 0;

    /** @var list<ToolCall> pemanggilan tool pada respons pertama yang memilih tool */
    public array $toolCalls = [];

    /** Galat penyedia terakhir (null bila panggilan terakhir berhasil). */
    public ?LlmException $galat = null;

    /** @var array{tool: string, argumen: array<string, mixed>}|null tool call yang ditolak penyedia (tool_use_failed) */
    public ?array $ditolakPenyedia = null;

    public int $tokenMasuk = 0;

    public int $tokenKeluar = 0;

    private bool $selesai = false;

    public function __construct(private readonly LlmClient $klien) {}

    public function chat(array $messages, array $tools, array $options = []): LlmResponse
    {
        if ($this->selesai) {
            return LlmResponse::text('Selesai.');
        }

        $this->panggilan++;

        try {
            $respons = $this->klien->chat($messages, $tools, $options);
        } catch (LlmException $e) {
            $this->galat = $e;

            if ($e->toolGagal() && $e->tool !== null) {
                $this->ditolakPenyedia = ['tool' => $e->tool, 'argumen' => $e->argumenGagal ?? []];
            }

            throw $e;
        }

        $this->galat = null;
        $this->tokenMasuk += $respons->inputTokens;
        $this->tokenKeluar += $respons->outputTokens;

        if ($respons->hasToolCalls()) {
            $this->toolCalls = $respons->toolCalls;
            $this->selesai = true;
        }

        return $respons;
    }

    public function modelName(): string
    {
        return $this->klien->modelName();
    }
}

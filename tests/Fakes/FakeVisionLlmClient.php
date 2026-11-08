<?php

namespace Tests\Fakes;

use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Llm\SupportsVision;
use Throwable;

/**
 * LLM palsu dengan model vision TERPISAH (dua tahap D6, seperti OpenAiCompatibleLlmClient + LLM_VISION_MODEL):
 * readImages() mengambil langkah dari skrip vision; chat() dari skrip FakeLlmClient biasa. Hanya untuk tes.
 */
class FakeVisionLlmClient extends FakeLlmClient implements SupportsVision
{
    /** @var list<array{images: array, instruction: string, options: array}> */
    public array $visionCalls = [];

    /** @var list<LlmResponse|Throwable> */
    private array $visionScript = [];

    public function __construct(private readonly ?string $vision = 'vision-palsu')
    {
        parent::__construct();
    }

    public function pushVision(LlmResponse|Throwable $step): static
    {
        $this->visionScript[] = $step;

        return $this;
    }

    public function supportsVision(): bool
    {
        return $this->vision !== null;
    }

    public function visionModelName(): ?string
    {
        return $this->vision;
    }

    public function readImages(array $images, string $instruction, array $options = []): LlmResponse
    {
        $this->visionCalls[] = ['images' => $images, 'instruction' => $instruction, 'options' => $options];
        $step = $this->visionScript === [] ? LlmResponse::text('NOTA TOKO FIKTIF') : array_shift($this->visionScript);

        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step;
    }
}

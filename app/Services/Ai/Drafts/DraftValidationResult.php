<?php

namespace App\Services\Ai\Drafts;

/** Hasil validasi dry-run payload draf terhadap Form Request modul. */
final class DraftValidationResult
{
    /**
     * @param  array<string, list<string>>  $errors  galat per field (kunci Laravel, mis. "items.0.jumlah")
     * @param  array<string, float>  $confidence  keyakinan per field tingkat atas (0 = perlu dicek)
     * @param  array<string, mixed>  $valid  subset payload yang lolos validasi
     */
    public function __construct(
        public readonly bool $authorized,
        public readonly array $errors = [],
        public readonly array $confidence = [],
        public readonly array $valid = [],
    ) {}

    public function passes(): bool
    {
        return $this->authorized && $this->errors === [];
    }

    /** @return list<string> field tingkat atas yang perlu dicek (galat atau keyakinan rendah). */
    public function needsReview(float $threshold): array
    {
        $fields = array_map(fn ($k) => explode('.', (string) $k)[0], array_keys($this->errors));

        foreach ($this->confidence as $field => $score) {
            if ($score < $threshold) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }
}

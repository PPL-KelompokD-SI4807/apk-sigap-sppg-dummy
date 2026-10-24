<?php

namespace App\Services\Ai\Tools;

/**
 * Validator ketat argumen tool (subset JSON Schema) untuk argumen dari model yang TIDAK tepercaya.
 *
 * Didukung: type (object, string, integer, number, boolean, array, null; atau daftar tipe),
 * properties, required, additionalProperties (default FALSE = properti tak dikenal ditolak;
 * boleh berupa skema), maxProperties, enum, minLength/maxLength (default maks 1000),
 * pattern, format (date Y-m-d, time H:i), minimum/maximum, items, maxItems (default 50).
 * Kedalaman maksimum 4 level. Karakter NUL/kontrol (selain tab/baris baru) ditolak.
 */
final class ArgumentValidator
{
    public const MAX_DEPTH = 4;

    public const DEFAULT_MAX_STRING = 1000;

    public const DEFAULT_MAX_ITEMS = 50;

    public const DEFAULT_MAX_PROPERTIES = 40;

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> pesan galat (kosong = sah)
     */
    public function validate(array $schema, mixed $value): array
    {
        if (self::depthOf($value) > self::MAX_DEPTH) {
            return ['argumen: struktur terlalu dalam.'];
        }

        $errors = [];
        $this->check($schema, $value, '', 0, $errors);

        return $errors;
    }

    /** Kedalaman struktur array (skalar = 0), berhenti lebih awal di atas MAX_DEPTH. */
    private static function depthOf(mixed $value, int $level = 0): int
    {
        if (! is_array($value) || $level > self::MAX_DEPTH) {
            return $level;
        }

        $max = $level + 1;

        foreach ($value as $item) {
            $max = max($max, self::depthOf($item, $level + 1));
        }

        return $max;
    }

    /**
     * Buang argumen terlindungi (sppg_id, user_id, role, created_by, ...) di semua level.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $protected
     * @return array{0: array<string, mixed>, 1: list<string>} [argumen bersih, path yang dibuang]
     */
    public static function stripProtected(array $arguments, array $protected, string $prefix = '', int $depth = 0): array
    {
        $removed = [];

        if ($depth > self::MAX_DEPTH) {
            return [$arguments, $removed];
        }

        foreach ($arguments as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_string($key) && in_array(strtolower($key), $protected, true)) {
                unset($arguments[$key]);
                $removed[] = $path;

                continue;
            }

            if (is_array($value)) {
                [$arguments[$key], $nested] = self::stripProtected($value, $protected, $path, $depth + 1);
                $removed = array_merge($removed, $nested);
            }
        }

        return [$arguments, $removed];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function check(array $schema, mixed $value, string $path, int $depth, array &$errors): void
    {
        $label = $path === '' ? 'argumen' : $path;

        if ($depth > self::MAX_DEPTH) {
            $errors[] = "{$label}: struktur terlalu dalam.";

            return;
        }

        $types = (array) ($schema['type'] ?? 'string');

        if ($value === null) {
            if (! in_array('null', $types, true)) {
                $errors[] = "{$label}: tidak boleh null.";
            }

            return;
        }

        $type = $this->matchType($types, $value);

        if ($type === null) {
            $errors[] = "{$label}: tipe harus ".implode('|', $types).'.';

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$label}: nilai tidak diizinkan.";

            return;
        }

        match ($type) {
            'object' => $this->checkObject($schema, $value, $path, $depth, $errors),
            'array' => $this->checkArray($schema, $value, $path, $depth, $errors),
            'string' => $this->checkString($schema, $value, $label, $errors),
            'integer', 'number' => $this->checkNumber($schema, $value, $label, $errors),
            default => null,
        };
    }

    /** @param list<string> $types */
    private function matchType(array $types, mixed $value): ?string
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'boolean' => is_bool($value),
                default => false,
            };

            if ($ok) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<mixed>  $value
     * @param  list<string>  $errors
     */
    private function checkObject(array $schema, array $value, string $path, int $depth, array &$errors): void
    {
        $properties = (array) ($schema['properties'] ?? []);
        $additional = $schema['additionalProperties'] ?? false;
        $max = (int) ($schema['maxProperties'] ?? self::DEFAULT_MAX_PROPERTIES);

        if (count($value) > $max) {
            $errors[] = ($path === '' ? 'argumen' : $path).": maksimal {$max} properti.";

            return;
        }

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $value)) {
                $errors[] = ($path === '' ? '' : $path.'.')."{$required}: wajib diisi.";
            }
        }

        foreach ($value as $key => $item) {
            $childPath = ($path === '' ? '' : $path.'.').$key;

            if (! is_string($key) || preg_match('/^[a-zA-Z0-9_]{1,64}$/', $key) !== 1) {
                $errors[] = 'nama argumen tidak valid.';

                continue;
            }

            if (array_key_exists($key, $properties)) {
                $this->check((array) $properties[$key], $item, $childPath, $depth + 1, $errors);
            } elseif (is_array($additional)) {
                $this->check($additional, $item, $childPath, $depth + 1, $errors);
            } elseif ($additional !== true) {
                $errors[] = "{$childPath}: argumen tidak dikenal.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<mixed>  $value
     * @param  list<string>  $errors
     */
    private function checkArray(array $schema, array $value, string $path, int $depth, array &$errors): void
    {
        $max = (int) ($schema['maxItems'] ?? self::DEFAULT_MAX_ITEMS);
        $min = (int) ($schema['minItems'] ?? 0);

        if (count($value) > $max || count($value) < $min) {
            $errors[] = ($path === '' ? 'argumen' : $path).": jumlah item harus {$min}-{$max}.";

            return;
        }

        $items = (array) ($schema['items'] ?? ['type' => 'string']);

        foreach ($value as $i => $item) {
            $this->check($items, $item, ($path === '' ? '' : $path.'.').$i, $depth + 1, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkString(array $schema, string $value, string $label, array &$errors): void
    {
        $length = mb_strlen($value);
        $max = (int) ($schema['maxLength'] ?? self::DEFAULT_MAX_STRING);
        $min = (int) ($schema['minLength'] ?? 0);

        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $errors[] = "{$label}: memuat karakter tidak valid.";

            return;
        }

        if ($length > $max || $length < $min) {
            $errors[] = "{$label}: panjang harus {$min}-{$max} karakter.";

            return;
        }

        if (isset($schema['pattern']) && @preg_match('#'.str_replace('#', '\#', (string) $schema['pattern']).'#u', $value) !== 1) {
            $errors[] = "{$label}: format tidak sesuai.";

            return;
        }

        $format = $schema['format'] ?? null;

        if ($format === 'date') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($d === false || $d->format('Y-m-d') !== $value) {
                $errors[] = "{$label}: tanggal harus YYYY-MM-DD.";
            }
        } elseif ($format === 'time' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            $errors[] = "{$label}: jam harus HH:MM.";
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkNumber(array $schema, int|float $value, string $label, array &$errors): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$label}: minimal {$schema['minimum']}.";
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$label}: maksimal {$schema['maximum']}.";
        }
    }
}

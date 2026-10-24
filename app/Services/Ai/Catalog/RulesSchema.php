<?php

namespace App\Services\Ai\Catalog;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Throwable;

/**
 * Menurunkan skema argumen tool (subset JSON Schema untuk ArgumentValidator) dari aturan validasi
 * Form Request modul, sehingga skema selalu sejalan dengan REST API: kolom baru di Form Request
 * otomatis ikut ditawarkan ke LLM tanpa mengubah definisi tool. Aturan didefinisikan sekali.
 *
 * Yang dipetakan: tipe (string/integer/number/boolean/array), nullable, wajib tanpa syarat
 * (required/present tanpa sometimes), enum (Rule::enum/Rule::in/in:), min/max/between, format
 * tanggal (Y-m-d) dan jam (H:i), serta item objek untuk aturan `induk.*.anak`. Tidak dipetakan
 * (tetap divalidasi Form Request saat dry-run): regex, exists, unique, aturan bersyarat.
 * Kolom file, lampiran, ai_draft_id, dan argumen terlindungi (sppg_id, user_id, ...) tidak ditawarkan.
 */
final class RulesSchema
{
    /** @var array<string, array{properties: array<string, array<string, mixed>>, required: list<string>}> */
    private static array $memo = [];

    private const ALWAYS_SKIP = ['ai_draft_id', 'lampiran_ids'];

    /** Kolom *_id -> tool cari_* yang menyediakan nilainya (untuk deskripsi ke LLM). */
    private const ID_SOURCES = [
        'titik_layanan_id' => 'cari_titik_layanan',
        'pemasok_id' => 'cari_pemasok',
        'bahan_baku_id' => 'cari_bahan_baku',
        'menu_id' => 'cari_menu',
        'jadwal_produksi_id' => 'cari_jadwal_produksi',
        'jadwal_distribusi_id' => 'cari_jadwal_distribusi',
        'armada_id' => 'cari_armada',
        'pengemudi_id' => 'cari_pengemudi',
        'petugas_id' => 'cari_pengguna',
        'pencairan_dana_id' => 'cari_pencairan_dana',
    ];

    public static function forget(): void
    {
        self::$memo = [];
    }

    /**
     * @param  class-string<FormRequest>  $formRequest
     * @param  class-string|null  $model  kelas model untuk parameter rute (update)
     * @param  list<string>  $exclude
     * @param  array<string, string>  $hints
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}
     */
    public static function derive(string $formRequest, ?string $routeParameter = null, ?string $model = null, array $exclude = [], array $hints = []): array
    {
        $key = implode('|', [$formRequest, (string) $routeParameter, (string) $model, implode(',', $exclude), md5(serialize($hints))]);

        return self::$memo[$key] ??= self::build($formRequest, $routeParameter, $model, $exclude, $hints);
    }

    /**
     * @param  class-string<FormRequest>  $formRequest
     * @param  list<string>  $exclude
     * @param  array<string, string>  $hints
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}
     */
    private static function build(string $formRequest, ?string $routeParameter, ?string $model, array $exclude, array $hints): array
    {
        $protected = array_map('strtolower', (array) config('ai.protected_arguments', []));
        $properties = [];
        $required = [];
        $nested = [];

        foreach (self::rulesOf($formRequest, $routeParameter, $model) as $field => $ruleSet) {
            $field = (string) $field;

            if (str_contains($field, '.')) {
                if (preg_match('/^([A-Za-z0-9_]+)\.\*\.([A-Za-z0-9_]+)$/', $field, $m) === 1) {
                    $nested[$m[1]][$m[2]] = $ruleSet;
                }

                continue;
            }

            if (in_array($field, self::ALWAYS_SKIP, true) || in_array($field, $exclude, true) || in_array(strtolower($field), $protected, true)) {
                continue;
            }

            $parsed = self::parse($ruleSet);

            if ($parsed['skip']) {
                continue;
            }

            $properties[$field] = self::schemaOf($field, $parsed, $hints);

            if ($parsed['required']) {
                $required[] = $field;
            }
        }

        foreach ($nested as $parent => $children) {
            if (! isset($properties[$parent])) {
                continue;
            }

            $itemProperties = [];
            $itemRequired = [];

            foreach ($children as $name => $ruleSet) {
                $parsed = self::parse($ruleSet);

                if ($parsed['skip']) {
                    continue;
                }

                $itemProperties[$name] = self::schemaOf($name, $parsed, $hints);

                if ($parsed['required']) {
                    $itemRequired[] = $name;
                }
            }

            $properties[$parent]['items'] = ['type' => 'object', 'properties' => $itemProperties, 'required' => $itemRequired, 'additionalProperties' => false];
        }

        return ['properties' => $properties, 'required' => $required];
    }

    /**
     * Aturan Form Request untuk permintaan sintetis milik pengguna placeholder (Kepala SPPG ber-SPPG),
     * persis cara DraftValidator membuatnya: POST untuk buat, PUT + model kosong untuk ubah.
     *
     * @param  class-string<FormRequest>  $class
     * @return array<string, mixed>
     */
    private static function rulesOf(string $class, ?string $routeParameter, ?string $model): array
    {
        try {
            $user = new User;
            $user->forceFill(['role' => Role::KepalaSppg, 'sppg_id' => 1, 'is_active' => true]);

            $parameters = $routeParameter !== null && $model !== null ? [$routeParameter => new $model] : [];

            /** @var FormRequest $request */
            $request = $class::create('/ai-schema', $parameters === [] ? 'POST' : 'PUT', []);
            $request->setContainer(app())->setRedirector(app('redirect'));
            $request->setUserResolver(fn () => $user);

            $route = new Route([$request->getMethod()], 'ai-schema', []);
            $route->parameters = $parameters;
            $request->setRouteResolver(fn () => $route);

            return (array) app()->call([$request, 'rules']);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return array{type: string, nullable: bool, required: bool, skip: bool, enum: list<mixed>|null, min: float|int|null, max: float|int|null, format: string|null, date: bool}
     */
    private static function parse(mixed $ruleSet): array
    {
        $tokens = is_string($ruleSet) ? explode('|', $ruleSet) : (array) $ruleSet;
        $flags = [];
        $out = ['type' => 'string', 'nullable' => false, 'required' => false, 'skip' => false, 'enum' => null, 'min' => null, 'max' => null, 'format' => null, 'date' => false];
        $sometimes = false;
        $between = null;

        foreach ($tokens as $token) {
            if (is_object($token)) {
                $values = self::enumValues($token);

                if ($values !== null) {
                    $out['enum'] = $values;
                }

                continue;
            }

            if (! is_string($token)) {
                continue;
            }

            [$name, $param] = array_pad(explode(':', $token, 2), 2, null);

            switch ($name) {
                case 'required':
                case 'present':
                    $out['required'] = true;
                    break;
                case 'sometimes':
                    $sometimes = true;
                    break;
                case 'nullable':
                    $out['nullable'] = true;
                    break;
                case 'exclude':
                case 'prohibited':
                case 'file':
                case 'image':
                case 'mimes':
                case 'mimetypes':
                    $out['skip'] = true;
                    break;
                case 'boolean':
                case 'accepted':
                    $flags['boolean'] = true;
                    break;
                case 'integer':
                    $flags['integer'] = true;
                    break;
                case 'numeric':
                case 'decimal':
                    $flags['number'] = true;
                    break;
                case 'array':
                    $flags['array'] = true;
                    break;
                case 'in':
                    $out['enum'] = array_map(fn (string $v) => trim($v, " \t\"'"), explode(',', (string) $param));
                    break;
                case 'max':
                    $out['max'] = is_numeric($param) ? $param + 0 : null;
                    break;
                case 'min':
                    $out['min'] = is_numeric($param) ? $param + 0 : null;
                    break;
                case 'between':
                    $between = array_map(fn (string $v) => is_numeric($v) ? $v + 0 : null, explode(',', (string) $param));
                    break;
                case 'date_format':
                    $out['format'] = match ($param) {
                        'Y-m-d' => 'date',
                        'H:i' => 'time',
                        default => null,
                    };
                    $out['date'] = true;
                    break;
                case 'date':
                    $out['date'] = true;
                    break;
            }
        }

        if ($between !== null && count($between) === 2) {
            [$out['min'], $out['max']] = $between;
        }

        if ($sometimes) {
            $out['required'] = false;
        }

        $out['type'] = match (true) {
            isset($flags['boolean']) => 'boolean',
            isset($flags['integer']) => 'integer',
            isset($flags['number']) => 'number',
            isset($flags['array']) => 'array',
            default => 'string',
        };

        // Enum bilangan bulat (backed enum int): tipe mengikuti nilai.
        if ($out['enum'] !== null && $out['type'] === 'string' && $out['enum'] !== [] && is_int($out['enum'][0])) {
            $out['type'] = 'integer';
        }

        return $out;
    }

    /**
     * @param  array{type: string, nullable: bool, required: bool, skip: bool, enum: list<mixed>|null, min: float|int|null, max: float|int|null, format: string|null, date: bool}  $parsed
     * @param  array<string, string>  $hints
     * @return array<string, mixed>
     */
    private static function schemaOf(string $field, array $parsed, array $hints): array
    {
        $schema = ['type' => $parsed['nullable'] ? [$parsed['type'], 'null'] : $parsed['type']];

        if ($parsed['enum'] !== null && $parsed['enum'] !== []) {
            $schema['enum'] = array_values($parsed['enum']);
        }

        match ($parsed['type']) {
            'string' => $schema += array_filter([
                'minLength' => $parsed['min'] !== null ? (int) $parsed['min'] : null,
                'maxLength' => $parsed['max'] !== null ? (int) $parsed['max'] : null,
            ], fn ($v) => $v !== null),
            'integer', 'number' => $schema += array_filter(['minimum' => $parsed['min'], 'maximum' => $parsed['max']], fn ($v) => $v !== null),
            'array' => $schema += array_filter([
                'minItems' => $parsed['min'] !== null ? (int) $parsed['min'] : null,
                'maxItems' => $parsed['max'] !== null ? (int) $parsed['max'] : null,
            ], fn ($v) => $v !== null),
            default => null,
        };

        if ($parsed['format'] !== null) {
            $schema['format'] = $parsed['format'];
        }

        $description = $hints[$field] ?? null;

        if ($description === null) {
            $description = match (true) {
                $parsed['format'] === 'date' => 'Tanggal YYYY-MM-DD.',
                $parsed['format'] === 'time' => 'Jam HH:MM (24 jam).',
                $parsed['date'] => 'Tanggal/waktu, mis. 2026-11-03 07:30.',
                isset(self::ID_SOURCES[$field]) => 'ID dari tool '.self::ID_SOURCES[$field].'.',
                default => null,
            };
        }

        if ($description !== null) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /** @return list<mixed>|null */
    private static function enumValues(object $rule): ?array
    {
        try {
            if ($rule instanceof Enum) {
                $type = self::property($rule, 'type');

                if (! is_string($type) || ! enum_exists($type)) {
                    return null;
                }

                $only = (array) self::property($rule, 'only');
                $except = (array) self::property($rule, 'except');
                $values = [];

                foreach ($type::cases() as $case) {
                    if (($only !== [] && ! in_array($case, $only, true)) || in_array($case, $except, true)) {
                        continue;
                    }

                    $values[] = $case->value ?? $case->name;
                }

                return $values;
            }

            if ($rule instanceof In) {
                $values = self::property($rule, 'values');

                return is_array($values) ? array_values($values) : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private static function property(object $object, string $name): mixed
    {
        return (fn () => $this->{$name} ?? null)->call($object);
    }
}

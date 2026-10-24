<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Menggabungkan fragmen OpenAPI menjadi docs/api/openapi.yaml.
 *
 *   php docs/api/build-openapi.php          # tulis ulang openapi.yaml
 *   php docs/api/build-openapi.php --check  # exit 1 bila openapi.yaml tidak sinkron (CI)
 *
 * Sumber:
 *   docs/api/base.yaml          openapi, info, servers, security, tags (tanpa paths/components)
 *   docs/api/paths/*.yaml       key tingkat atas = path relatif server (/bahan-baku, /bahan-baku/{id})
 *   docs/api/components/*.yaml  key tingkat atas = seksi komponen (schemas, responses, parameters, ...)
 *
 * Penggabungan bersifat tekstual (tanpa dependensi) sehingga fragmen WAJIB memakai indentasi
 * 2 spasi dan tanpa tab. Key path/komponen ganda antar-fragmen ditolak. Bila symfony/yaml
 * tersedia di vendor, hasil akhirnya juga di-parse untuk memastikan YAML sah.
 */
$dir = __DIR__;
$check = in_array('--check', $argv ?? [], true);

const COMPONENT_SECTIONS = [
    'securitySchemes', 'schemas', 'parameters', 'requestBodies', 'responses', 'headers', 'examples', 'links', 'callbacks', 'pathItems',
];

function fail(string $message): never
{
    fwrite(STDERR, "build-openapi: {$message}\n");
    exit(2);
}

/**
 * Pecah teks YAML menjadi blok per key tingkat atas.
 *
 * @return list<array{0: string, 1: string, 2: list<string>}> [key, baris key, baris anak]
 */
function topLevelBlocks(string $text, string $source): array
{
    $blocks = [];
    $current = null;

    foreach (preg_split('/\R/', $text) as $no => $line) {
        if (str_contains($line, "\t")) {
            fail("{$source}:".($no + 1).': tab tidak diizinkan, pakai spasi.');
        }

        if (preg_match('/^(\'[^\']+\'|"[^"]+"|[^\s#\'"-][^:]*):(?:\s|$)/', $line, $m) === 1) {
            if ($current !== null) {
                $blocks[] = $current;
            }
            $current = [trim($m[1], '\'"'), rtrim($line), []];

            continue;
        }

        if ($current === null) {
            if (trim($line) !== '' && ! str_starts_with(ltrim($line), '#')) {
                fail("{$source}:".($no + 1).': konten sebelum key tingkat atas pertama.');
            }

            continue;
        }

        $current[2][] = rtrim($line);
    }

    if ($current !== null) {
        $blocks[] = $current;
    }

    foreach ($blocks as &$block) {
        while ($block[2] !== [] && trim(end($block[2])) === '') {
            array_pop($block[2]);
        }
    }

    return $blocks;
}

/** @param list<string> $lines */
function indent(array $lines, int $spaces): string
{
    $pad = str_repeat(' ', $spaces);

    return implode("\n", array_map(fn ($l) => $l === '' ? '' : $pad.$l, $lines));
}

/** @return list<string> */
function sortedFiles(string $pattern): array
{
    $files = glob($pattern) ?: [];
    usort($files, function ($a, $b) {
        $rank = fn ($f) => basename($f) === 'common.yaml' ? 0 : 1;

        return [$rank($a), basename($a)] <=> [$rank($b), basename($b)];
    });

    return $files;
}

if (! is_file("{$dir}/base.yaml")) {
    fail('docs/api/base.yaml tidak ditemukan.');
}

$base = rtrim((string) file_get_contents("{$dir}/base.yaml"));

foreach (topLevelBlocks($base, 'base.yaml') as [$key]) {
    if (in_array($key, ['paths', 'components'], true)) {
        fail("base.yaml tidak boleh memuat '{$key}'; pindahkan ke fragmen.");
    }
}

// ---- paths ----
$paths = [];
$origin = [];

foreach (sortedFiles("{$dir}/paths/*.yaml") as $file) {
    $name = 'paths/'.basename($file);

    foreach (topLevelBlocks((string) file_get_contents($file), $name) as [$key, $line, $children]) {
        if (! str_starts_with($key, '/')) {
            fail("{$name}: key '{$key}' harus berupa path yang diawali '/'.");
        }

        if (isset($paths[$key])) {
            fail("{$name}: path '{$key}' sudah didefinisikan di {$origin[$key]}.");
        }

        $paths[$key] = array_merge([$line], $children);
        $origin[$key] = $name;
    }
}

// ---- components ----
$components = [];
$componentOrigin = [];

foreach (sortedFiles("{$dir}/components/*.yaml") as $file) {
    $name = 'components/'.basename($file);

    foreach (topLevelBlocks((string) file_get_contents($file), $name) as [$section, $line, $children]) {
        if (! in_array($section, COMPONENT_SECTIONS, true)) {
            fail("{$name}: seksi komponen '{$section}' tidak dikenal.");
        }

        if (trim(substr($line, strlen($section) + 1)) !== '') {
            fail("{$name}: seksi '{$section}' harus berupa blok (bukan nilai sebaris).");
        }

        $dedented = [];

        foreach ($children as $child) {
            if ($child !== '' && ! str_starts_with($child, '  ')) {
                fail("{$name}: isi seksi '{$section}' harus diindentasi 2 spasi.");
            }
            $dedented[] = (string) substr($child, 2);
        }

        foreach (topLevelBlocks(implode("\n", $dedented), "{$name}#{$section}") as [$key, $childLine, $grand]) {
            $id = "{$section}.{$key}";

            if (isset($componentOrigin[$id])) {
                fail("{$name}: komponen '{$id}' sudah didefinisikan di {$componentOrigin[$id]}.");
            }

            $components[$section][$key] = array_merge([$childLine], $grand);
            $componentOrigin[$id] = $name;
        }
    }
}

// ---- tulis ----
$out = "# BERKAS DIHASILKAN OTOMATIS oleh docs/api/build-openapi.php. Jangan edit langsung;\n"
    ."# ubah docs/api/base.yaml, docs/api/paths/*.yaml, atau docs/api/components/*.yaml.\n"
    .$base."\n";

$out .= $paths === [] ? "paths: {}\n" : "paths:\n";

foreach ($paths as $lines) {
    $out .= indent($lines, 2)."\n";
}

$out .= "components:\n";

foreach (COMPONENT_SECTIONS as $section) {
    if (! isset($components[$section])) {
        continue;
    }

    $out .= "  {$section}:\n";

    foreach ($components[$section] as $lines) {
        $out .= indent($lines, 4)."\n";
    }
}

$autoload = dirname($dir, 2).'/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

if (class_exists(Yaml::class)) {
    try {
        Yaml::parse($out);
    } catch (Throwable $e) {
        fail('hasil gabungan bukan YAML sah: '.$e->getMessage());
    }
}

$target = "{$dir}/openapi.yaml";

if ($check) {
    if (! is_file($target) || file_get_contents($target) !== $out) {
        fwrite(STDERR, "build-openapi: docs/api/openapi.yaml tidak sinkron. Jalankan: php docs/api/build-openapi.php\n");
        exit(1);
    }

    fwrite(STDOUT, 'openapi.yaml sinkron ('.count($paths)." path).\n");
    exit(0);
}

file_put_contents($target, $out);
fwrite(STDOUT, 'openapi.yaml ditulis ('.count($paths).' path, '.count($componentOrigin)." komponen).\n");

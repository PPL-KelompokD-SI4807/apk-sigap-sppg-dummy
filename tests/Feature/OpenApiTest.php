<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    public function test_openapi_yaml_sinkron_dengan_fragmen(): void
    {
        $process = new Process([PHP_BINARY, base_path('docs/api/build-openapi.php'), '--check']);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    public function test_setiap_rute_api_v1_terdokumentasi(): void
    {
        $yaml = (string) file_get_contents(base_path('docs/api/openapi.yaml'));
        preg_match_all('/^  (\/[^:\s]*):\s*$/m', $yaml, $m);
        $documented = array_map(fn ($p) => preg_replace('/\{[^}]+\}/', '{}', $p), $m[1]);

        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/_')) {
                continue;
            }

            $path = preg_replace('/\{[^}]+\}/', '{}', substr($uri, strlen('api/v1')));

            if (! in_array($path, $documented, true)) {
                $missing[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $missing, 'Tambahkan fragmen di docs/api/paths/*.yaml lalu jalankan php docs/api/build-openapi.php');
    }
}

<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use LogicException;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Daftar tool Agen AI, disaring per pengguna (FR-11, NFR-12).
 *
 * - Tool ditemukan otomatis dari config('ai.tools.paths') (default app/Services/Ai/Tools/**):
 *   setiap kelas konkret yang mengimplementasikan AiTool. Agen fitur cukup menambah berkas
 *   baru di app/Services/Ai/Tools/<Modul>/, tanpa mengubah berkas bersama.
 * - forUser(): hanya tool yang isAvailableFor($user) (Rbac Tabel 5 + Policy + unit relawan).
 * - resolveFor(): menolak nama tak dikenal dan tool di luar hak pengguna WALAU model memanggilnya.
 */
class ToolRegistry
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{2,63}$/';

    /** @var array<string, AiTool>|null */
    private ?array $tools = null;

    public function __construct(private readonly Container $container) {}

    public function register(AiTool $tool): void
    {
        $this->load();
        $this->add($tool);
    }

    /** @return array<string, AiTool> */
    public function all(): array
    {
        return $this->load();
    }

    /** @return array<string, AiTool> */
    public function forUser(User $user): array
    {
        if (! $user->canAuthenticate()) {
            return [];
        }

        return array_filter($this->load(), fn (AiTool $tool) => $tool->isAvailableFor($user));
    }

    /**
     * Skema tool untuk LlmClient (hanya tool milik peran pengguna).
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function schemasFor(User $user): array
    {
        return array_values(array_map(fn (AiTool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->parameters(),
        ], $this->forUser($user)));
    }

    /** Tool tulis untuk modul + aksi draf (dipakai GET /ai-drafts untuk validasi ulang). */
    public function writeToolFor(string $module, string $draftAction): ?WriteDraftTool
    {
        foreach ($this->load() as $tool) {
            if ($tool instanceof WriteDraftTool && $tool->module() === $module && $tool->draftAction()->value === $draftAction) {
                return $tool;
            }
        }

        return null;
    }

    /** @throws ToolException */
    public function resolveFor(User $user, string $name): AiTool
    {
        $tools = $this->load();

        if (preg_match(self::NAME_PATTERN, $name) !== 1 || ! isset($tools[$name])) {
            throw new ToolException(ToolException::UNKNOWN, 'Tool tidak dikenal.');
        }

        $tool = $tools[$name];

        if (! $user->canAuthenticate() || ! $tool->isAvailableFor($user)) {
            throw new ToolException(ToolException::FORBIDDEN, 'Tool tidak tersedia untuk hak akses Anda.');
        }

        return $tool;
    }

    /** @return array<string, AiTool> */
    private function load(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $this->tools = [];

        foreach ($this->discover() as $class) {
            $this->add($this->container->make($class));
        }

        foreach ((array) config('ai.tools.extra', []) as $class) {
            $this->add($this->container->make($class));
        }

        return $this->tools;
    }

    private function add(AiTool $tool): void
    {
        $name = $tool->name();

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new LogicException("Nama tool AI tidak valid: {$name}");
        }

        if (in_array($name, (array) config('ai.tools.disabled', []), true)) {
            return;
        }

        if (isset($this->tools[$name]) && $this->tools[$name]::class !== $tool::class) {
            throw new LogicException("Nama tool AI ganda: {$name}");
        }

        $this->tools[$name] = $tool;
    }

    /** @return list<class-string<AiTool>> */
    private function discover(): array
    {
        $paths = array_filter((array) config('ai.tools.paths', []), 'is_dir');

        if ($paths === []) {
            return [];
        }

        $appPath = rtrim(app_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in($paths)->sortByName() as $file) {
            $real = $file->getRealPath();

            if ($real === false || ! str_starts_with($real, $appPath)) {
                continue;
            }

            $class = 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], substr($real, strlen($appPath)));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isInstantiable() && $reflection->implementsInterface(AiTool::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}

<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use InvalidArgumentException;

/**
 * Penerapan matriks CRUD Tabel 5 (config/rbac.php).
 *
 * Huruf: C (create), R (read), U (update), D (delete lunak). Pengguna nonaktif/terhapus
 * tidak memiliki hak apa pun. Modul/huruf tak dikenal melempar exception (cegah salah ketik
 * yang diam-diam memberi/menolak akses).
 */
final class Rbac
{
    public const LETTERS = ['C', 'R', 'U', 'D'];

    /** @return list<string> */
    public static function modules(): array
    {
        return array_keys(config('rbac.modules', []));
    }

    public static function hasModule(string $module): bool
    {
        return array_key_exists($module, config('rbac.modules', []));
    }

    /**
     * Sel mentah matriks untuk peran (mis. "CRU", "R*", "").
     */
    public static function cell(Role|string $role, string $module): string
    {
        self::assertModule($module);
        $role = $role instanceof Role ? $role->value : $role;

        return (string) (config("rbac.modules.{$module}.{$role}") ?? '');
    }

    /**
     * Huruf efektif pengguna untuk modul setelah aturan unit relawan diterapkan.
     * Contoh: "CRU", "R", "". Tanda "*" dipertahankan (lihat isSelfOnly()).
     */
    public static function letters(?User $user, string $module): string
    {
        self::assertModule($module);

        if ($user === null || ! $user->canAuthenticate() || ! $user->role instanceof Role) {
            return '';
        }

        $cell = self::cell($user->role, $module);

        // Investor baca saja (R-06a): huruf tulis dibuang walau matriks salah isi.
        if ($user->role === Role::Investor) {
            $cell = (string) preg_replace('/[CUD]/', '', $cell);
        }

        $rule = config("rbac.unit_rules.{$user->role->value}.{$module}");

        if (is_array($rule)) {
            $unit = $user->unit?->value;

            if ($unit === null || ! in_array($unit, $rule['units'] ?? [], true)) {
                return '';
            }
        }

        return $cell;
    }

    public static function can(?User $user, string $module, string $letter): bool
    {
        self::assertLetter($letter);

        return str_contains(self::letters($user, $module), $letter);
    }

    /**
     * True bila hak $letter hanya untuk record milik sendiri (sel bertanda "*", mis. "R*").
     */
    public static function isSelfOnly(?User $user, string $module, string $letter): bool
    {
        self::assertLetter($letter);
        $letters = self::letters($user, $module);
        $pos = strpos($letters, $letter);

        return $pos !== false && ($letters[$pos + 1] ?? '') === '*';
    }

    /** True bila pengguna tidak punya hak C/U/D di modul mana pun (mis. investor, R-06a). */
    public static function readOnly(?User $user): bool
    {
        return preg_match('/[CUD]/', implode('', self::permissions($user))) !== 1;
    }

    /**
     * Hak memutuskan (config('rbac.keputusan')), terpisah dari huruf C/U/D. Pengguna nonaktif/
     * terhapus tidak pernah boleh. Tidak memengaruhi readOnly().
     */
    public static function canDecide(?User $user, string $module): bool
    {
        self::assertModule($module);

        if ($user === null || ! $user->canAuthenticate() || ! $user->role instanceof Role) {
            return false;
        }

        return in_array($user->role->value, (array) config("rbac.keputusan.{$module}", []), true);
    }

    /**
     * Modul tempat pengguna berhak memutuskan, mis. investor -> ['persetujuan-menu'].
     *
     * @return list<string>
     */
    public static function decisionModules(?User $user): array
    {
        return array_values(array_filter(
            array_keys((array) config('rbac.keputusan', [])),
            fn (string $module) => self::hasModule($module) && self::canDecide($user, $module),
        ));
    }

    /**
     * True bila pengguna boleh membaca AGREGAT modul (hitungan per kategori tanpa data pribadi):
     * punya hak R penuh (bukan R*) atau perannya tercantum di config('rbac.agregat.<modul>').
     */
    public static function canAggregate(?User $user, string $module): bool
    {
        self::assertModule($module);

        if (self::can($user, $module, 'R') && ! self::isSelfOnly($user, $module, 'R')) {
            return true;
        }

        if ($user === null || ! $user->canAuthenticate() || ! $user->role instanceof Role) {
            return false;
        }

        return in_array($user->role->value, (array) config("rbac.agregat.{$module}", []), true);
    }

    /**
     * Modul yang hanya boleh dibaca agregatnya oleh pengguna (tanpa hak R), mis. investor ->
     * ['penerima-manfaat']. Dipakai Mini App untuk menu agregat.
     *
     * @return list<string>
     */
    public static function aggregateOnlyModules(?User $user): array
    {
        return array_values(array_filter(
            array_keys((array) config('rbac.agregat', [])),
            fn (string $module) => self::hasModule($module) && self::letters($user, $module) === '' && self::canAggregate($user, $module),
        ));
    }

    /**
     * Sub-jenis data yang boleh dikelola pengguna untuk modul (mis. jadwal relawan dapur ->
     * 'produksi', relawan distribusi -> 'distribusi'). Null = tidak dibatasi sub-jenis.
     */
    public static function unitScope(?User $user, string $module): ?string
    {
        self::assertModule($module);

        if ($user === null || ! $user->role instanceof Role) {
            return null;
        }

        $map = config("rbac.unit_rules.{$user->role->value}.{$module}.jenis");

        if (! is_array($map)) {
            return null;
        }

        // Relawan tanpa unit tidak boleh mengelola sub-jenis apa pun.
        return $map[$user->unit?->value ?? ''] ?? '__none__';
    }

    /**
     * True bila pengguna boleh $letter pada sub-jenis $jenis modul (mis. jadwal 'distribusi').
     */
    public static function canForJenis(?User $user, string $module, string $letter, string $jenis): bool
    {
        if (! self::can($user, $module, $letter)) {
            return false;
        }

        $scope = self::unitScope($user, $module);

        return $scope === null || $scope === $jenis;
    }

    /**
     * Aturan kepemilikan untuk huruf (mis. relawan U -> 'own_same_day'), atau null.
     */
    public static function ownerRule(?User $user, string $letter): ?string
    {
        if ($user === null || ! $user->role instanceof Role) {
            return null;
        }

        return config("rbac.owner_rules.{$user->role->value}.{$letter}");
    }

    /**
     * Slug modul yang punya minimal satu hak untuk pengguna.
     *
     * @return list<string>
     */
    public static function allowedModules(?User $user): array
    {
        return array_keys(self::permissions($user));
    }

    /**
     * Peta modul -> huruf efektif (tanpa modul yang kosong), mis. ['sppg' => 'RU'].
     *
     * @return array<string, string>
     */
    public static function permissions(?User $user): array
    {
        $result = [];

        foreach (self::modules() as $module) {
            $letters = self::letters($user, $module);

            if ($letters !== '') {
                $result[$module] = $letters;
            }
        }

        return $result;
    }

    /**
     * Ability token Sanctum: "role:<role>" + "<modul>:<huruf>" (informatif; otorisasi
     * tetap dihitung ulang dari peran terkini di basis data).
     *
     * @return list<string>
     */
    public static function abilities(User $user): array
    {
        $abilities = ['role:'.$user->role->value];

        foreach (self::permissions($user) as $module => $letters) {
            foreach (str_split(str_replace('*', '', $letters)) as $letter) {
                $abilities[] = "{$module}:{$letter}";
            }
        }

        foreach (self::decisionModules($user) as $module) {
            $abilities[] = "{$module}:putuskan";
        }

        return $abilities;
    }

    private static function assertModule(string $module): void
    {
        if (! self::hasModule($module)) {
            throw new InvalidArgumentException("Modul RBAC tidak dikenal: {$module}");
        }
    }

    private static function assertLetter(string $letter): void
    {
        if (! in_array($letter, self::LETTERS, true)) {
            throw new InvalidArgumentException("Huruf RBAC tidak dikenal: {$letter}");
        }
    }
}

<?php

namespace Tests\Unit\Rbac;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Support\Rbac;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RbacMatrixTest extends TestCase
{
    /**
     * Salinan literal Tabel 5 proposal (urutan kolom: admin, kepala_sppg, ahli_gizi, akuntan,
     * staf_gudang, relawan) + kolom investor (R-06a, keputusan pengguna 2026-10-26: baca saja).
     * "-" = tanpa akses. Menjaga config/rbac.php tidak bergeser diam-diam.
     */
    private const TABEL_5 = [
        'pengguna' => ['CRUD', 'R', 'R*', 'R*', 'R*', 'R*', 'R*'],
        'notifikasi-perjalanan' => ['R', 'CR', '-', '-', '-', 'CR', '-'],
        'sppg' => ['CRUD', 'RU', 'R', 'R', 'R', 'R', 'R'],
        'titik-layanan' => ['CRUD', 'CRUD', 'R', 'R', 'R', 'R', '-'],
        'penerima-manfaat' => ['CRUD', 'CRU', 'R', '-', '-', '-', '-'],
        'bahan-baku' => ['CRUD', 'R', 'R', 'R', 'CRUD', '-', 'R'],
        'pemasok' => ['CRUD', 'R', 'R', 'R', 'CRU', '-', '-'],
        'menu-gizi' => ['R', 'R', 'CRUD', '-', 'R', 'R', '-'],
        'jadwal' => ['R', 'CRUD', 'CRU', '-', 'R', 'CRU', '-'],
        'log-produksi' => ['R', 'RUD', 'R', 'R', 'R', 'CRU', '-'],
        'mutu' => ['R', 'R', 'CRUD', '-', '-', 'R', 'R'],
        'armada' => ['CRUD', 'CRUD', '-', 'R', '-', 'R', '-'],
        'bukti-distribusi' => ['R', 'CRUD', 'R', 'R', '-', 'CRU', '-'],
        'keuangan' => ['R', 'R', '-', 'CRUD', '-', '-', 'R'],
        'keluhan' => ['CRUD', 'RU', 'RU', '-', '-', 'CR', 'R'],
        'rekap-harian' => ['R', 'CRU', 'R', 'R', '-', '-', 'R'],
    ];

    private const ROLES = ['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'relawan', 'investor'];

    /** Modul relawan yang dibatasi unit: modul => unit yang boleh. */
    private const RELAWAN_UNIT = [
        'notifikasi-perjalanan' => ['distribusi', '-'],
        'bukti-distribusi' => ['distribusi', '-'],
        'log-produksi' => ['dapur', '-'],
        'jadwal' => ['dapur', 'distribusi', '-'],
    ];

    private function user(Role $role, ?RelawanUnit $unit = null, bool $active = true): User
    {
        return User::factory()->make([
            'id' => 1, 'role' => $role, 'unit' => $unit, 'sppg_id' => 1, 'is_active' => $active,
        ]);
    }

    public function test_config_identik_dengan_tabel_5_dan_urutan_fitur(): void
    {
        $this->assertSame(array_keys(self::TABEL_5), Rbac::modules());

        foreach (self::TABEL_5 as $module => $cells) {
            foreach (self::ROLES as $i => $role) {
                $expected = $cells[$i] === '-' ? '' : $cells[$i];
                $this->assertSame($expected, Rbac::cell($role, $module), "{$module}/{$role}");
            }
        }
    }

    public function test_setiap_peran_enum_ada_di_matriks(): void
    {
        $this->assertSame(self::ROLES, Role::values());
    }

    /**
     * Data-driven dari config: setiap modul x peran (x unit relawan) x huruf.
     *
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function kombinasi(): iterable
    {
        foreach (array_keys(self::TABEL_5) as $module) {
            foreach (self::ROLES as $role) {
                if ($role === 'relawan') {
                    foreach (['dapur', 'distribusi'] as $unit) {
                        yield "{$module}/{$role}:{$unit}" => [$module, $role, $unit];
                    }
                } else {
                    yield "{$module}/{$role}" => [$module, $role, null];
                }
            }
        }
    }

    #[DataProvider('kombinasi')]
    public function test_rbac_can_sesuai_matriks(string $module, string $role, ?string $unit): void
    {
        $user = $this->user(Role::from($role), $unit ? RelawanUnit::from($unit) : null);
        $cell = config("rbac.modules.{$module}.{$role}");

        $unitAllowed = $role !== 'relawan'
            || ! isset(self::RELAWAN_UNIT[$module])
            || in_array($unit, self::RELAWAN_UNIT[$module], true);

        foreach (Rbac::LETTERS as $letter) {
            $expected = $unitAllowed && str_contains($cell, $letter);
            $this->assertSame($expected, Rbac::can($user, $module, $letter), "{$module}/{$role}/{$unit}/{$letter}");
        }

        $this->assertSame($unitAllowed && $cell !== '', in_array($module, Rbac::allowedModules($user), true));
    }

    public function test_relawan_tanpa_unit_tidak_mendapat_modul_berunit(): void
    {
        $user = $this->user(Role::Relawan);

        foreach (array_keys(self::RELAWAN_UNIT) as $module) {
            $this->assertFalse(Rbac::can($user, $module, 'R'), $module);
        }

        $this->assertTrue(Rbac::can($user, 'keluhan', 'C'));
    }

    public function test_r_bintang_hanya_profil_sendiri(): void
    {
        $this->assertTrue(Rbac::isSelfOnly($this->user(Role::Akuntan), 'pengguna', 'R'));
        $this->assertFalse(Rbac::isSelfOnly($this->user(Role::KepalaSppg), 'pengguna', 'R'));
        $this->assertFalse(Rbac::isSelfOnly($this->user(Role::Admin), 'pengguna', 'R'));
        $this->assertFalse(Rbac::isSelfOnly($this->user(Role::Akuntan), 'keuangan', 'R'));
    }

    public function test_unit_scope_jadwal_relawan(): void
    {
        $dapur = $this->user(Role::Relawan, RelawanUnit::Dapur);
        $distribusi = $this->user(Role::Relawan, RelawanUnit::Distribusi);

        $this->assertSame('produksi', Rbac::unitScope($dapur, 'jadwal'));
        $this->assertSame('distribusi', Rbac::unitScope($distribusi, 'jadwal'));
        $this->assertNull(Rbac::unitScope($this->user(Role::KepalaSppg), 'jadwal'));
        $this->assertNull(Rbac::unitScope($dapur, 'keluhan'));

        $this->assertTrue(Rbac::canForJenis($dapur, 'jadwal', 'C', 'produksi'));
        $this->assertFalse(Rbac::canForJenis($dapur, 'jadwal', 'C', 'distribusi'));
        $this->assertTrue(Rbac::canForJenis($distribusi, 'jadwal', 'U', 'distribusi'));
        $this->assertFalse(Rbac::canForJenis($distribusi, 'jadwal', 'U', 'produksi'));
        $this->assertFalse(Rbac::canForJenis($dapur, 'jadwal', 'D', 'produksi'));
        $this->assertTrue(Rbac::canForJenis($this->user(Role::AhliGizi), 'jadwal', 'C', 'distribusi'));
        $this->assertFalse(Rbac::canForJenis($this->user(Role::Relawan), 'jadwal', 'R', 'produksi'));
    }

    public function test_pengguna_nonaktif_atau_null_tidak_punya_hak(): void
    {
        $this->assertFalse(Rbac::can($this->user(Role::Admin, active: false), 'pengguna', 'R'));
        $this->assertSame([], Rbac::allowedModules($this->user(Role::Admin, active: false)));
        $this->assertFalse(Rbac::can(null, 'sppg', 'R'));
    }

    public function test_modul_atau_huruf_tidak_dikenal_melempar_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rbac::can($this->user(Role::Admin), 'bahan_baku', 'R');
    }

    public function test_huruf_tidak_dikenal_melempar_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rbac::can($this->user(Role::Admin), 'sppg', 'X');
    }

    public function test_abilities_token(): void
    {
        $abilities = Rbac::abilities($this->user(Role::Akuntan));

        $this->assertContains('role:akuntan', $abilities);
        $this->assertContains('keuangan:D', $abilities);
        $this->assertContains('pengguna:R', $abilities);
        $this->assertNotContains('bahan-baku:C', $abilities);
    }

    public function test_investor_baca_saja_dan_agregat_penerima(): void
    {
        $investor = $this->user(Role::Investor);
        $abilities = Rbac::abilities($investor);

        $this->assertContains('role:investor', $abilities);
        $this->assertContains('keuangan:R', $abilities);
        $this->assertSame([], array_values(array_filter($abilities, fn ($a) => preg_match('/:[CUD]$/', $a) === 1)));
        $this->assertFalse(Rbac::can($investor, 'penerima-manfaat', 'R'));
        $this->assertTrue(Rbac::canAggregate($investor, 'penerima-manfaat'));
        $this->assertSame(['penerima-manfaat'], Rbac::aggregateOnlyModules($investor));
        $this->assertTrue(Rbac::canAggregate($this->user(Role::AhliGizi), 'penerima-manfaat'));
        $this->assertFalse(Rbac::canAggregate($this->user(Role::Akuntan), 'penerima-manfaat'));
        $this->assertSame([], Rbac::aggregateOnlyModules($this->user(Role::AhliGizi)));

        // Huruf tulis di matriks untuk investor selalu dibuang (pertahanan berlapis).
        config(['rbac.modules.keluhan.investor' => 'CRUD']);
        $this->assertSame('R', Rbac::letters($investor, 'keluhan'));
    }
}

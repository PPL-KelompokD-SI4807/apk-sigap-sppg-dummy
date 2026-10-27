<?php

namespace Tests\Feature\Ai;

use App\Enums\JenisInputAi;
use App\Enums\Role;
use App\Models\BotAksi;
use App\Models\PengajuanMenu;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Catalog\CatalogDefinitions;
use App\Services\Ai\Catalog\SppgBound;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\Persetujuan\DaftarPengajuanMenuTool;
use App\Services\Ai\Tools\TitikLayanan\CariTitikLayananTool;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Support\SppgAktif;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Master\MasterApiTestCase;

/**
 * Penimpa baca-saja admin (R02-A1) di kode bersama SppgBound/SppgAktif. Regresi: di luar penimpa perilaku jalur
 * AI/REST TIDAK berubah (admin lintas SPPG, non-admin = SPPG sendiri); di dalam penimpa admin hanya DIPERSEMPIT
 * ke satu SPPG, tanpa hak tulis (transaksi di-rollback), dan penimpa tidak dapat diaktifkan untuk non-admin.
 */
class SppgBoundBacaAdminTest extends MasterApiTestCase
{
    private function sppgAktif(): SppgAktif
    {
        return app(SppgAktif::class);
    }

    private function jalankan(User $user, AiTool $tool): array
    {
        $this->forgetActor();
        $hasil = ActingUser::run($user, fn () => $tool->execute($user, ['batas' => 20], new ToolContext(null, JenisInputAi::Teks)));
        $this->assertSame('ok', $hasil->status);

        return array_column($hasil->data['hasil'], 'id');
    }

    private function buatRecord(string $model, int $sppgId): Model
    {
        $this->forgetActor();

        return $model === Sppg::class ? Sppg::query()->findOrFail($sppgId) : $model::factory()->create(['sppg_id' => $sppgId]);
    }

    /** @return array<string, array{string}> */
    public static function bacaProvider(): array
    {
        $out = [];

        foreach (CatalogDefinitions::all() as $spec) {
            if (in_array('R', $spec->actions, true) && ! in_array($spec->toolName('R'), $spec->skip, true)) {
                $out[$spec->toolName('R')] = [$spec->key];
            }
        }

        return $out;
    }

    #[DataProvider('bacaProvider')]
    public function test_tool_baca_admin_tetap_lintas_sppg_di_luar_penimpa_dan_dipersempit_di_dalamnya(string $key): void
    {
        $spec = collect(CatalogDefinitions::all())->firstWhere('key', $key);
        $tool = app(ToolRegistry::class)->all()[$spec->toolName('R')];

        if (! $tool->isAvailableFor($this->admin)) {
            $this->markTestSkipped("{$tool->name()} tidak tersedia untuk admin");
        }

        $idA = $spec->model === User::class ? $this->kepalaA->id : $this->buatRecord($spec->model, $this->sppgA)->getKey();
        $idB = $spec->model === User::class ? $this->kepalaB->id : $this->buatRecord($spec->model, $this->sppgB)->getKey();

        $sebelum = $this->jalankan($this->admin, $tool);
        $this->assertContains($idA, $sebelum);
        $this->assertContains($idB, $sebelum);

        $dalam = $this->sppgAktif()->untukBacaAdmin($this->admin, $this->sppgA, fn () => $this->jalankan($this->admin, $tool));
        $this->assertContains($idA, $dalam);
        $this->assertNotContains($idB, $dalam, 'Penimpa admin mempersempit ke SPPG terpilih');

        $sesudah = $this->jalankan($this->admin, $tool);
        $this->assertEqualsCanonicalizing($sebelum, $sesudah, 'Setelah penimpa: perilaku kembali seperti semula');

        // Non-admin tidak berubah: Kepala SPPG A tetap hanya SPPG A.
        if ($tool->isAvailableFor($this->kepalaA)) {
            $this->assertNotContains($idB, $this->jalankan($this->kepalaA, $tool));
        }
    }

    public function test_penimpa_tidak_dapat_diaktifkan_untuk_non_admin_atau_sppg_tidak_sah(): void
    {
        foreach ([$this->kepalaA, $this->akuntanA, $this->relawanA] as $user) {
            try {
                $this->sppgAktif()->untukBacaAdmin($user, $this->sppgA, fn () => $this->fail('callback tidak boleh jalan'));
                $this->fail('non-admin harus ditolak');
            } catch (AuthorizationException) {
                $this->assertNull($this->sppgAktif()->sppgBacaAdmin($user));
            }
        }

        Sppg::query()->whereKey($this->sppgB)->delete();

        foreach ([0, -1, 999999, $this->sppgB] as $sppg) {
            try {
                $this->sppgAktif()->untukBacaAdmin($this->admin, $sppg, fn () => $this->fail('callback tidak boleh jalan'));
                $this->fail("SPPG {$sppg} harus ditolak");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->admin->forceFill(['is_active' => false])->save();
        $this->expectException(AuthorizationException::class);
        $this->sppgAktif()->untukBacaAdmin($this->admin->fresh(), $this->sppgA, fn () => null);
    }

    public function test_penimpa_hanya_untuk_admin_itu_dibersihkan_saat_galat_dan_bersarang(): void
    {
        $adminLain = User::factory()->admin()->create();
        TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        TitikLayanan::factory()->count(2)->create(['sppg_id' => $this->sppgB]);
        $hitung = fn (User $u) => SppgBound::apply(TitikLayanan::query()->withoutGlobalScopes(), $u)->count();

        $this->sppgAktif()->untukBacaAdmin($this->admin, $this->sppgA, function () use ($hitung, $adminLain): void {
            $this->assertSame(1, $hitung($this->admin));
            $this->assertSame(3, $hitung($adminLain), 'Admin lain tidak terpengaruh');
            $this->assertNull($this->sppgAktif()->id($this->admin), 'SppgAktif::id admin tetap null (SppgScope/Policy tidak berubah)');

            $this->sppgAktif()->untukBacaAdmin($this->admin, $this->sppgB, fn () => $this->assertSame(2, $hitung($this->admin)));
            $this->assertSame(1, $hitung($this->admin), 'Bersarang dipulihkan');
        });

        try {
            $this->sppgAktif()->untukBacaAdmin($this->admin, $this->sppgA, fn () => throw new RuntimeException('galat'));
        } catch (RuntimeException) {
        }

        $this->assertNull($this->sppgAktif()->sppgBacaAdmin($this->admin));
        $this->assertSame(3, $hitung($this->admin), 'Dibersihkan di finally');
    }

    public function test_penimpa_baca_saja_tulisan_dirollback_dan_tool_tulis_tidak_ditawarkan(): void
    {
        $sebelum = TitikLayanan::query()->withoutGlobalScopes()->count();

        $this->sppgAktif()->untukBacaAdmin($this->admin, $this->sppgA, function (): void {
            TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
            BotAksi::query()->insert([
                'kode' => 'kodeujifikt', 'user_id' => $this->admin->id, 'telegram_id' => 1, 'chat_id' => 1, 'jenis' => 'tinjau',
                'status' => 'aktif', 'expires_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $ditawarkan = ActingUser::run($this->admin, fn () => app(ToolRegistry::class)->forUser($this->admin));
            $this->assertNotEmpty($ditawarkan, 'Admin tetap punya tool baca');

            foreach ($ditawarkan as $tool) {
                $this->assertFalse($tool->isWrite(), "Admin di penimpa tidak ditawari {$tool->name()}");
            }

            // Sama persis dengan keadaan di luar penimpa: penimpa tidak menambah/mengurangi tool.
            $this->assertEqualsCanonicalizing(
                array_keys($ditawarkan),
                array_keys(ActingUser::run($this->admin, fn () => app(ToolRegistry::class)->forUser($this->admin))),
            );
        });

        $luar = ActingUser::run($this->admin, fn () => app(ToolRegistry::class)->forUser($this->admin));
        $this->assertNotEmpty($luar);
        $this->assertSame([], array_values(array_filter($luar, fn (AiTool $t) => $t->isWrite())), 'Di luar penimpa admin juga tanpa tool tulis (lintas SPPG)');

        $this->assertSame($sebelum, TitikLayanan::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_rest_admin_tetap_lintas_sppg(): void
    {
        $a = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $b = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB]);

        $ids = collect($this->as($this->admin)->getJson('/api/v1/titik-layanan?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);

        $ids = collect($this->as($this->kepalaA)->getJson('/api/v1/titik-layanan?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
    }

    public function test_peran_lain_tidak_terpengaruh_walau_penimpa_admin_aktif(): void
    {
        TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        TitikLayanan::factory()->count(2)->create(['sppg_id' => $this->sppgB]);
        $kepalaB = $this->kepalaB;

        $this->sppgAktif()->untukBacaAdmin($this->admin, $this->sppgA, function () use ($kepalaB): void {
            $this->assertSame(2, SppgBound::apply(TitikLayanan::query()->withoutGlobalScopes(), $kepalaB)->count());
            $investor = User::factory()->role(Role::Investor)->create(['sppg_id' => null]);
            $this->assertSame(0, SppgBound::apply(TitikLayanan::query()->withoutGlobalScopes(), $investor)->count(), 'Investor tanpa SPPG tetap fail closed');
        });
    }

    // ------------------------------------------------------------------ sumber di luar katalog (di-skip bacaProvider)

    /** @return array<string, array{class-string<AiTool>, class-string<Model>}> */
    public static function toolLuarKatalog(): array
    {
        return [
            'cari_titik_layanan' => [CariTitikLayananTool::class, TitikLayanan::class],
            'daftar_pengajuan_menu' => [DaftarPengajuanMenuTool::class, PengajuanMenu::class],
        ];
    }

    /** @param class-string<AiTool> $kelasTool @param class-string<Model> $model */
    #[DataProvider('toolLuarKatalog')]
    public function test_tool_luar_katalog_admin_dipersempit_di_penimpa_dan_lintas_sppg_di_luar(string $kelasTool, string $model): void
    {
        $tool = new $kelasTool;
        $idA = $this->buatRecord($model, $this->sppgA)->getKey();
        $idB = $this->buatRecord($model, $this->sppgB)->getKey();

        $sebelum = $this->jalankan($this->admin, $tool);
        $this->assertContains($idA, $sebelum);
        $this->assertContains($idB, $sebelum, 'Di luar penimpa admin tetap lintas SPPG');

        $dalam = $this->sppgAktif()->untukBacaAdmin($this->admin, $this->sppgA, fn () => $this->jalankan($this->admin, $tool));
        $this->assertContains($idA, $dalam);
        $this->assertNotContains($idB, $dalam);

        $this->assertEqualsCanonicalizing($sebelum, $this->jalankan($this->admin, $tool));

        // Peran satu-SPPG tetap hanya SPPG sendiri (tool tersedia untuk kepala A pada kedua sumber).
        $this->assertTrue($tool->isAvailableFor($this->kepalaA));
        $milikKepala = $this->jalankan($this->kepalaA, $tool);
        $this->assertContains($idA, $milikKepala);
        $this->assertNotContains($idB, $milikKepala);
    }

    /** @param class-string<AiTool> $kelasTool @param class-string<Model> $model */
    #[DataProvider('toolLuarKatalog')]
    public function test_tool_luar_katalog_investor_banyak_sppg_hanya_sppg_aktif(string $kelasTool, string $model): void
    {
        $tool = new $kelasTool;
        $idA = $this->buatRecord($model, $this->sppgA)->getKey();
        $idB = $this->buatRecord($model, $this->sppgB)->getKey();
        $this->forgetActor();
        $investor = User::factory()->role(Role::Investor)->create(['sppg_id' => null]);

        foreach ([$this->sppgA, $this->sppgB] as $sppg) {
            DB::table('investor_sppg')->insert(['user_id' => $investor->id, 'sppg_id' => $sppg, 'created_at' => now(), 'updated_at' => now()]);
        }

        if (! $tool->isAvailableFor($investor)) {
            // Titik layanan tidak terbuka bagi investor (rbac): cukup pastikan memang tertutup.
            $this->assertSame('cari_titik_layanan', $tool->name());

            return;
        }

        $this->assertSame([], $this->jalankan($investor, $tool), 'Banyak SPPG tanpa SPPG aktif: fail closed');
        $dalamA = $this->sppgAktif()->untuk($investor, $this->sppgA, fn () => $this->jalankan($investor, $tool));
        $dalamB = $this->sppgAktif()->untuk($investor, $this->sppgB, fn () => $this->jalankan($investor, $tool));
        $this->assertContains($idA, $dalamA);
        $this->assertNotContains($idB, $dalamA);
        $this->assertContains($idB, $dalamB);
        $this->assertNotContains($idA, $dalamB);
    }
}

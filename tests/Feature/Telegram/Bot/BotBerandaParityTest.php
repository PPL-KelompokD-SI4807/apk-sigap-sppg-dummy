<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\Bot\BotNavHasil;
use App\Services\Telegram\Bot\BotNavigasi;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Support\PetaHak;
use Illuminate\Support\Facades\Gate;

/**
 * Parity beranda Bot vs gerbang layar (peta hak server): tombol beranda tampil JIKA DAN HANYA JIKA layar akan
 * mengizinkan (BotLayarRegistry::layarBolehDibuka = hakRbac + LayarAksi::tampilUntuk + izinkan). Layar produksi
 * sungguhan, sembilan profil. Tidak ada tombol baru yang ditambahkan.
 */
class BotBerandaParityTest extends BotTestCase
{
    /** @return array<string, User> */
    private function profil(): array
    {
        $tanpaUnit = $this->userOf(Role::Relawan, $this->sppgA);
        $tanpaUnit->forceFill(['unit' => null])->saveQuietly();

        $profil = [
            'admin' => $this->admin,
            'kepala_sppg' => $this->kepalaA,
            'ahli_gizi' => $this->ahliGiziA,
            'akuntan' => $this->akuntanA,
            'staf_gudang' => $this->stafGudangA,
            'relawan.dapur' => $this->relawanA,
            'relawan.distribusi' => $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi),
            'relawan.tanpa_unit' => $tanpaUnit->fresh(),
            'investor' => $this->investorAB,
        ];

        $this->sesiPenuh(...array_values($profil));
        $this->forgetActor();

        return $profil;
    }

    private function buka(User $user, string $layar, ?int $sppg): BotNavHasil
    {
        $this->forgetActor();

        return app(BotNavigasi::class)->buka($user, $layar, $sppg, new KonteksLayar((int) $user->telegram_id, (int) $user->telegram_id));
    }

    /** SPPG yang dipilih saat membuka beranda (admin/investor memilih A; peran lain SPPG sendiri). */
    private function sppgBeranda(User $user): ?int
    {
        return in_array($user->role, [Role::Admin, Role::Investor], true) ? $this->sppgA : ($user->sppg_id === null ? null : (int) $user->sppg_id);
    }

    public function test_setiap_tombol_n1_beranda_benar_benar_dibuka_layarnya_tanpa_ditolak(): void
    {
        $dibuka = 0;

        foreach ($this->profil() as $nama => $user) {
            $sppg = $this->sppgBeranda($user);
            $beranda = $this->buka($user, BotLayarRegistry::BERANDA, $sppg);
            $this->assertTrue($beranda->berhasil(), "{$nama}: beranda");

            foreach (array_merge(...($beranda->layar->markup['inline_keyboard'] ?: [[]])) as $tombol) {
                $tujuan = isset($tombol['callback_data']) ? BotNavCodec::urai($tombol['callback_data'], (int) $user->telegram_id) : null;

                if ($tujuan === null) {
                    continue; // tombol keputusan `b1` atau web_app
                }

                $hasil = $this->buka($user, $tujuan->layar, $tujuan->sppgId);
                $this->assertSame(BotNavHasil::OK, $hasil->status, "{$nama}: tombol '{$tombol['text']}' ({$tujuan->layar}) tampil tetapi layar menolak ({$hasil->status})");
                $dibuka++;
            }
        }

        $this->assertGreaterThan(60, $dibuka, 'sembilan profil harus menguji puluhan tombol');
    }

    public function test_tombol_menu_tampil_iff_gerbang_layar_mengizinkan_dan_yang_tersembunyi_memang_ditolak(): void
    {
        $registry = app(BotLayarRegistry::class);

        foreach ($this->profil() as $nama => $user) {
            $sppg = $this->sppgBeranda($user);
            $tampil = ActingUser::run($user, fn () => array_column($registry->tombolBeranda($user, $sppg !== null, $sppg), 'layar'));

            foreach (BotLayarRegistry::MENU[BotLayarRegistry::profil($user)] as [$id, $label, , $hak]) {
                if ($hak === BotLayarRegistry::HAK_PUTUSKAN) {
                    $this->assertSame(BotLayarRegistry::bisaMemutuskan($user), in_array($id, $tampil, true), "{$nama}: {$label}");

                    continue;
                }

                $layar = $registry->cari($id);
                $this->assertNotNull($layar, "{$nama}/{$id}");
                $boleh = ActingUser::run($user, fn () => BotLayarRegistry::layarBolehDibuka($user, $layar, $sppg));

                $this->assertSame($boleh, in_array($id, $tampil, true), "{$nama}: tombol '{$label}' ({$id}) harus tampil iff gerbang layar mengizinkan");

                if (! in_array($id, $tampil, true)) {
                    $this->assertNotSame(BotNavHasil::OK, $this->buka($user, $id, $sppg)->status, "{$nama}: '{$label}' ({$id}) disembunyikan sehingga klik manual harus ditolak");
                }
            }
        }
    }

    public function test_gerbang_tunggal_sama_dengan_hasil_buka_untuk_semua_layar_terdaftar(): void
    {
        $registry = app(BotLayarRegistry::class);

        foreach ($this->profil() as $nama => $user) {
            $sppg = $this->sppgBeranda($user);

            foreach ($registry->semua() as $id => $layar) {
                $boleh = ActingUser::run($user, fn () => BotLayarRegistry::layarBolehDibuka($user, $layar, $sppg));
                $status = $this->buka($user, $id, $sppg)->status;

                if ($boleh) {
                    $this->assertNotSame(BotNavHasil::TIDAK_BERHAK, $status, "{$nama}/{$id}: gerbang mengizinkan tetapi navigasi menolak");
                } else {
                    $this->assertSame(BotNavHasil::TIDAK_BERHAK, $status, "{$nama}/{$id}: gerbang menolak, navigasi harus menolak (bukan {$status})");
                }
            }
        }
    }

    public function test_tombol_dashboard_konsisten_dengan_dashboard_policy(): void
    {
        $registry = app(BotLayarRegistry::class);

        foreach ($this->profil() as $nama => $user) {
            $sppg = $this->sppgBeranda($user);
            $adaTombol = in_array('db', ActingUser::run($user, fn () => array_column($registry->tombolBeranda($user, $sppg !== null, $sppg), 'layar')), true);

            if ($adaTombol) {
                $this->assertTrue(Gate::forUser($user)->allows('dashboard.lihat'), "{$nama}: tombol db tampil padahal DashboardPolicy::lihat menolak");
                $this->assertTrue($this->buka($user, 'db', $sppg)->berhasil(), $nama);
            }

            // Sama dengan aksi.dashboard.tersedia pada peta hak untuk profil yang memiliki tombol db di MENU.
            $adaDiMenu = array_filter(BotLayarRegistry::MENU[BotLayarRegistry::profil($user)], fn (array $e) => $e[0] === 'db') !== [];

            if ($adaDiMenu) {
                $this->assertSame(PetaHak::aksi($user)['dashboard']['tersedia'], $adaTombol, "{$nama}: tombol db vs aksi.dashboard.tersedia");
            }
        }
    }

    public function test_relawan_tanpa_unit_tidak_mendapat_tombol_aksi_atau_dashboard(): void
    {
        $user = $this->profil()['relawan.tanpa_unit'];
        $sppg = $this->sppgBeranda($user);
        $tampil = ActingUser::run($user, fn () => array_column(app(BotLayarRegistry::class)->tombolBeranda($user, true, $sppg), 'layar'));

        $this->assertNotContains('db', $tampil);
        $this->assertNotContains('mp', $tampil);
    }
}

<?php

namespace App\Services\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Services\Telegram\Bot\Layar\DefinisiLayar;
use App\Services\Telegram\Bot\Layar\LayarAksi;
use App\Services\Telegram\Bot\Layar\LayarBaca;
use App\Services\Telegram\Bot\Layar\LayarBantuan;
use App\Services\Telegram\Bot\Layar\LayarBeranda;
use App\Services\Telegram\Bot\Layar\LayarPilihSppg;
use App\Services\Telegram\Bot\Layar\LayarTanyaAi;
use App\Support\Rbac;
use App\Support\SppgAktif;
use LogicException;

/**
 * Registri layar baca Bot (R-02 v2). Kode, bukan data: daftar kelas di LAYAR (diisi bertahap pada R02-A2/B1)
 * dan matriks tombol beranda per profil di MENU (bagian 3.4 rencana).
 *
 * Validasi saat dibangun (gagal tertutup): id unik + pola sah, modul ada di config('rbac.modules'),
 * hak dikenal, layar tanpa modul hanya untuk id di TANPA_MODUL, dan tiap entri MENU yang layarnya terdaftar
 * harus memakai modul/hak yang sama dengan layar itu.
 *
 * Beranda hanya menampilkan tombol bila layar tujuannya TERDAFTAR dan hak RBAC (config/rbac.php) pengguna saat ini
 * mengizinkan; layar yang belum dibangun tidak pernah muncul. Hak tetap dicek ulang BotNavigasi pada setiap klik.
 */
class BotLayarRegistry
{
    public const BERANDA = 'h';

    public const PILIH_SPPG = 'ps';

    public const TANYA_AI = 'ai';

    public const BANTUAN = 'bt';

    /** Dashboard per peran (R-07): ringkasan 5-8 angka kunci dari DashboardService. */
    public const DASHBOARD = 'db';

    /** Id layar yang boleh berhak HAK_SESI (tanpa modul RBAC): beranda, pilih SPPG, profil, Tanya Agen AI, bantuan, dashboard. */
    public const TANPA_MODUL = ['h', 'ps', 'pf', 'ai', 'bt', 'db'];

    /** Penanda entri MENU khusus (bukan huruf RBAC). */
    public const HAK_PILIH = 'pilih';

    public const HAK_PUTUSKAN = 'putuskan';

    /** Penanda entri MENU Dashboard (modul null): layar `db` HAK_SESI; isi disaring per widget oleh DashboardService. */
    public const HAK_DASHBOARD = 'dashboard';

    /** Id semu tombol keputusan investor (callback `b1`, bukan layar `n1`). */
    public const KEPUTUSAN = 'kp';

    /** Jumlah halaman maksimum yang dapat dialamatkan id navigasi (akhiran 2-9). Layar tool baca dibatasi BotLayarService::HALAMAN_MAKS. */
    public const HALAMAN_MAKS = 9;

    /**
     * Perintah pintasan -> id layar (bagian 3.9). Hak dicek saat dijalankan, bukan dari daftar ini.
     *
     * @var array<string, string>
     */
    public const PERINTAH = [
        'jadwal' => 'jd',
        'stok' => 'st',
        'keluhan' => 'kl',
        'rekap' => 'rk',
        'keuangan' => 'ku',
        'anggaran' => 'ag',
        'distribusi' => 'ds',
        'mutu' => 'mt',
    ];

    /**
     * Daftar perintah global untuk setMyCommands (urutan tampil). Deskripsi tanpa data dinamis.
     *
     * @var array<string, string>
     */
    public const DESKRIPSI_PERINTAH = [
        'menu' => 'Menu utama sesuai peran',
        'jadwal' => 'Jadwal produksi dan distribusi hari ini',
        'stok' => 'Stok bahan baku',
        'keluhan' => 'Keluhan terbuka',
        'rekap' => 'Rekap harian operasional',
        'keuangan' => 'Pencairan dan pengeluaran',
        'anggaran' => 'Serapan anggaran',
        'distribusi' => 'Distribusi hari ini',
        'mutu' => 'Pemeriksaan mutu pangan',
        'start' => 'Mulai dan buka Mini App',
        'bantuan' => 'Cara memakai bot',
    ];

    /** @var list<class-string<LayarBaca>> */
    public const LAYAR = [
        LayarBeranda::class,
        LayarPilihSppg::class,
        LayarTanyaAi::class,
        LayarBantuan::class,
    ];

    /**
     * Matriks tombol beranda per profil (urutan tampil). Tiap entri: [id layar, label, modul RBAC, hak].
     * hak = HAK_BACA 'R' | 'R*' | 'agregat' (lihat LayarBaca) atau HAK_PILIH / HAK_PUTUSKAN / HAK_DASHBOARD (khusus, modul null).
     * Tombol hanya tampil bila layar `id` terdaftar dan hak RBAC peran saat ini mengizinkan (HAK_PILIH: admin atau
     * investor >1 SPPG; HAK_PUTUSKAN: Rbac::canDecide; HAK_DASHBOARD: layar `db` terdaftar dan pengguna lolos izinkan(), tanpa syarat SPPG terpilih
     * untuk admin/investor karena tampilan gabungan sah). Layar aksi (LayarAksi, mis. `mp` Mulai Perjalanan dan petunjuk alur
     * draf `dpk`/`dpc`/`dms`/`dkl`, C2) juga mensyaratkan tampilUntuk(); tombol aksi tulis lain (kirim ulang rekap, proses keluhan) ada di layar Rekap/Keluhan (PR-3).
     *
     * @var array<string, list<array{0: string, 1: string, 2: ?string, 3: string}>>
     */
    public const MENU = [
        'admin' => [
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['ps', 'Pilih SPPG', null, self::HAK_PILIH],
            ['rk', 'Rekap', 'rekap-harian', 'R'],
            ['kl', 'Keluhan', 'keluhan', 'R'],
            ['tm', 'Pengguna', 'pengguna', 'R'],
            ['tl', 'Titik Layanan', 'titik-layanan', 'R'],
            ['st', 'Stok', 'bahan-baku', 'R'],
            ['ds', 'Distribusi', 'bukti-distribusi', 'R'],
            ['ku', 'Keuangan', 'keuangan', 'R'],
            ['jd', 'Jadwal', 'jadwal', 'R'],
            ['mt', 'Mutu', 'mutu', 'R'],
        ],
        'kepala_sppg' => [
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['jd', 'Jadwal', 'jadwal', 'R'],
            ['ds', 'Distribusi', 'bukti-distribusi', 'R'],
            ['ar', 'Armada', 'armada', 'R'],
            ['rk', 'Rekap Harian', 'rekap-harian', 'R'],
            ['kl', 'Keluhan', 'keluhan', 'R'],
            ['ku', 'Keuangan', 'keuangan', 'R'],
            ['st', 'Stok', 'bahan-baku', 'R'],
            ['mt', 'Mutu', 'mutu', 'R'],
            ['lg', 'Log Produksi', 'log-produksi', 'R'],
            ['tl', 'Titik Layanan', 'titik-layanan', 'R'],
            ['tm', 'Tim', 'pengguna', 'R'],
            ['np', 'Notifikasi Perjalanan', 'notifikasi-perjalanan', 'R'],
            ['mp', 'Mulai Perjalanan', 'notifikasi-perjalanan', 'R'],
        ],
        'ahli_gizi' => [
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['mg', 'Menu dan Gizi', 'menu-gizi', 'R'],
            ['pj', 'Persetujuan Menu', 'persetujuan-menu', 'R'],
            ['mt', 'Mutu', 'mutu', 'R'],
            ['jd', 'Jadwal', 'jadwal', 'R'],
            ['kl', 'Keluhan', 'keluhan', 'R'],
            ['rk', 'Rekap', 'rekap-harian', 'R'],
            ['st', 'Stok', 'bahan-baku', 'R'],
            ['pn', 'Penerima Manfaat', 'penerima-manfaat', LayarBaca::HAK_AGREGAT],
            ['lg', 'Log Produksi', 'log-produksi', 'R'],
        ],
        'akuntan' => [
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['pc', 'Pencairan', 'keuangan', 'R'],
            ['pk', 'Pengeluaran', 'keuangan', 'R'],
            ['ag', 'Anggaran', 'keuangan', 'R'],
            ['st', 'Stok', 'bahan-baku', 'R'],
            ['pe', 'Pemasok', 'pemasok', 'R'],
            ['pb', 'Pembelian Bahan', 'pembelian-bahan', 'R'],
            ['ar', 'Armada', 'armada', 'R'],
            ['rk', 'Rekap', 'rekap-harian', 'R'],
            ['dpk', 'Catat pengeluaran', 'keuangan', 'R'],
            ['dpc', 'Catat pencairan', 'keuangan', 'R'],
        ],
        'staf_gudang' => [
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['st', 'Stok', 'bahan-baku', 'R'],
            ['sm', 'Stok Menipis', 'bahan-baku', 'R'],
            ['kd', 'Kedaluwarsa', 'bahan-baku', 'R'],
            ['pe', 'Pemasok', 'pemasok', 'R'],
            ['pb', 'Pembelian Bahan', 'pembelian-bahan', 'R'],
            ['mg', 'Menu', 'menu-gizi', 'R'],
            ['jd', 'Jadwal', 'jadwal', 'R'],
            ['lg', 'Log Produksi', 'log-produksi', 'R'],
            ['dms', 'Catat mutasi stok', 'bahan-baku', 'R'],
        ],
        'relawan.dapur' => [
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['jd', 'Jadwal Produksi', 'jadwal', 'R'],
            ['lg', 'Log Produksi', 'log-produksi', 'R'],
            ['mh', 'Menu Hari Ini', 'menu-gizi', 'R'],
            ['mt', 'Mutu', 'mutu', 'R'],
            ['dkl', 'Buat Keluhan', 'keluhan', 'R'],
            ['pf', 'Profil Saya', 'pengguna', LayarBaca::HAK_BACA_MILIK_SENDIRI],
        ],
        'relawan.distribusi' => [
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['jd', 'Jadwal Distribusi', 'jadwal', 'R'],
            ['ds', 'Bukti Distribusi', 'bukti-distribusi', 'R'],
            ['mp', 'Mulai Perjalanan', 'notifikasi-perjalanan', 'R'],
            ['ar', 'Armada', 'armada', 'R'],
            ['mh', 'Menu Hari Ini', 'menu-gizi', 'R'],
            ['dkl', 'Buat Keluhan', 'keluhan', 'R'],
            ['pf', 'Profil Saya', 'pengguna', LayarBaca::HAK_BACA_MILIK_SENDIRI],
        ],
        'relawan.tanpa_unit' => [
            ['mh', 'Menu Hari Ini', 'menu-gizi', 'R'],
            ['mt', 'Mutu', 'mutu', 'R'],
            ['ar', 'Armada', 'armada', 'R'],
            ['dkl', 'Buat Keluhan', 'keluhan', 'R'],
            ['pf', 'Profil Saya', 'pengguna', LayarBaca::HAK_BACA_MILIK_SENDIRI],
        ],
        'investor' => [
            [self::KEPUTUSAN, 'Menunggu keputusan', null, self::HAK_PUTUSKAN],
            ['db', 'Dashboard', null, self::HAK_DASHBOARD],
            ['rk', 'Rekap', 'rekap-harian', 'R'],
            ['ag', 'Anggaran', 'keuangan', 'R'],
            ['pj', 'Persetujuan Menu', 'persetujuan-menu', 'R'],
            ['ps', 'Pilih SPPG', null, self::HAK_PILIH],
        ],
    ];

    private const HAK = [LayarBaca::HAK_BACA, LayarBaca::HAK_BACA_MILIK_SENDIRI, LayarBaca::HAK_AGREGAT, LayarBaca::HAK_SESI];

    /** @var array<string, LayarBaca> */
    private array $layar = [];

    /** @param list<LayarBaca>|null $layar null = semua layar (bawaan + layar baca modul, dari container) */
    public function __construct(?array $layar = null)
    {
        foreach ($layar ?? self::layarSemua() as $l) {
            $this->daftarkan($l);
        }

        $this->validasiMenu();
    }

    /**
     * Layar bawaan (beranda, pilih SPPG, Tanya Agen AI, bantuan) + layar baca modul yang sudah dibangun.
     *
     * @return list<LayarBaca>
     */
    public static function layarBawaan(): array
    {
        return array_map(fn (string $kelas) => app($kelas), self::LAYAR);
    }

    /**
     * Semua layar produksi: layar bawaan + layar baca modul (DefinisiLayar, R02-B1).
     *
     * @return list<LayarBaca>
     */
    public static function layarSemua(): array
    {
        return [...self::layarBawaan(), ...DefinisiLayar::semua()];
    }

    /**
     * Pecah id navigasi menjadi [id dasar, indeks halaman]. Id berakhiran 2-9 = halaman ke-2 sampai ke-9 dari
     * layar dasar (id layar terdaftar tidak boleh berakhiran angka). Selain itu halaman 0.
     *
     * @return array{0: string, 1: int}
     */
    public static function pecahId(string $id): array
    {
        if (preg_match('/^([a-z](?:[a-z0-9]{0,5}[a-z])?)([2-9])$/D', $id, $m) === 1) {
            return [$m[1], (int) $m[2] - 1];
        }

        return [$id, 0];
    }

    /** Id navigasi untuk halaman $halaman (0 = id dasar) dari layar $dasar. */
    public static function idHalaman(string $dasar, int $halaman): string
    {
        return $halaman > 0 ? $dasar.(min($halaman, self::HALAMAN_MAKS - 1) + 1) : $dasar;
    }

    public function cari(string $id): ?LayarBaca
    {
        return $this->layar[$id] ?? null;
    }

    /** @return array<string, LayarBaca> */
    public function semua(): array
    {
        return $this->layar;
    }

    /** Profil menu pengguna: peran, atau relawan.<unit|tanpa_unit>. */
    public static function profil(User $user): ?string
    {
        if ($user->role === Role::Relawan) {
            return match ($user->unit) {
                RelawanUnit::Dapur => 'relawan.dapur',
                RelawanUnit::Distribusi => 'relawan.distribusi',
                default => 'relawan.tanpa_unit',
            };
        }

        return $user->role instanceof Role ? $user->role->value : null;
    }

    /**
     * Hak RBAC peran SAAT INI untuk sebuah hak layar (satu-satunya tempat aturan ini; dipakai BotNavigasi
     * pada setiap klik dan beranda untuk menyaring tombol).
     */
    public static function hakRbac(User $user, ?string $modul, string $hak): bool
    {
        return match ($hak) {
            LayarBaca::HAK_SESI => $modul === null,
            LayarBaca::HAK_BACA => $modul !== null && Rbac::can($user, $modul, 'R') && ! Rbac::isSelfOnly($user, $modul, 'R'),
            LayarBaca::HAK_BACA_MILIK_SENDIRI => $modul !== null && Rbac::can($user, $modul, 'R'),
            LayarBaca::HAK_AGREGAT => $modul !== null && Rbac::canAggregate($user, $modul),
            default => false,
        };
    }

    /** Admin, atau investor dengan lebih dari satu SPPG (pivot saat ini). */
    public static function bisaMemilihSppg(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->role === Role::Investor && count(app(SppgAktif::class)->milik($user)) > 1;
    }

    /** Pengguna berhak memutuskan pengajuan (investor): sama dengan BotKeputusanService::bisaMemutuskan. */
    public static function bisaMemutuskan(User $user): bool
    {
        return Rbac::canDecide($user, 'persetujuan-menu') || Rbac::canDecide($user, 'keuangan');
    }

    /**
     * Tombol utama beranda untuk pengguna (belum termasuk baris tetap), urutan sesuai MENU. Layar data hanya bila
     * `$adaSppg` (SPPG efektif sudah ada). Layar yang belum terdaftar tidak pernah dimasukkan.
     *
     * @return list<array{layar: string, label: string, keputusan: bool}>
     */
    public function tombolBeranda(User $user, bool $adaSppg): array
    {
        $hasil = [];

        foreach (self::MENU[self::profil($user) ?? ''] ?? [] as [$id, $label, $modul, $hak]) {
            $tampil = match ($hak) {
                self::HAK_PILIH => isset($this->layar[$id]) && self::bisaMemilihSppg($user),
                self::HAK_PUTUSKAN => self::bisaMemutuskan($user),
                // Dashboard: admin/investor boleh gabungan tanpa SPPG terpilih; peran lain butuh SPPG efektif.
                self::HAK_DASHBOARD => isset($this->layar[$id]) && ($adaSppg || $user->isAdmin() || $user->role === Role::Investor) && $this->layar[$id]->izinkan($user, null),
                // Layar aksi (PR-3, mis. Mulai Perjalanan): juga butuh hak aksi peran saat ini (LayarAksi::tampilUntuk).
                default => $adaSppg && isset($this->layar[$id]) && self::hakRbac($user, $modul, $hak)
                    && (! $this->layar[$id] instanceof LayarAksi || $this->layar[$id]->tampilUntuk($user)),
            };

            if ($tampil) {
                $hasil[] = ['layar' => $id, 'label' => $label, 'keputusan' => $hak === self::HAK_PUTUSKAN];
            }
        }

        return $hasil;
    }

    private function daftarkan(LayarBaca $l): void
    {
        $id = $l->id();
        $modul = $l->modul();
        $hak = $l->hak();

        // Id maks. 7 karakter dan tidak boleh berakhiran angka (angka akhir 2-9 = penanda halaman, lihat pecahId; id halaman harus muat 8 karakter di BotNavCodec).
        if (preg_match('/^[a-z](?:[a-z0-9]{0,5}[a-z])?$/D', $id) !== 1 || isset($this->layar[$id])) {
            throw new LogicException("Layar bot tidak sah atau ganda: {$id}");
        }

        if (! in_array($hak, self::HAK, true)) {
            throw new LogicException("Hak layar bot tidak dikenal: {$id}");
        }

        if ($hak === LayarBaca::HAK_SESI ? ($modul !== null || ! in_array($id, self::TANPA_MODUL, true)) : ($modul === null || ! Rbac::hasModule($modul))) {
            throw new LogicException("Modul/hak layar bot tidak konsisten: {$id}");
        }

        $this->layar[$id] = $l;
    }

    /** Matriks MENU harus menunjuk modul yang ada dan sesuai dengan layar terdaftar (gagal tertutup). */
    private function validasiMenu(): void
    {
        foreach (self::MENU as $profil => $entri) {
            foreach ($entri as [$id, , $modul, $hak]) {
                if (in_array($hak, [self::HAK_PILIH, self::HAK_PUTUSKAN, self::HAK_DASHBOARD], true)) {
                    if ($hak === self::HAK_DASHBOARD && isset($this->layar[$id]) && ($id !== self::DASHBOARD || $this->layar[$id]->hak() !== LayarBaca::HAK_SESI)) {
                        throw new LogicException("Entri menu dashboard bot tidak sesuai layar: {$profil}/{$id}");
                    }

                    continue;
                }

                if ($modul === null || ! Rbac::hasModule($modul) || ! in_array($hak, self::HAK, true) || $hak === LayarBaca::HAK_SESI) {
                    throw new LogicException("Entri menu bot tidak sah: {$profil}/{$id}");
                }

                $layar = $this->layar[$id] ?? null;

                if ($layar !== null && ($layar->modul() !== $modul || $layar->hak() !== $hak)) {
                    throw new LogicException("Entri menu bot tidak sesuai layar: {$profil}/{$id}");
                }
            }
        }
    }
}

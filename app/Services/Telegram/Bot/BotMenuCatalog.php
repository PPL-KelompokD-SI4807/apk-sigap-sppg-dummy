<?php

namespace App\Services\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Support\Rbac;

/**
 * Katalog pintasan layar Mini App pada menu Bot (R-02). BUKAN sumber hak akses:
 * - Daftar per profil hanya memilih pintasan yang relevan (maks. 6) agar menu ringkas.
 * - Sebuah butir tampil HANYA bila sel RBAC pengguna mengizinkan (Rbac::can + bukan self-only), sehingga
 *   perubahan config/rbac.php (termasuk aturan unit relawan lewat Rbac::letters) langsung berlaku.
 * Label tidak memuat data dinamis/pribadi. Rute mengacu slug Mini App (registry/router).
 */
final class BotMenuCatalog
{
    public const MAKS_BUTIR = 6;

    /**
     * id => [label, modul RBAC, huruf, rute Mini App].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const BUTIR = [
        'pengguna' => ['Pengguna', 'pengguna', 'R', '/pengguna'],
        'sppg' => ['Data SPPG', 'sppg', 'R', '/sppg'],
        'titik-layanan' => ['Titik Layanan', 'titik-layanan', 'R', '/titik-layanan'],
        'keluhan' => ['Keluhan', 'keluhan', 'R', '/keluhan'],
        'rekap-harian' => ['Rekap Harian', 'rekap-harian', 'R', '/rekap-harian'],
        'jadwal' => ['Jadwal', 'jadwal', 'R', '/jadwal'],
        'notifikasi-baru' => ['Notifikasi Perjalanan', 'notifikasi-perjalanan', 'C', '/notifikasi-perjalanan/baru'],
        'bukti-distribusi' => ['Bukti Distribusi', 'bukti-distribusi', 'R', '/bukti-distribusi'],
        'keuangan' => ['Keuangan', 'keuangan', 'R', '/keuangan'],
        'pengeluaran' => ['Pengeluaran', 'keuangan', 'R', '/keuangan/pengeluaran'],
        'menu-gizi' => ['Menu dan Gizi', 'menu-gizi', 'R', '/menu-gizi'],
        'persetujuan-menu' => ['Persetujuan Menu', 'persetujuan-menu', 'R', '/persetujuan-menu'],
        'mutu' => ['Mutu Pangan', 'mutu', 'R', '/mutu'],
        'pembelian-bahan' => ['Pembelian Bahan', 'pembelian-bahan', 'R', '/pembelian-bahan'],
        'bahan-baku' => ['Bahan Baku', 'bahan-baku', 'R', '/bahan-baku'],
        'stok-menipis' => ['Stok Menipis', 'bahan-baku', 'R', '/bahan-baku/stok-menipis'],
        'kedaluwarsa-dekat' => ['Kedaluwarsa Dekat', 'bahan-baku', 'R', '/bahan-baku/kedaluwarsa-dekat'],
        'pemasok' => ['Pemasok', 'pemasok', 'R', '/pemasok'],
        'log-produksi' => ['Log Produksi', 'log-produksi', 'R', '/log-produksi'],
        'armada' => ['Armada', 'armada', 'R', '/armada'],
    ];

    /**
     * Pintasan per profil (peran, atau relawan.<unit>). Urutan = urutan tampil.
     *
     * @var array<string, list<string>>
     */
    public const PROFIL = [
        'admin' => ['pengguna', 'sppg', 'titik-layanan', 'keluhan', 'rekap-harian'],
        'kepala_sppg' => ['jadwal', 'notifikasi-baru', 'bukti-distribusi', 'keluhan', 'rekap-harian', 'keuangan'],
        'ahli_gizi' => ['menu-gizi', 'persetujuan-menu', 'jadwal', 'mutu', 'keluhan', 'rekap-harian'],
        'akuntan' => ['keuangan', 'pengeluaran', 'pembelian-bahan', 'rekap-harian'],
        'staf_gudang' => ['bahan-baku', 'stok-menipis', 'kedaluwarsa-dekat', 'pembelian-bahan', 'pemasok'],
        'relawan.dapur' => ['jadwal', 'log-produksi', 'mutu', 'keluhan'],
        'relawan.distribusi' => ['jadwal', 'notifikasi-baru', 'bukti-distribusi', 'keluhan'],
        'relawan.tanpa_unit' => ['mutu', 'keluhan', 'armada'],
        'investor' => ['persetujuan-menu', 'pengeluaran', 'keuangan', 'rekap-harian', 'keluhan', 'mutu'],
    ];

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
     * Pintasan yang boleh ditampilkan untuk pengguna (disaring RBAC), maks. MAKS_BUTIR.
     *
     * @return list<array{id: string, label: string, modul: string, rute: string}>
     */
    public static function untuk(User $user): array
    {
        $profil = self::profil($user);
        $hasil = [];

        foreach (self::PROFIL[$profil] ?? [] as $id) {
            [$label, $modul, $huruf, $rute] = self::BUTIR[$id];

            if (! Rbac::can($user, $modul, $huruf) || Rbac::isSelfOnly($user, $modul, $huruf)) {
                continue;
            }

            $hasil[] = ['id' => $id, 'label' => $label, 'modul' => $modul, 'rute' => $rute];
        }

        return array_slice($hasil, 0, self::MAKS_BUTIR);
    }
}

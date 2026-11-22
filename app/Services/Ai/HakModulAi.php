<?php

namespace App\Services\Ai;

use App\Enums\JenisInputAi;
use App\Enums\Role;
use App\Models\User;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\ToolSelector;
use App\Support\Rbac;

/**
 * Hak modul yang DISEBUT JELAS dalam pesan Agen AI, dihitung SERVER dari matriks Tabel 5 (config/rbac.php lewat Rbac).
 * Tidak membuka hak apa pun: hanya menyusun penolakan/keterangan yang benar (temuan uji per peran P10/P11).
 *
 * - penolakan(): balasan server TANPA memanggil LLM bila pesan jelas menyasar modul yang tidak dapat diakses peran
 *   (mis. Akuntan "tampilkan keluhan terbaru" -> "Anda tidak punya akses ke data keluhan.") atau meminta aksi tulis yang
 *   tidak dimiliki peran pada modul itu (mis. Kepala SPPG "ubah sub-kategori pengeluaran ..." -> peran berhak: Akuntan).
 *   Sengaja konservatif: hanya kata benda utama modul (KATA_MODUL), tidak ada tool milik peran yang cocok dengan pesan
 *   (ToolSelector::rank, selalu subset ToolRegistry::forUser), dan untuk aksi tulis tidak ada kata tanya/baca. Pesan
 *   ganda/ambigu tetap ke LLM dengan keterangan hak dari catatanPrompt().
 * - catatanPrompt(): baris konteks tepercaya "Hak terkait" untuk prompt sistem, agar penolakan model menyebut peran yang
 *   BERHAK menurut matriks (bukan selalu "Kepala SPPG/Admin").
 * - peranBerhak(): label peran yang memiliki huruf C/R/U/D pada modul (investor tidak pernah untuk C/U/D, R-06a).
 */
final class HakModulAi
{
    /** Kata benda utama per slug modul (kata/frasa utuh, huruf kecil, setelah ToolSelector::haystack). */
    private const KATA_MODUL = [
        'pengguna' => ['pengguna', 'undangan pengguna'],
        'notifikasi-perjalanan' => ['notifikasi perjalanan', 'perjalanan'],
        'titik-layanan' => ['titik layanan'],
        'penerima-manfaat' => ['penerima manfaat'],
        'bahan-baku' => ['bahan baku', 'stok'],
        'pemasok' => ['pemasok', 'supplier'],
        'menu-gizi' => ['menu', 'standar gizi'],
        'jadwal' => ['jadwal'],
        'log-produksi' => ['log produksi'],
        'mutu' => ['pemeriksaan mutu', 'mutu', 'organoleptik'],
        'armada' => ['armada', 'pengemudi'],
        'bukti-distribusi' => ['bukti distribusi'],
        'keuangan' => ['keuangan', 'pengeluaran', 'pencairan', 'sub kategori', 'subkategori'],
        'keluhan' => ['keluhan', 'komplain', 'aduan', 'feedback'],
        'rekap-harian' => ['rekap'],
        'pembelian-bahan' => ['pembelian'],
        'persetujuan-menu' => ['pengajuan menu', 'persetujuan menu'],
    ];

    /** Nama pendek modul untuk kalimat balasan ("data keluhan"). */
    private const NAMA_PENDEK = [
        'pengguna' => 'pengguna',
        'notifikasi-perjalanan' => 'notifikasi perjalanan',
        'titik-layanan' => 'titik layanan',
        'penerima-manfaat' => 'penerima manfaat',
        'bahan-baku' => 'bahan baku',
        'pemasok' => 'pemasok',
        'menu-gizi' => 'menu dan gizi',
        'jadwal' => 'jadwal produksi dan distribusi',
        'log-produksi' => 'log produksi harian',
        'mutu' => 'pemeriksaan mutu',
        'armada' => 'armada dan pengemudi',
        'bukti-distribusi' => 'bukti distribusi',
        'keuangan' => 'keuangan',
        'keluhan' => 'keluhan',
        'rekap-harian' => 'rekap harian',
        'pembelian-bahan' => 'pembelian bahan',
        'persetujuan-menu' => 'persetujuan menu',
    ];

    /**
     * Kata penanda permintaan GANDA atau baca (mis. "ringkas keluhan dan buat keluhan baru"): penolakan aksi tulis tidak
     * diputuskan server; pesan diteruskan ke agen (bagian yang berhak tetap dilayani).
     */
    private const KATA_CAMPURAN = ['dan', 'lalu', 'kemudian', 'serta', 'sekaligus', 'juga', 'ringkas', 'ringkasan', 'rangkum', 'rangkuman'];

    private const KERJA = ['C' => 'menambah', 'R' => 'melihat', 'U' => 'mengubah', 'D' => 'menghapus'];

    /** Batas modul yang dicantumkan di prompt (hemat token). */
    private const MAKS_CATATAN = 3;

    public function __construct(private readonly ToolSelector $selector) {}

    /**
     * Slug modul yang disebut jelas di pesan (urutan config/rbac.php), hanya modul yang terdaftar.
     *
     * @return list<string>
     */
    public static function modulDisebut(string $text): array
    {
        $haystack = ToolSelector::haystack($text);
        $hasil = [];

        foreach (self::KATA_MODUL as $modul => $kata) {
            if (! Rbac::hasModule($modul)) {
                continue;
            }

            foreach ($kata as $k) {
                if (str_contains($haystack, ' '.$k.' ') || str_contains($haystack, ' '.$k.'nya ')) {
                    $hasil[] = $modul;
                    break;
                }
            }
        }

        return $hasil;
    }

    /**
     * Label peran yang memegang huruf $letter pada modul menurut matriks (sel mentah, termasuk bertanda "*").
     * Investor tidak pernah berhak C/U/D (baca saja, R-06a).
     *
     * @return list<string>
     */
    public static function peranBerhak(string $modul, string $letter): array
    {
        if (! Rbac::hasModule($modul)) {
            return [];
        }

        $hasil = [];

        foreach (Role::cases() as $role) {
            if ($role === Role::Investor && $letter !== 'R') {
                continue;
            }

            if (str_contains(Rbac::cell($role, $modul), $letter)) {
                $hasil[] = $role->label();
            }
        }

        return $hasil;
    }

    public static function namaPendek(string $modul): string
    {
        return self::NAMA_PENDEK[$modul] ?? mb_strtolower((string) config("rbac.modules.{$modul}.label", $modul));
    }

    /**
     * Balasan penolakan SERVER (tanpa LLM) atau null bila pesan perlu diproses agen seperti biasa.
     *
     * @param  array<string, AiTool>  $allowed  hasil ToolRegistry::forUser()
     */
    public function penolakan(User $user, string $text, JenisInputAi $type, array $allowed): ?string
    {
        if ($type === JenisInputAi::Foto || trim($text) === '') {
            return null;
        }

        $modul = self::modulDisebut($text);

        if ($modul === []) {
            return null;
        }

        $ranked = $this->selector->rank($allowed, $text, $type);
        $haystack = ToolSelector::haystack($text);
        $tulis = array_values(array_diff(array_keys(ToolSelector::niat($haystack, $type)), ['R']));

        // (a) Modul yang disebut sama sekali tidak dapat diakses peran ini, dan tidak ada tool milik peran yang cocok.
        $tanpaAkses = array_values(array_filter($modul, fn (string $m) => Rbac::letters($user, $m) === ''));

        if ($ranked === [] && $tanpaAkses === $modul) {
            return 'Anda tidak punya akses ke data '.self::gabung(array_map(self::namaPendek(...), $modul)).'.';
        }

        // (b) Aksi tulis yang tidak dimiliki peran pada SEMUA modul yang disebut; tanpa kata tanya/baca dan tanpa tool
        // tulis milik peran yang cocok (pesan campuran diserahkan ke agen).
        if ($tulis === [] || ToolSelector::adaKataBaca($haystack) || self::adaKata($haystack, self::KATA_CAMPURAN)) {
            return null;
        }

        foreach ($ranked as $nama) {
            if (($allowed[$nama] ?? null)?->isWrite()) {
                return null;
            }
        }

        foreach ($modul as $m) {
            foreach ($tulis as $huruf) {
                if (Rbac::can($user, $m, $huruf)) {
                    return null;
                }
            }
        }

        $kalimat = [];

        foreach ($modul as $m) {
            if (Rbac::letters($user, $m) === '') {
                $kalimat[] = 'Anda tidak punya akses ke data '.self::namaPendek($m).'.';

                continue;
            }

            $kerja = self::gabung(array_map(fn (string $h) => self::KERJA[$h], $tulis), 'atau');
            $peran = [];

            foreach ($tulis as $huruf) {
                array_push($peran, ...self::peranBerhak($m, $huruf));
            }

            $peran = array_values(array_unique($peran));
            $kalimat[] = "Anda tidak berhak {$kerja} data ".self::namaPendek($m).'. '
                .($peran === [] ? 'Aksi ini tidak tersedia untuk peran mana pun.' : 'Yang berhak: '.self::gabung($peran).'.');
        }

        return implode(' ', $kalimat).' Data tidak diubah dan tidak ada draf yang dibuat.';
    }

    /**
     * Baris konteks tepercaya untuk prompt sistem: hak yang TIDAK dimiliki peran pada modul yang disebut pesan, beserta
     * peran yang berhak menurut matriks. Kosong bila peran memiliki semua hak yang diminta.
     *
     * @return list<string>
     */
    public function catatanPrompt(User $user, string $text, JenisInputAi $type = JenisInputAi::Teks): array
    {
        $haystack = ToolSelector::haystack($text);
        $huruf = array_keys(ToolSelector::niat($haystack, $type));
        $baris = [];

        foreach (array_slice(self::modulDisebut($text), 0, self::MAKS_CATATAN) as $m) {
            $label = (string) config("rbac.modules.{$m}.label", $m);

            if (Rbac::letters($user, $m) === '') {
                $peran = self::peranBerhak($m, 'R');
                $baris[] = "{$label}: peran pengguna tidak punya akses".($peran === [] ? '' : '; yang berhak melihat: '.implode(', ', $peran));

                continue;
            }

            $kurang = [];

            foreach ($huruf as $h) {
                if (! Rbac::can($user, $m, $h)) {
                    $peran = self::peranBerhak($m, $h);
                    $kurang[] = self::KERJA[$h].' hanya oleh '.($peran === [] ? '(tidak ada peran)' : implode(', ', $peran));
                }
            }

            if ($kurang !== []) {
                $baris[] = "{$label}: ".implode('; ', $kurang);
            }
        }

        return $baris;
    }

    /** @param list<string> $kata kata utuh */
    private static function adaKata(string $haystack, array $kata): bool
    {
        foreach ($kata as $k) {
            if (str_contains($haystack, ' '.$k.' ')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $kata */
    private static function gabung(array $kata, string $sambung = 'dan'): string
    {
        $kata = array_values(array_unique($kata));

        if (count($kata) <= 1) {
            return $kata[0] ?? '';
        }

        $akhir = array_pop($kata);

        return implode(', ', $kata).' '.$sambung.' '.$akhir;
    }
}

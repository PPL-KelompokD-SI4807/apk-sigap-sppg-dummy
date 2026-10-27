<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Enums\Role;
use App\Models\User;
use App\Services\Ai\Tools\ToolException;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\TelegramBotService;
use App\Support\Rbac;
use LogicException;

/**
 * Petunjuk alur DRAF (R-02 PR-3, C2; bagian 3.7-A rencana): "Catat pengeluaran", "Catat pencairan", "Catat mutasi stok",
 * "Buat Keluhan". Layar BACA-SAJA: bot TIDAK menulis data. Isinya hanya petunjuk + contoh kalimat agar pengguna mengetik ke
 * Agen AI seperti biasa (Agen AI membuat AiDraft; tombol "Periksa & Simpan" ?draft=<uuid> (AiChatHandler) yang sudah ada tetap
 * dipakai), plus tombol web_app "Buka formulir" ke rute formulir modul yang SUDAH terdaftar di Mini App (?r=). Konfirmasi
 * dan penyimpanan tetap di Mini App (keputusan pengguna #2).
 *
 * Tombol hanya tampil (tampilUntuk) bila: pengguna aktif, bukan admin/investor, perannya termasuk peran alur ini, punya
 * SPPG, huruf RBAC (C/U) dari config/rbac.php saat ini, DAN ToolRegistry::forUser memuat tool tulis terkait. Pemeriksaan
 * yang sama diulang pada setiap klik lewat izinkan() (BotNavigasi menambah sesi penuh, RBAC baca, dan kecocokan SPPG).
 * Isi layar: tanpa data bisnis, tanpa nama SPPG, tanpa nama pengguna.
 */
final class LayarAlurDraf extends LayarModulDasar implements LayarAksi
{
    /**
     * Definisi alur: id layar => judul, modul RBAC, huruf, tool tulis Agen AI, peran, rute formulir Mini App (null =
     * belum ada rute formulir yang dapat dibuka lewat ?r=, hanya petunjuk), contoh kalimat, catatan.
     * Rute harus terdaftar di resources/miniapp (diuji BotAlurDrafTest terhadap sumber Mini App).
     *
     * @var array<string, array{judul: string, modul: string, huruf: string, tool: string, peran: list<Role>, rute: ?string, contoh: list<string>, catatan: string}>
     */
    public const ALUR = [
        'dpk' => [
            'judul' => 'Catat Pengeluaran', 'modul' => 'keuangan', 'huruf' => 'C', 'tool' => 'buat_pengeluaran',
            'peran' => [Role::Akuntan], 'rute' => '/keuangan/pengeluaran/baru',
            'contoh' => ['Catat pengeluaran gas elpiji Rp 150.000 hari ini', 'Buat pengeluaran kategori energi Rp 200.000'],
            'catatan' => 'Foto nota ditambahkan saat Anda memeriksa dan menyimpan draf di Mini App.',
        ],
        'dpc' => [
            'judul' => 'Catat Pencairan', 'modul' => 'keuangan', 'huruf' => 'C', 'tool' => 'buat_pencairan_dana',
            'peran' => [Role::Akuntan], 'rute' => '/keuangan/pencairan/baru',
            'contoh' => ['Catat pencairan dana Rp 10.000.000 hari ini', 'Buat pencairan dana periode bulan ini sebesar Rp 5.000.000'],
            'catatan' => 'Bukti pencairan ditambahkan saat Anda memeriksa dan menyimpan draf di Mini App.',
        ],
        'dms' => [
            'judul' => 'Catat Mutasi Stok', 'modul' => 'bahan-baku', 'huruf' => 'U', 'tool' => 'catat_mutasi_stok',
            'peran' => [Role::StafGudang], 'rute' => null,
            'contoh' => ['Catat stok beras masuk 50 kg', 'Catat pemakaian minyak goreng 5 liter'],
            'catatan' => 'Stok baru berubah setelah Anda memeriksa dan menyimpan draf di Mini App.',
        ],
        'dkl' => [
            'judul' => 'Buat Keluhan', 'modul' => 'keluhan', 'huruf' => 'C', 'tool' => 'buat_keluhan',
            'peran' => [Role::Relawan], 'rute' => '/keluhan/baru',
            'contoh' => ['Buat keluhan: sayur terlalu asin di salah satu titik layanan', 'Catat keluhan makanan terlambat tiba, prioritas sedang'],
            'catatan' => 'Jangan menulis nama atau nomor telepon anak di keluhan.',
        ],
    ];

    public function __construct(private readonly string $kode)
    {
        if (! isset(self::ALUR[$kode])) {
            throw new LogicException("Alur draf bot tidak dikenal: {$kode}");
        }
    }

    /** @return list<string> */
    public static function semuaId(): array
    {
        return array_keys(self::ALUR);
    }

    public function id(): string
    {
        return $this->kode;
    }

    public function modul(): ?string
    {
        return self::ALUR[$this->kode]['modul'];
    }

    protected function judul(): string
    {
        return self::ALUR[$this->kode]['judul'].' (Agen AI)';
    }

    /** Rute formulir modul (null = belum ada rute formulir yang dapat dibuka lewat ?r=). */
    public function ruteFormulir(): ?string
    {
        return self::ALUR[$this->kode]['rute'];
    }

    /** Nama tool tulis Agen AI yang menjadi syarat tampil. */
    public function namaTool(): string
    {
        return self::ALUR[$this->kode]['tool'];
    }

    /**
     * Hak tingkat peran SAAT INI tanpa data: aktif, bukan admin/investor, peran alur ini, punya SPPG penempatan, huruf RBAC
     * (C/U, bukan self-only), dan tool tulis ada di ToolRegistry::forUser(user). Dipakai tombol beranda dan setiap klik.
     */
    public function tampilUntuk(User $user): bool
    {
        $d = self::ALUR[$this->kode];

        if (! $user->canAuthenticate() || $user->isAdmin() || ! $user->role instanceof Role || $user->role === Role::Investor
            || ! in_array($user->role, $d['peran'], true) || $user->sppg_id === null
            || ! Rbac::can($user, $d['modul'], $d['huruf']) || Rbac::isSelfOnly($user, $d['modul'], $d['huruf'])) {
            return false;
        }

        try {
            app(ToolRegistry::class)->resolveFor($user, $d['tool']);
        } catch (ToolException) {
            return false;
        }

        return true;
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return $this->tampilUntuk($user);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $d = self::ALUR[$this->kode];

        $isi = [
            'Ketik permintaan Anda langsung di chat ini seperti biasa; Agen AI menyiapkan <b>draf</b>. Data belum tersimpan.',
            '',
            'Contoh:',
            ...array_map(fn (string $c) => '- '.self::e($c), $d['contoh']),
            '',
            self::e($d['catatan']),
            'Setelah draf siap, tekan tombol "'.self::e('Periksa & Simpan').'" untuk memeriksa dan menyimpannya di Mini App.',
        ];

        if ($d['rute'] === null) {
            $isi[] = 'Anda juga dapat membukanya langsung di Mini App lewat menu Bahan Baku.';
        }

        $formulir = $d['rute'] === null ? null
            : TelegramBotService::webAppButton('Buka formulir', app(TelegramBotService::class)->miniAppUrl(['r' => $d['rute']], $d['rute']));

        return $this->susun($user, $sppgId, $konteks, $isi, barisTombol: $formulir === null ? [] : [[$formulir]], namaSppg: false);
    }
}

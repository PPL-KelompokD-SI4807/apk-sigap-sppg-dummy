<?php

namespace App\Services\Telegram\Bot;

use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusKeluhan;
use App\Enums\StatusKirim;
use App\Http\Requests\Perjalanan\StoreNotifikasiPerjalananRequest;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\NotifikasiPerjalanan;
use App\Models\RekapHarian;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Keluhan\KeluhanService;
use App\Services\Perjalanan\TripNotificationService;
use App\Services\Rekap\DailySummaryService;
use App\Services\Telegram\Bot\Layar\FormatLayar;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\TelegramBotService;
use App\Support\Rbac;
use App\Support\SppgAktif;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aksi tulis Bot berkonfirmasi dua langkah (R-02 PR-3, pola 3.7-B): tombol aksi `b1` pada layar baca -> layar
 * KONFIRMASI (rincian + "Ya, lakukan" / "Batal", satu grup token sekali pakai) -> service bisnis -> layar hasil.
 *
 * Aksi: kirim ulang rekap (DailySummaryService::kirimUlang), mulai perjalanan (TripNotificationService::pratinjau/mulai),
 * tindak lanjut keluhan baru -> diproses (KeluhanService::perbarui). Service yang sama dengan REST.
 *
 * Aturan (dicek ULANG di server pada setiap klik, pemanggil = BotCallbackHandler setelah pengguna dimuat ulang dan
 * SesiService::punyaSesiPenuh):
 * - Peran yang diizinkan per aksi (AKSI['peran']); admin dan investor tidak pernah. Huruf RBAC config/rbac.php saat ini.
 * - SPPG token == users.sppg_id saat ini == sppg_id subjek (IDOR lintas SPPG ditolak); Policy di dalam ActingUser + SppgAktif.
 * - Versi/keadaan yang DILIHAT pengguna disimpan di baris token (subjek_versi = sidik keadaan); berbeda -> BERUBAH ("data
 *   sudah berubah", muat ulang), dicek lagi di dalam transaksi dengan kunci baris tepat sebelum service.
 * - Rekap hanya hari ini/kemarin dan maks. 1x per rekap per `telegram.kirim_ulang_rekap_jeda_menit`; perjalanan hanya
 *   jadwal hari ini dan divalidasi dengan aturan StoreNotifikasiPerjalananRequest + TripNotificationService.
 * - Tidak ada teks keluhan, nama orang, kontak, atau chat id di layar/log/audit. Audit bersumber `bot` atas nama pengguna.
 */
class BotAksiTulisService
{
    public const TIDAK_BERHAK = 'tidak_berhak';

    public const TIDAK_ADA = 'tidak_ada';

    public const BERUBAH = 'berubah';

    public const DI_LUAR_JENDELA = 'di_luar_jendela';

    /** Rekap masih berstatus "menunggu" (sedang/akan dikirim): kirim ulang lewat bot ditolak (temuan E2-2). */
    public const SEDANG_DIKIRIM = 'sedang_dikirim';

    public const PESAN_BERUBAH = 'Data sudah berubah sejak Anda melihatnya. Tidak ada perubahan yang dilakukan. Muat ulang untuk melihat data terbaru.';

    public const PESAN_SEDANG_DIKIRIM = 'Rekap ini sedang dikirim (status Menunggu). Tunggu sampai terkirim atau gagal sebelum mengirim ulang, atau gunakan Mini App.';

    public const PESAN_SUDAH_DIPROSES = 'Aksi sudah diproses. Periksa hasilnya di Mini App sebelum mencoba lagi.';

    /** Hasil jalankan() yang berarti efek bisnis SUDAH terjadi (tidak dapat ditarik). */
    public const HASIL_BEREFEK = ['dikirim_ulang', 'dimulai', 'diproses'];

    public const LABEL_YA = 'Ya, lakukan';

    public const LABEL_BATAL = 'Batal';

    /** Layar konfirmasi/hasil mulai perjalanan; layar baca daftar jadwal berangkat. */
    public const LAYAR_MULAI_PERJALANAN = 'mp';

    /**
     * Definisi aksi: jenis token aksi => [jenis token "Ya", subjek_tipe, peran yang boleh, modul RBAC, huruf, layar baca asal].
     *
     * @var array<string, array{ya: string, subjek: string, peran: list<Role>, modul: string, huruf: string, layar: string}>
     */
    public const AKSI = [
        BotAksi::JENIS_KIRIM_ULANG_REKAP => [
            'ya' => BotAksi::JENIS_YA_KIRIM_ULANG_REKAP, 'subjek' => BotAksi::SUBJEK_REKAP,
            'peran' => [Role::KepalaSppg], 'modul' => 'rekap-harian', 'huruf' => 'U', 'layar' => 'rk',
        ],
        BotAksi::JENIS_PICU_PERJALANAN => [
            'ya' => BotAksi::JENIS_YA_PICU_PERJALANAN, 'subjek' => BotAksi::SUBJEK_JADWAL_DISTRIBUSI,
            'peran' => [Role::KepalaSppg, Role::Relawan], 'modul' => 'notifikasi-perjalanan', 'huruf' => 'C', 'layar' => self::LAYAR_MULAI_PERJALANAN,
        ],
        BotAksi::JENIS_TINDAK_KELUHAN => [
            'ya' => BotAksi::JENIS_YA_TINDAK_KELUHAN, 'subjek' => BotAksi::SUBJEK_KELUHAN,
            'peran' => [Role::KepalaSppg], 'modul' => 'keluhan', 'huruf' => 'U', 'layar' => 'kl',
        ],
    ];

    /** Maks. tombol aksi per layar baca. */
    public const MAKS_TOMBOL = 5;

    public function __construct(
        private readonly BotAksiService $aksi,
        private readonly TelegramBotService $bot,
        private readonly DailySummaryService $harian,
        private readonly TripNotificationService $trip,
        private readonly KeluhanService $keluhan,
    ) {}

    // ------------------------------------------------------------------ identitas token

    /** Jenis token milik alur aksi tulis (aksi, "Ya", atau Batal). */
    public static function milik(?string $jenis): bool
    {
        return $jenis !== null && (isset(self::AKSI[$jenis]) || $jenis === BotAksi::JENIS_BATAL_AKSI || self::dariYa($jenis) !== null);
    }

    /** Kunci aksi (jenis token aksi) untuk token aksi/"Ya"/Batal; null bila jenis/subjek tidak cocok. */
    public static function aksiToken(BotAksi $token): ?string
    {
        $aksi = match (true) {
            isset(self::AKSI[$token->jenis]) => $token->jenis,
            $token->jenis === BotAksi::JENIS_BATAL_AKSI => self::dariSubjek($token->subjek_tipe),
            default => self::dariYa($token->jenis),
        };

        return $aksi !== null && $token->subjek_tipe === self::AKSI[$aksi]['subjek'] ? $aksi : null;
    }

    public static function tahapAksi(BotAksi $token): bool
    {
        return isset(self::AKSI[$token->jenis]);
    }

    private static function dariYa(string $jenis): ?string
    {
        foreach (self::AKSI as $kunci => $d) {
            if ($d['ya'] === $jenis) {
                return $kunci;
            }
        }

        return null;
    }

    private static function dariSubjek(?string $subjek): ?string
    {
        foreach (self::AKSI as $kunci => $d) {
            if ($d['subjek'] === $subjek) {
                return $kunci;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ hak

    /**
     * Hak tingkat peran SAAT INI untuk satu aksi (tanpa data): aktif, bukan admin/investor, peran diizinkan, punya SPPG
     * penempatan, dan huruf RBAC (termasuk unit relawan) dari config/rbac.php. Dipakai tombol beranda/layar dan tiap klik.
     */
    public function bolehAksi(User $user, string $aksi): bool
    {
        $d = self::AKSI[$aksi] ?? null;

        return $d !== null
            && $user->canAuthenticate()
            && ! $user->isAdmin()
            && $user->role instanceof Role
            && $user->role !== Role::Investor
            && in_array($user->role, $d['peran'], true)
            && $user->sppg_id !== null
            && Rbac::can($user, $d['modul'], $d['huruf'])
            && ! Rbac::isSelfOnly($user, $d['modul'], $d['huruf']);
    }

    /**
     * Hitung ulang hak + keadaan untuk token ini. null = boleh; selain itu TIDAK_BERHAK / TIDAK_ADA / BERUBAH /
     * DI_LUAR_JENDELA. Tidak mengubah data dan tidak menghabiskan token.
     */
    public function periksa(User $user, BotAksi $token): ?string
    {
        $aksi = self::aksiToken($token);

        if ($aksi === null || ! $this->bolehAksi($user, $aksi) || $token->sppg_id === null || $token->subjek_id === null
            || (int) $token->sppg_id !== (int) $user->sppg_id) {
            return self::TIDAK_BERHAK;
        }

        $sppgId = (int) $token->sppg_id;

        try {
            return $this->dalamKonteks($user, $sppgId, function () use ($user, $token, $aksi, $sppgId): ?string {
                $subjek = $this->muat($aksi, (int) $token->subjek_id);

                if ($subjek === null) {
                    return self::TIDAK_ADA;
                }

                if ((int) $subjek->getAttribute('sppg_id') !== $sppgId || ! $this->izinkan($user, $aksi, $subjek)) {
                    return self::TIDAK_BERHAK;
                }

                // Batal tidak mengubah data: cukup hak.
                if ($token->jenis === BotAksi::JENIS_BATAL_AKSI) {
                    return null;
                }

                if ($this->versi($aksi, $subjek) !== (int) $token->subjek_versi) {
                    return self::BERUBAH;
                }

                if (! $this->dalamJendela($aksi, $subjek)) {
                    return self::DI_LUAR_JENDELA;
                }

                return $this->sedangDikirim($aksi, $subjek) ? self::SEDANG_DIKIRIM : null;
            });
        } catch (AuthorizationException) {
            return self::TIDAK_BERHAK;
        }
    }

    // ------------------------------------------------------------------ tombol pada layar baca

    /**
     * Tombol "Kirim ulang rekap" (hari ini dan kemarin yang sudah tersimpan) untuk layar Rekap. Dipanggil di dalam
     * konteks render BotNavigasi (ActingUser + SPPG). Admin/investor/peran tanpa hak: tidak ada tombol, tidak ada token.
     *
     * @return list<list<array<string, mixed>>>
     */
    public function tombolRekap(User $user, int $sppgId, KonteksLayar $konteks): array
    {
        $aksi = BotAksi::JENIS_KIRIM_ULANG_REKAP;

        if (! $this->bolehAksi($user, $aksi) || (int) $user->sppg_id !== $sppgId) {
            return [];
        }

        return $this->dalamKonteks($user, $sppgId, function () use ($user, $sppgId, $konteks, $aksi): array {
            [$hari, $kemarin] = $this->jendelaRekap();
            $baris = [];

            $rekap = RekapHarian::query()->withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $sppgId)
                ->where(fn ($q) => $q->whereDate('tanggal', $hari)->orWhereDate('tanggal', $kemarin))
                ->orderByDesc('tanggal')->limit(2)->get();

            foreach ($rekap as $r) {
                // Rekap berstatus "menunggu" sedang/akan dikirim: tanpa tombol (E2-2).
                if (! $this->sedangDikirim($aksi, $r) && $this->izinkan($user, $aksi, $r)) {
                    $baris[] = [$this->tombolAksi($user, $konteks, $aksi, $sppgId, $r, 'Kirim ulang rekap '.$r->tanggal->format('d/m'))];
                }
            }

            return $baris;
        });
    }

    /**
     * Tombol "Proses keluhan #id" untuk keluhan BERSTATUS BARU di antara baris yang sedang ditampilkan layar Keluhan
     * (id dari tool baca katalog, divalidasi ulang di sini). Label tanpa teks keluhan.
     *
     * @param  list<array<string, mixed>>  $barisTampil
     * @return list<list<array<string, mixed>>>
     */
    public function tombolKeluhan(User $user, int $sppgId, KonteksLayar $konteks, array $barisTampil): array
    {
        $aksi = BotAksi::JENIS_TINDAK_KELUHAN;
        $ids = array_values(array_unique(array_filter(array_map(fn (array $b) => is_numeric($b['id'] ?? null) ? (int) $b['id'] : null, $barisTampil))));

        if ($ids === [] || ! $this->bolehAksi($user, $aksi) || (int) $user->sppg_id !== $sppgId) {
            return [];
        }

        return $this->dalamKonteks($user, $sppgId, function () use ($user, $sppgId, $konteks, $aksi, $ids): array {
            $keluhan = FeedbackKeluhan::query()->withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $sppgId)->whereIn('id', $ids)->where('status', StatusKeluhan::Baru->value)
                ->get()->keyBy('id');
            $tombol = [];

            foreach ($ids as $id) {
                $k = $keluhan->get($id);

                if ($k !== null && count($tombol) < self::MAKS_TOMBOL && $this->izinkan($user, $aksi, $k)) {
                    $tombol[] = $this->tombolAksi($user, $konteks, $aksi, $sppgId, $k, 'Proses keluhan #'.$id);
                }
            }

            return BotTombol::susun($tombol);
        });
    }

    /**
     * Isi layar "Mulai Perjalanan": jadwal distribusi HARI INI berstatus siap/berangkat milik SPPG. Jadwal yang lolos
     * aturan keberangkatan mendapat tombol; yang tidak (mis. mutu belum Layak, sudah ada perjalanan aktif) ditampilkan
     * dengan alasan ramah tanpa tombol.
     *
     * @return array{isi: list<string>, tombol: list<list<array<string, mixed>>>}
     */
    public function daftarBerangkat(User $user, int $sppgId, KonteksLayar $konteks): array
    {
        $aksi = BotAksi::JENIS_PICU_PERJALANAN;

        if (! $this->bolehAksi($user, $aksi) || (int) $user->sppg_id !== $sppgId) {
            return ['isi' => ['Anda tidak dapat memulai perjalanan.'], 'tombol' => []];
        }

        return $this->dalamKonteks($user, $sppgId, function () use ($user, $sppgId, $konteks, $aksi): array {
            $jadwal = JadwalDistribusi::query()->withoutGlobalScope(SppgScope::class)
                ->with(['titikLayanan', 'armada', 'jadwalProduksi'])
                ->where('sppg_id', $sppgId)
                ->whereDate('tanggal_distribusi', $this->hariIni()->toDateString())
                ->whereIn('status', [StatusJadwalDistribusi::Siap->value, StatusJadwalDistribusi::Berangkat->value])
                ->orderBy('jam_berangkat')->orderBy('id')->limit(8)->get();

            $isi = [];
            $baris = [];

            foreach ($jadwal as $j) {
                if (! $this->izinkan($user, $aksi, $j)) {
                    continue;
                }

                $tujuan = FormatLayar::nama($j->titikLayanan?->nama);
                $jam = FormatLayar::jam($j->jam_berangkat);

                try {
                    $this->validasiPerjalanan($user, $j);
                    $isi[] = '- '.$this->e(sprintf('%s %s, %s porsi: siap berangkat', $jam, $tujuan, FormatLayar::angka($j->jumlah_porsi)));

                    if (count($baris) < self::MAKS_TOMBOL) {
                        $baris[] = [$this->tombolAksi($user, $konteks, $aksi, $sppgId, $j, mb_substr('Berangkat: '.$tujuan, 0, 40).' ('.$jam.')')];
                    }
                } catch (ValidationException $e) {
                    $isi[] = '- '.$this->e(sprintf('%s %s: %s', $jam, $tujuan, self::pesanValidasi($e)));
                }
            }

            if ($isi === []) {
                $isi[] = 'Tidak ada jadwal distribusi hari ini yang siap berangkat.';
            }

            return ['isi' => $isi, 'tombol' => $baris];
        });
    }

    // ------------------------------------------------------------------ langkah 1: layar konfirmasi

    /**
     * Layar konfirmasi (dipanggil setelah periksa() lolos dan token aksi dikonsumsi). Rincian dari data segar server;
     * menerbitkan grup token "Ya, lakukan" + "Batal" dengan versi yang sama dengan yang dilihat pengguna.
     */
    public function konfirmasi(User $user, BotAksi $token, int $chatId): BotLayar
    {
        $aksi = (string) self::aksiToken($token);
        $sppgId = (int) $token->sppg_id;

        return $this->dalamKonteks($user, $sppgId, function () use ($user, $token, $chatId, $aksi, $sppgId): BotLayar {
            $subjek = $this->muat($aksi, (int) $token->subjek_id);

            if ($subjek === null) {
                return $this->layarPesan($user, $chatId, $aksi, $sppgId, 'Data tidak ditemukan atau sudah tidak berlaku.');
            }

            $nama = (string) (Sppg::query()->withoutGlobalScopes()->whereKey($sppgId)->value('nama') ?? 'SPPG');
            $rute = null;

            try {
                [$judul, $rincian, $rute] = match ($aksi) {
                    BotAksi::JENIS_KIRIM_ULANG_REKAP => $this->rincianRekap($subjek),
                    BotAksi::JENIS_PICU_PERJALANAN => $this->rincianPerjalanan($user, $subjek),
                    BotAksi::JENIS_TINDAK_KELUHAN => $this->rincianKeluhan($subjek),
                };
            } catch (ValidationException $e) {
                return $this->layarPesan($user, $chatId, $aksi, $sppgId, self::pesanValidasi($e));
            }

            $grup = BotAksiService::grupBaru();
            $opsi = ['sppg_id' => $sppgId, 'subjek_tipe' => $token->subjek_tipe, 'subjek_id' => (int) $token->subjek_id, 'subjek_versi' => (int) $token->subjek_versi];
            $ya = $this->aksi->terbit($user, $chatId, self::AKSI[$aksi]['ya'], $opsi, $grup);
            $batal = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_BATAL_AKSI, $opsi, $grup);
            $baris = [[$this->aksi->tombol(self::LABEL_YA, $ya), $this->aksi->tombol(self::LABEL_BATAL, $batal)], ...($rute === null ? [] : $this->barisMiniApp($rute))];

            $teks = '<b>'.$this->e($judul).'</b>'."\nSPPG: ".$this->e($nama)."\n".implode("\n", array_map(fn (string $r) => $this->e($r), $rincian))
                ."\n\nTekan <b>".$this->e(self::LABEL_YA).'</b> untuk melanjutkan atau <b>Batal</b>. Tombol berlaku '
                .max(1, (int) config('telegram.callback_ttl_menit', 10)).' menit.';

            return new BotLayar($teks, ['inline_keyboard' => $baris], true, true);
        });
    }

    /**
     * @return array{0: string, 1: list<string>, 2: ?string}
     */
    private function rincianRekap(RekapHarian $rekap): array
    {
        if (Cache::has($this->kunciJedaRekap($rekap))) {
            throw ValidationException::withMessages(['rekap' => [$this->pesanJedaRekap()]]);
        }

        $status = $rekap->status_kirim?->label() ?? '-';
        $dikirim = $rekap->dikirim_pada === null ? '' : ' (dikirim '.self::waktu($rekap->dikirim_pada).')';

        return ['Konfirmasi: kirim ulang rekap', [
            'Rekap tanggal: '.$rekap->tanggal->format('d/m/Y'),
            'Status kirim saat ini: '.$status.$dikirim,
            'Rekap akan dikirim ulang ke penerima rekap (Kepala SPPG, Ahli Gizi, Akuntan) lewat Telegram.',
            'Pengiriman tidak dapat ditarik kembali.',
        ], null];
    }

    /**
     * @return array{0: string, 1: list<string>, 2: ?string}
     *
     * @throws ValidationException
     */
    private function rincianPerjalanan(User $user, JadwalDistribusi $jadwal): array
    {
        $this->validasiPerjalanan($user, $jadwal);
        $p = $this->trip->pratinjau($jadwal);
        $zona = $this->trip->zona();

        return ['Konfirmasi: mulai perjalanan', [
            'Tujuan: '.FormatLayar::nama($jadwal->titikLayanan?->nama),
            'Kendaraan: '.($jadwal->armada?->plat_nomor === null ? 'Mobil SPPG' : FormatLayar::nama($jadwal->armada->plat_nomor)),
            'Berangkat: sekarang ('.Carbon::instance($p['waktu_berangkat'])->setTimezone($zona)->format('H:i').' WIB)',
            sprintf('Estimasi tiba: %s WIB (%d menit, dihitung ulang saat berangkat)', Carbon::instance($p['estimasi_tiba'])->setTimezone($zona)->format('H:i'), $p['estimasi_menit']),
            'Notifikasi dikirim ke: '.($p['penerima'] === 'pic' ? 'PIC titik layanan (Telegram)' : 'Kepala SPPG (PIC titik layanan belum terhubung)'),
            'Notifikasi tidak dapat ditarik kembali.',
        ], null];
    }

    /**
     * Rincian keluhan TANPA teks keluhan/tindak lanjut (bisa memuat data pribadi): kategori, prioritas, titik, tanggal.
     *
     * @return array{0: string, 1: list<string>, 2: ?string}
     */
    private function rincianKeluhan(FeedbackKeluhan $k): array
    {
        return ['Konfirmasi: tindak lanjut keluhan', [
            sprintf('Keluhan #%d: %s, prioritas %s', $k->id, $k->kategori?->label() ?? '-', strtoupper((string) $k->prioritas?->value)),
            'Titik layanan: '.FormatLayar::nama($k->titikLayanan?->nama),
            'Dicatat: '.self::waktu($k->created_at),
            'Status: Baru -> Diproses',
            'Isi keluhan tidak ditampilkan di chat. Penyelesaian (selesai/ditolak) beserta catatan tindak lanjut dilakukan di Mini App.',
        ], '/keluhan/'.$k->id];
    }

    // ------------------------------------------------------------------ langkah 2: jalankan

    /**
     * Jalankan aksi (dipanggil setelah periksa() lolos dan token "Ya" dikonsumsi atomik). Di dalam transaksi: kunci baris
     * subjek, hitung ulang SPPG + Policy + versi + jendela, lalu SATU pemanggilan service. Penolakan aturan service
     * (mis. perjalanan ganda, mutu belum Layak) menjadi pesan ramah.
     *
     * @return array{hasil: string, layar: BotLayar}
     */
    public function jalankan(User $user, BotAksi $token, int $chatId): array
    {
        $aksi = (string) self::aksiToken($token);
        $sppgId = (int) $token->sppg_id;

        try {
            $hasil = AuditLog::withSource('bot', fn () => $this->dalamKonteks($user, $sppgId, fn () => DB::transaction(function () use ($user, $token, $aksi, $sppgId): array {
                $subjek = $this->muat($aksi, (int) $token->subjek_id, kunci: true);

                if ($subjek === null) {
                    return ['hasil' => 'tidak_ada'];
                }

                if ((int) $subjek->getAttribute('sppg_id') !== $sppgId || ! $this->izinkan($user, $aksi, $subjek)) {
                    return ['hasil' => 'ditolak_hak'];
                }

                if ($this->versi($aksi, $subjek) !== (int) $token->subjek_versi) {
                    return ['hasil' => 'konflik_versi'];
                }

                if (! $this->dalamJendela($aksi, $subjek)) {
                    return ['hasil' => 'di_luar_jendela'];
                }

                if ($this->sedangDikirim($aksi, $subjek)) {
                    return ['hasil' => self::SEDANG_DIKIRIM];
                }

                return match ($aksi) {
                    BotAksi::JENIS_KIRIM_ULANG_REKAP => $this->eksekusiRekap($user, $subjek),
                    BotAksi::JENIS_PICU_PERJALANAN => $this->eksekusiPerjalanan($user, $subjek),
                    BotAksi::JENIS_TINDAK_KELUHAN => $this->eksekusiKeluhan($user, $subjek),
                };
            })));
        } catch (ValidationException $e) {
            return ['hasil' => 'ditolak_aturan', 'layar' => $this->layarPesan($user, $chatId, $aksi, $sppgId, self::pesanValidasi($e))];
        } catch (AuthorizationException) {
            $hasil = ['hasil' => 'ditolak_hak'];
        } catch (Throwable $e) {
            BotGalat::catat($e, 'aksi_tulis');

            return ['hasil' => 'gagal', 'layar' => new BotLayar('Terjadi kesalahan saat memproses aksi. Periksa hasilnya di Mini App sebelum mencoba lagi.', null, false, true)];
        }

        // Hasil sudah final (transaksi selesai, efek mungkin sudah terjadi): galat saat menyusun layar hasil TIDAK boleh
        // dilaporkan sebagai "gagal" (temuan E2-4); pengguna diarahkan memeriksa Mini App, hasil dicatat sesuai efek.
        try {
            $layar = $this->layarHasil($user, $chatId, $aksi, $sppgId, $hasil);
        } catch (Throwable $e) {
            BotGalat::catat($e, 'aksi_tulis_layar');
            $layar = new BotLayar(self::PESAN_SUDAH_DIPROSES, null, false, true);
        }

        return ['hasil' => $hasil['hasil'], 'layar' => $layar];
    }

    /** @return array<string, mixed> */
    private function eksekusiRekap(User $user, RekapHarian $rekap): array
    {
        // Batas 1x per rekap per jeda (lintas pengguna): Cache::add atomik.
        $kunci = $this->kunciJedaRekap($rekap);

        if (! Cache::add($kunci, (int) $user->getKey(), max(1, (int) config('telegram.kirim_ulang_rekap_jeda_menit', 5)) * 60)) {
            return ['hasil' => 'dibatasi'];
        }

        $lama = $rekap->status_kirim?->value;

        try {
            $this->audit($user, 'bot_kirim_ulang_rekap', $rekap, ['status_kirim' => $lama], ['status_kirim' => 'menunggu']);
            $this->harian->kirimUlang($rekap);
        } catch (Throwable $e) {
            Cache::forget($kunci);

            throw $e;
        }

        return ['hasil' => 'dikirim_ulang', 'tanggal' => $rekap->tanggal->format('d/m/Y')];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function eksekusiPerjalanan(User $user, JadwalDistribusi $jadwal): array
    {
        $this->validasiPerjalanan($user, $jadwal);
        // Audit ditulis SETELAH baris disimpan tetapi SEBELUM PerjalananDimulai dipancarkan (E2-3): bila audit gagal,
        // tidak ada event/notifikasi yang terjadwal (tidak bergantung pada afterCommit/dedup jalur notifikasi).
        $n = $this->trip->mulai($user, $jadwal, setelahSimpan: fn (NotifikasiPerjalanan $baru) => $this->audit($user, 'bot_picu_perjalanan', $baru, [], [
            'jadwal_distribusi_id' => (int) $jadwal->id,
            'status_perjalanan' => $baru->status_perjalanan?->value,
            'dialihkan_ke_kepala' => (bool) $baru->dialihkan_ke_kepala,
        ]));

        return [
            'hasil' => 'dimulai',
            'tujuan' => FormatLayar::nama($jadwal->titikLayanan?->nama),
            'tiba' => Carbon::instance($n->estimasi_tiba)->setTimezone($this->trip->zona())->format('H:i'),
            'menit' => (int) $n->estimasi_menit,
            'pic' => ! $n->dialihkan_ke_kepala,
        ];
    }

    /** @return array<string, mixed> */
    private function eksekusiKeluhan(User $user, FeedbackKeluhan $k): array
    {
        $lama = $k->status?->value;
        $baru = $this->keluhan->perbarui($user, $k, ['status' => StatusKeluhan::Diproses->value]);
        $this->audit($user, 'bot_tindak_keluhan', $baru, ['status' => $lama], ['status' => $baru->status?->value]);

        return ['hasil' => 'diproses', 'id' => (int) $k->id];
    }

    // ------------------------------------------------------------------ layar hasil/pesan

    /** Layar setelah Batal: tanpa perubahan data. */
    public function layarBatal(User $user, BotAksi $token, int $chatId): BotLayar
    {
        $aksi = self::aksiToken($token);

        return new BotLayar('Dibatalkan. Tidak ada perubahan data.', ['inline_keyboard' => $this->kaki($user, $chatId, $aksi, (int) $token->sppg_id, 'Kembali')], true, true);
    }

    /** Layar "data sudah berubah" / "di luar jendela" untuk token basi (dari periksa()). */
    public function layarTolakKeadaan(User $user, BotAksi $token, int $chatId, string $alasan): BotLayar
    {
        $aksi = (string) self::aksiToken($token);

        return $this->layarPesan($user, $chatId, $aksi, (int) $token->sppg_id, match ($alasan) {
            self::BERUBAH => self::PESAN_BERUBAH,
            self::SEDANG_DIKIRIM => self::PESAN_SEDANG_DIKIRIM,
            default => $this->pesanJendela($aksi),
        });
    }

    /** @param array<string, mixed> $h */
    private function layarHasil(User $user, int $chatId, string $aksi, int $sppgId, array $h): BotLayar
    {
        $e = $this->e(...);

        return match ($h['hasil']) {
            'dikirim_ulang' => new BotLayar(
                "<b>Rekap dikirim ulang</b>\nRekap tanggal ".$e($h['tanggal']).' sedang dikirim ulang ke penerima rekap. Status kirim: Menunggu.',
                ['inline_keyboard' => $this->kaki($user, $chatId, $aksi, $sppgId, 'Kembali ke Rekap')], true, true,
            ),
            'dimulai' => new BotLayar(
                "<b>Perjalanan dimulai</b>\nTujuan: ".$e($h['tujuan'])."\nEstimasi tiba: ".$e($h['tiba']).' WIB ('.(int) $h['menit']." menit)\nNotifikasi: "
                .($h['pic'] ? 'dikirim ke PIC titik layanan.' : 'PIC titik layanan belum terhubung, dialihkan ke Kepala SPPG.'),
                ['inline_keyboard' => $this->kaki($user, $chatId, $aksi, $sppgId, 'Kembali')], true, true,
            ),
            'diproses' => new BotLayar(
                '<b>Keluhan #'.(int) $h['id']." kini Diproses</b>\nPenyelesaian (selesai/ditolak) beserta catatan tindak lanjut dilakukan di Mini App.",
                ['inline_keyboard' => [...$this->barisMiniApp('/keluhan/'.(int) $h['id']), ...$this->kaki($user, $chatId, $aksi, $sppgId, 'Kembali ke Keluhan')]], true, true,
            ),
            'konflik_versi' => $this->layarPesan($user, $chatId, $aksi, $sppgId, self::PESAN_BERUBAH),
            'di_luar_jendela' => $this->layarPesan($user, $chatId, $aksi, $sppgId, $this->pesanJendela($aksi)),
            'dibatasi' => $this->layarPesan($user, $chatId, $aksi, $sppgId, $this->pesanJedaRekap()),
            self::SEDANG_DIKIRIM => $this->layarPesan($user, $chatId, $aksi, $sppgId, self::PESAN_SEDANG_DIKIRIM),
            'tidak_ada' => $this->layarPesan($user, $chatId, $aksi, $sppgId, 'Data tidak ditemukan atau sudah tidak berlaku. Tidak ada perubahan yang dilakukan.'),
            default => new BotLayar('Anda tidak berhak melakukan aksi ini. Tidak ada perubahan yang dilakukan.', null, false, true),
        };
    }

    /** Pesan ramah + tombol "Muat ulang" (navigasi `n1` ke layar baca asal; hak dicek ulang saat diklik) + Beranda. */
    private function layarPesan(User $user, int $chatId, ?string $aksi, int $sppgId, string $pesan): BotLayar
    {
        return new BotLayar($this->e($pesan), ['inline_keyboard' => $this->kaki($user, $chatId, $aksi, $sppgId, 'Muat ulang')], true, true);
    }

    /** @return list<list<array<string, mixed>>> */
    private function kaki(User $user, int $chatId, ?string $aksi, int $sppgId, string $labelKembali): array
    {
        $konteks = new KonteksLayar($chatId, (int) $user->telegram_id);
        $baris = [];

        if ($aksi !== null) {
            $baris[] = $konteks->tombol($labelKembali, self::AKSI[$aksi]['layar'], $sppgId);
        }

        $baris[] = $konteks->tombol('Beranda', BotLayarRegistry::BERANDA, $sppgId);

        return [$baris];
    }

    private function pesanJendela(string $aksi): string
    {
        return match ($aksi) {
            BotAksi::JENIS_KIRIM_ULANG_REKAP => 'Kirim ulang lewat bot hanya untuk rekap hari ini dan kemarin. Gunakan Mini App untuk rekap lain.',
            BotAksi::JENIS_PICU_PERJALANAN => 'Perjalanan lewat bot hanya dapat dimulai untuk jadwal distribusi hari ini.',
            default => self::PESAN_BERUBAH,
        };
    }

    private function pesanJedaRekap(): string
    {
        return 'Rekap ini baru saja dikirim ulang. Coba lagi setelah '.max(1, (int) config('telegram.kirim_ulang_rekap_jeda_menit', 5)).' menit.';
    }

    // ------------------------------------------------------------------ util

    /**
     * @template T
     *
     * @param  Closure(): T  $f
     * @return T
     */
    private function dalamKonteks(User $user, int $sppgId, Closure $f): mixed
    {
        return ActingUser::run($user, fn () => app(SppgAktif::class)->untuk($user, $sppgId, $f));
    }

    private function muat(string $aksi, int $id, bool $kunci = false): RekapHarian|JadwalDistribusi|FeedbackKeluhan|null
    {
        $q = match ($aksi) {
            BotAksi::JENIS_KIRIM_ULANG_REKAP => RekapHarian::query(),
            BotAksi::JENIS_PICU_PERJALANAN => JadwalDistribusi::query()->with(['titikLayanan', 'armada', 'jadwalProduksi']),
            BotAksi::JENIS_TINDAK_KELUHAN => FeedbackKeluhan::query()->with('titikLayanan'),
        };

        $q->withoutGlobalScope(SppgScope::class)->whereKey($id);

        if ($kunci) {
            $q->lockForUpdate();
        }

        /** @var RekapHarian|JadwalDistribusi|FeedbackKeluhan|null */
        return $q->first();
    }

    /** Policy di dalam konteks ActingUser + SPPG (pemanggil sudah masuk dalamKonteks). */
    private function izinkan(User $user, string $aksi, Model $subjek): bool
    {
        $gate = Gate::forUser($user);

        return match ($aksi) {
            BotAksi::JENIS_KIRIM_ULANG_REKAP, BotAksi::JENIS_TINDAK_KELUHAN => $gate->allows('update', $subjek),
            BotAksi::JENIS_PICU_PERJALANAN => $gate->allows('create', NotifikasiPerjalanan::class) && $gate->allows('view', $subjek),
            default => false,
        };
    }

    private function dalamJendela(string $aksi, Model $subjek): bool
    {
        return match ($aksi) {
            BotAksi::JENIS_KIRIM_ULANG_REKAP => in_array($subjek->tanggal?->format('Y-m-d'), $this->jendelaRekap(), true),
            BotAksi::JENIS_PICU_PERJALANAN => $subjek->tanggal_distribusi?->format('Y-m-d') === $this->hariIni()->toDateString(),
            default => true,
        };
    }

    /**
     * Sidik keadaan subjek yang DILIHAT pengguna (disimpan di bot_aksi.subjek_versi). Model tanpa kolom versi:
     * gabungan status + stempel waktu + keadaan yang ditampilkan di konfirmasi (penerima PIC, perjalanan aktif, mutu).
     */
    private function versi(string $aksi, Model $s): int
    {
        $ts = fn (mixed $v): string => $v instanceof CarbonInterface ? (string) $v->getTimestamp() : (string) $v;

        $bagian = match ($aksi) {
            BotAksi::JENIS_KIRIM_ULANG_REKAP => [$s->status_kirim?->value, $ts($s->dikirim_pada), $ts($s->disusun_pada), $ts($s->updated_at), $s->tanggal?->format('Y-m-d')],
            BotAksi::JENIS_PICU_PERJALANAN => [
                $s->status?->value, $s->tanggal_distribusi?->format('Y-m-d'), $s->titik_layanan_id, $s->armada_id, $ts($s->updated_at),
                $s->titikLayanan?->picTerhubung(), $ts($s->titikLayanan?->updated_at), $s->jadwalProduksi?->isLayakDistribusi(),
                // Semua perjalanan jadwal ini (status apa pun): mulai-lalu-batal di antara konfirmasi dan "Ya" mengubah sidik (E2-1).
                ...$this->sidikPerjalanan((int) $s->getKey()),
            ],
            BotAksi::JENIS_TINDAK_KELUHAN => [$s->status?->value, $s->prioritas?->value, $ts($s->updated_at)],
            default => [],
        };

        return (int) sprintf('%u', crc32($aksi.'|'.json_encode($bagian)));
    }

    /** @return list<int|string|null> jumlah, id terbesar, dan updated_at terbaru semua perjalanan jadwal */
    private function sidikPerjalanan(int $jadwalId): array
    {
        $q = NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)->where('jadwal_distribusi_id', $jadwalId);
        $aktif = (clone $q)->aktif()->count();
        $baris = $q->toBase()->selectRaw('count(*) as n, max(id) as m, max(updated_at) as u')->first();

        return [$aktif, (int) ($baris->n ?? 0), (int) ($baris->m ?? 0), (string) ($baris->u ?? '')];
    }

    /** Rekap masih "menunggu" (sedang/akan dikirim) -> kirim ulang lewat bot ditolak; REST tidak terpengaruh. */
    private function sedangDikirim(string $aksi, Model $subjek): bool
    {
        return $aksi === BotAksi::JENIS_KIRIM_ULANG_REKAP && $subjek->status_kirim === StatusKirim::Menunggu;
    }

    /**
     * Validasi setara POST /notifikasi-perjalanan: aturan StoreNotifikasiPerjalananRequest (jadwal milik SPPG pengguna,
     * belum dihapus; tanpa waktu/estimasi manual) + aturan keberangkatan service (status siap, mutu Layak, tanpa perjalanan aktif).
     *
     * @throws ValidationException
     */
    private function validasiPerjalanan(User $user, JadwalDistribusi $jadwal): void
    {
        $request = StoreNotifikasiPerjalananRequest::create('/', 'POST');
        $request->setUserResolver(fn () => $user);
        Validator::make(['jadwal_distribusi_id' => (int) $jadwal->id], $request->rules(), $request->messages())->validate();
        $this->trip->pastikanBisaBerangkat($jadwal);
    }

    /**
     * Tombol aksi `b1` dengan versi keadaan saat ini (dipakai ulang selama subjek/versi sama, agar Segarkan tidak menumpuk token).
     *
     * @return array{text: string, callback_data: string}
     */
    private function tombolAksi(User $user, KonteksLayar $konteks, string $aksi, int $sppgId, Model $subjek, string $label): array
    {
        $token = $this->aksi->terbitAtauPakai($user, $konteks->chatId, $aksi, [
            'sppg_id' => $sppgId,
            'subjek_tipe' => self::AKSI[$aksi]['subjek'],
            'subjek_id' => (int) $subjek->getKey(),
            'subjek_versi' => $this->versi($aksi, $subjek),
        ]);

        return $this->aksi->tombol($label, $token);
    }

    /** @return array{0: string, 1: string} [hari ini, kemarin] (Y-m-d, zona operasional) */
    private function jendelaRekap(): array
    {
        $hari = $this->hariIni();

        return [$hari->toDateString(), $hari->copy()->subDay()->toDateString()];
    }

    private function hariIni(): Carbon
    {
        return Carbon::now((string) config('operasional.timezone', 'Asia/Jakarta'))->startOfDay();
    }

    private function kunciJedaRekap(RekapHarian $rekap): string
    {
        return 'bot:kirim-ulang-rekap:'.(int) $rekap->getKey();
    }

    /** @return list<list<array<string, mixed>>> baris tombol web_app "Buka di Mini App" (kosong bila URL Mini App bukan HTTPS) */
    private function barisMiniApp(string $rute): array
    {
        $tombol = TelegramBotService::webAppButton('Buka di Mini App', $this->bot->miniAppUrl(['r' => $rute], $rute));

        return $tombol === null ? [] : [[$tombol]];
    }

    /**
     * Jejak audit aksi bot atas nama pengguna (sumber `bot`). Nilai dibangun server (status/id saja): tanpa teks keluhan,
     * pesan perjalanan, chat id PIC, nama, atau kontak. Model ini tidak memakai trait Auditable, jadi baris ditulis eksplisit.
     *
     * @param  array<string, mixed>  $lama
     * @param  array<string, mixed>  $baru
     */
    private function audit(User $user, string $event, Model $subjek, array $lama, array $baru): void
    {
        $log = new AuditLog;
        $log->forceFill([
            'sppg_id' => (int) $subjek->getAttribute('sppg_id'),
            'user_id' => (int) $user->getKey(),
            'event' => $event,
            'auditable_type' => $subjek->getMorphClass(),
            'auditable_id' => (int) $subjek->getKey(),
            'old_values' => $lama === [] ? null : $lama,
            'new_values' => $baru === [] ? null : $baru,
            'ip' => null,
            'user_agent' => null,
            'sumber' => 'bot',
        ])->save();
    }

    /** Waktu WIB 'd/m H:i' (teks biasa). */
    private static function waktu(?CarbonInterface $t): string
    {
        return $t === null ? '-' : Carbon::instance($t)->setTimezone(FormatLayar::zona())->format('d/m H:i');
    }

    /** Pesan validasi pertama (teks tetap dari server, bukan input pengguna). */
    private static function pesanValidasi(ValidationException $e): string
    {
        $pesan = collect($e->errors())->flatten()->first();

        return is_string($pesan) && $pesan !== '' ? $pesan : 'Aksi tidak dapat diproses oleh aturan sistem.';
    }

    private function e(mixed $v): string
    {
        return TelegramBotService::escapeHtml((string) $v);
    }
}

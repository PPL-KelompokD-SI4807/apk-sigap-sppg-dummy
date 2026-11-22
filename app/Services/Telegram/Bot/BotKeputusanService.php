<?php

namespace App\Services\Telegram\Bot;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPengajuanMenu;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Http\Requests\Keuangan\KeputusanPengeluaranRequest;
use App\Http\Requests\Persetujuan\KeputusanPengajuanRequest;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Keuangan\BudgetService;
use App\Services\Persetujuan\PersetujuanMenuService;
use App\Services\Persetujuan\PersetujuanPengeluaranService;
use App\Services\Telegram\TelegramBotService;
use App\Support\Rbac;
use App\Support\SppgAktif;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Alur keputusan investor lewat Bot (R-02): daftar -> (pilih SPPG) -> tinjau -> setuju / tolak (+ catatan).
 *
 * - Semua hak dihitung ulang di sini (periksa()): Rbac::canDecide, pivot investor_sppg, SPPG record == SPPG token,
 *   Policy `putuskan` di dalam SppgAktif::untuk(). callback_data tidak pernah dipercaya untuk hak.
 * - Keputusan memakai PersetujuanMenuService / PersetujuanPengeluaranService yang sama dengan API (kunci baris +
 *   versi + 409); versi yang dikirim adalah versi yang DILIHAT pengguna (baris bot_aksi), bukan dari klien.
 * - Layar hanya memuat ringkasan yang sudah ada di notifikasi (tanpa uraian, nota, nama penerima, telepon, NIK).
 * - Catatan penolakan tidak disimpan di bot_aksi/log dan tidak pernah dikirim ke Agen AI.
 */
class BotKeputusanService
{
    public const MAKS_DAFTAR = 5;

    public const TIDAK_BERHAK = 'tidak_berhak';

    public const TIDAK_ADA = 'tidak_ada';

    public function __construct(
        private readonly BotAksiService $aksi,
        private readonly TelegramBotService $bot,
        private readonly BudgetService $budget,
        private readonly PersetujuanMenuService $menu,
        private readonly PersetujuanPengeluaranService $pengeluaran,
    ) {}

    /** Pengguna berhak memutuskan minimal satu jenis pengajuan (investor). */
    public function bisaMemutuskan(User $user): bool
    {
        return Rbac::canDecide($user, 'persetujuan-menu') || Rbac::canDecide($user, 'keuangan');
    }

    /**
     * Hitung ulang hak pengguna atas aksi ini. null = boleh; selain itu TIDAK_BERHAK / TIDAK_ADA.
     * Tidak mengubah data apa pun dan tidak menghabiskan token.
     */
    public function periksa(User $user, BotAksi $aksi): ?string
    {
        app(SppgAktif::class)->segarkanMilik($user);
        $milik = app(SppgAktif::class)->milik($user);
        $sppgId = $aksi->sppg_id === null ? null : (int) $aksi->sppg_id;

        switch ($aksi->jenis) {
            case BotAksi::JENIS_DAFTAR:
                return $this->bisaMemutuskan($user) && ($sppgId === null || in_array($sppgId, $milik, true)) ? null : self::TIDAK_BERHAK;

            case BotAksi::JENIS_PILIH_SPPG:
                return $this->bisaMemutuskan($user) && $sppgId !== null && in_array($sppgId, $milik, true) ? null : self::TIDAK_BERHAK;

            case BotAksi::JENIS_TINJAU:
            case BotAksi::JENIS_SETUJU:
            case BotAksi::JENIS_TOLAK:
                $modul = self::modul($aksi->subjek_tipe);

                if ($modul === null || ! Rbac::canDecide($user, $modul) || $sppgId === null || ! in_array($sppgId, $milik, true)) {
                    return self::TIDAK_BERHAK;
                }

                $subjek = $this->muatSubjek($aksi);

                if ($subjek === null) {
                    return self::TIDAK_ADA;
                }

                if ((int) $subjek->sppg_id !== $sppgId) {
                    return self::TIDAK_BERHAK;
                }

                return app(SppgAktif::class)->untuk($user, $sppgId, fn () => Gate::forUser($user)->allows('putuskan', $subjek))
                    ? null
                    : self::TIDAK_BERHAK;
        }

        return self::TIDAK_BERHAK;
    }

    // ------------------------------------------------------------------ layar

    /** Layar awal "Menunggu keputusan": 1 SPPG -> daftar; >1 -> pemilih SPPG; $sppgId eksplisit -> daftar. */
    public function mulai(User $user, int $chatId, ?int $sppgId): BotLayar
    {
        if ($sppgId !== null) {
            return $this->daftar($user, $chatId, $sppgId);
        }

        $milik = app(SppgAktif::class)->milik($user);

        return match (count($milik)) {
            0 => new BotLayar('Akun Anda belum terhubung ke SPPG mana pun. Hubungi Admin.', null, false),
            1 => $this->daftar($user, $chatId, $milik[0]),
            default => $this->pemilihSppg($user, $chatId, $milik),
        };
    }

    /** @param list<int> $milik */
    private function pemilihSppg(User $user, int $chatId, array $milik): BotLayar
    {
        $grup = BotAksiService::grupBaru();
        $baris = [];

        $sppgs = Sppg::query()->withoutGlobalScopes()->whereIn('id', array_slice($milik, 0, 20))->orderBy('nama')->get(['id', 'nama']);

        foreach ($sppgs as $sppg) {
            $aksi = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_PILIH_SPPG, ['sppg_id' => (int) $sppg->id], $grup);
            $baris[] = [$this->aksi->tombol((string) $sppg->nama, $aksi)];
        }

        return new BotLayar("<b>Menunggu keputusan</b>\nPilih SPPG yang ingin ditinjau.", ['inline_keyboard' => $baris], true, true);
    }

    public function daftar(User $user, int $chatId, int $sppgId): BotLayar
    {
        return app(SppgAktif::class)->untuk($user, $sppgId, function () use ($user, $chatId, $sppgId): BotLayar {
            $grup = BotAksiService::grupBaru();
            $e = TelegramBotService::escapeHtml(...);
            $nama = (string) (Sppg::query()->withoutGlobalScopes()->whereKey($sppgId)->value('nama') ?? 'SPPG');
            $teks = ["<b>Menunggu keputusan</b>\nSPPG: <b>".$e($nama).'</b>'];
            $baris = [];
            $ada = false;

            if (Rbac::canDecide($user, 'persetujuan-menu')) {
                $dasar = PengajuanMenu::query()->withoutGlobalScope(SppgScope::class)
                    ->where('sppg_id', $sppgId)->where('status', StatusPengajuanMenu::Diajukan->value);
                $total = (clone $dasar)->count();
                $items = (clone $dasar)->orderBy('diajukan_pada')->orderBy('id')->limit(self::MAKS_DAFTAR)->get();

                if ($total > 0) {
                    $ada = true;
                    $teks[] = "\n<b>Menu ({$total})</b>";
                }

                foreach ($items as $i => $p) {
                    $teks[] = sprintf('%d. %s - Rp %s per porsi, total Rp %s', $i + 1, $e($this->namaMenu($p)), self::rp($p->biaya_per_porsi), self::rp($p->total_biaya));
                    $aksi = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_TINJAU, [
                        'sppg_id' => $sppgId, 'subjek_tipe' => BotAksi::SUBJEK_MENU, 'subjek_id' => (int) $p->id, 'subjek_versi' => (int) $p->menu_versi,
                    ], $grup);
                    $baris[] = [$this->aksi->tombol('Tinjau menu: '.mb_substr($this->namaMenu($p), 0, 40), $aksi)];
                }

                if ($total > $items->count()) {
                    $teks[] = '... dan '.($total - $items->count()).' lainnya (buka di Mini App).';
                }
            }

            if (Rbac::canDecide($user, 'keuangan')) {
                $dasar = Pengeluaran::query()->withoutGlobalScope(SppgScope::class)
                    ->where('sppg_id', $sppgId)->where('status_persetujuan', StatusPersetujuanPengeluaran::Diajukan->value);
                $total = (clone $dasar)->count();
                $items = (clone $dasar)->orderBy('tanggal')->orderBy('id')->limit(self::MAKS_DAFTAR)->get();

                if ($total > 0) {
                    $ada = true;
                    $teks[] = "\n<b>Pengeluaran ({$total})</b>";
                }

                foreach ($items as $i => $x) {
                    $teks[] = sprintf('%d. %s Rp %s (%s)', $i + 1, $e(self::labelKategori($x)), self::rp($x->nominal), $x->tanggal->toDateString());
                    $aksi = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_TINJAU, [
                        'sppg_id' => $sppgId, 'subjek_tipe' => BotAksi::SUBJEK_PENGELUARAN, 'subjek_id' => (int) $x->id, 'subjek_versi' => (int) $x->versi,
                    ], $grup);
                    $baris[] = [$this->aksi->tombol('Tinjau pengeluaran: '.mb_substr(self::labelKategori($x), 0, 20).' Rp '.self::rp($x->nominal), $aksi)];
                }

                if ($total > $items->count()) {
                    $teks[] = '... dan '.($total - $items->count()).' lainnya (buka di Mini App).';
                }
            }

            if (! $ada) {
                $teks[] = "\nTidak ada pengajuan yang menunggu keputusan.";
            }

            $buka = TelegramBotService::webAppButton('Buka SiGAP', $this->bot->miniAppUrl(['sppg' => $sppgId]));

            if ($buka !== null) {
                $baris[] = [$buka];
            }

            return new BotLayar(implode("\n", $teks), $baris === [] ? null : ['inline_keyboard' => $baris], true, true);
        });
    }

    /** Layar tinjau (data segar dari server). Dipanggil setelah periksa() lolos. */
    public function tinjau(User $user, int $chatId, BotAksi $aksi): BotLayar
    {
        $sppgId = (int) $aksi->sppg_id;

        return app(SppgAktif::class)->untuk($user, $sppgId, function () use ($user, $chatId, $aksi, $sppgId): BotLayar {
            $subjek = $this->muatSubjek($aksi);

            if ($subjek === null) {
                return $this->layarMuatUlang($user, $chatId, $sppgId, 'Pengajuan tidak ditemukan atau sudah tidak berlaku.');
            }

            $e = TelegramBotService::escapeHtml(...);
            $nama = (string) (Sppg::query()->withoutGlobalScopes()->whereKey($sppgId)->value('nama') ?? 'SPPG');

            if ($subjek instanceof PengajuanMenu) {
                $versi = (int) $subjek->menu_versi;
                $menunggu = $subjek->status === StatusPengajuanMenu::Diajukan;
                $teks = sprintf(
                    "<b>Menu menunggu persetujuan</b>\n%s\nBiaya per porsi Rp %s x %s porsi = total Rp %s.\nSPPG: %s",
                    $e($this->namaMenu($subjek)), self::rp($subjek->biaya_per_porsi), self::rp($subjek->jumlah_porsi), self::rp($subjek->total_biaya), $e($nama),
                );
                $rute = '/persetujuan-menu/'.$subjek->id;
            } else {
                $versi = (int) $subjek->versi;
                $menunggu = $subjek->status_persetujuan === StatusPersetujuanPengeluaran::Diajukan;
                $periode = $this->budget->periodeDari($subjek->tanggal);
                $r = $this->budget->ringkasan($sppgId, $periode);
                $serapan = $r['anggaran'] > 0
                    ? sprintf('Periode %s: serapan %s%% (Rp %s dari Rp %s), sisa Rp %s.', $e($periode), self::rp(round($r['terpakai'] * 100 / $r['anggaran'], 2), 2), self::rp($r['terpakai']), self::rp($r['anggaran']), self::rp($r['anggaran'] - $r['terpakai']))
                    : sprintf('Periode %s: belum ada dana yang dicairkan.', $e($periode));
                $teks = sprintf(
                    "<b>Pengeluaran menunggu persetujuan</b>\n%s Rp %s (%s).\n%s\nSPPG: %s",
                    $e(self::labelKategori($subjek)), self::rp($subjek->nominal), $subjek->tanggal->toDateString(), $serapan, $e($nama),
                );
                $rute = '/keuangan/pengeluaran/'.$subjek->id;
            }

            if (! $menunggu) {
                return $this->layarMuatUlang($user, $chatId, $sppgId, 'Pengajuan ini sudah diputuskan atau dibatalkan.');
            }

            if ($versi !== (int) $aksi->subjek_versi) {
                return $this->layarMuatUlang($user, $chatId, $sppgId, 'Data berubah sejak daftar ditampilkan. Muat ulang untuk melihat data terbaru.');
            }

            $grup = BotAksiService::grupBaru();
            $opsi = ['sppg_id' => $sppgId, 'subjek_tipe' => $aksi->subjek_tipe, 'subjek_id' => (int) $aksi->subjek_id, 'subjek_versi' => $versi];
            $setuju = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_SETUJU, $opsi, $grup);
            $tolak = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_TOLAK, $opsi, $grup);
            $baris = [[$this->aksi->tombol('Setujui', $setuju), $this->aksi->tombol('Tolak', $tolak)]];
            $buka = TelegramBotService::webAppButton('Buka di Mini App', $this->bot->miniAppUrl(['r' => $rute, 'sppg' => $sppgId], $rute));

            if ($buka !== null) {
                $baris[] = [$buka];
            }

            return new BotLayar($teks."\n\nTekan Setujui atau Tolak. Penolakan wajib disertai alasan.", ['inline_keyboard' => $baris], true, true);
        });
    }

    // ------------------------------------------------------------------ keputusan

    /**
     * Panggil service keputusan yang sama dengan API. Dipanggil SETELAH token dikonsumsi.
     *
     * @return array{hasil: string, layar: BotLayar}
     */
    public function putuskan(User $user, BotAksi $aksi, string $keputusan, ?string $catatan, int $chatId): array
    {
        $sppgId = (int) $aksi->sppg_id;

        try {
            // ActingUser: SppgScope/Auditable berlaku seperti permintaan Mini App (audit mencatat user_id pemutus).
            $subjek = AuditLog::withSource('bot', fn () => ActingUser::run($user, fn () => app(SppgAktif::class)->untuk($user, $sppgId, function () use ($user, $aksi, $keputusan, $catatan) {
                // Pivot dibaca segar di dalam jalur keputusan (bisa dicabut sejak periksa()).
                app(SppgAktif::class)->segarkanMilik($user);
                $versi = (int) $aksi->subjek_versi;

                if ($aksi->subjek_tipe === BotAksi::SUBJEK_MENU) {
                    $p = PengajuanMenu::query()->withoutGlobalScope(SppgScope::class)->findOrFail($aksi->subjek_id);

                    return $this->menu->putuskan($user, $p, $keputusan, $catatan, $versi);
                }

                $x = Pengeluaran::query()->withoutGlobalScope(SppgScope::class)->findOrFail($aksi->subjek_id);

                return $this->pengeluaran->putuskan($user, $x, $keputusan, $catatan, $versi);
            })));
        } catch (HttpResponseException $e) {
            $status = $e->getResponse()->getStatusCode();
            $alasan = (string) (json_decode((string) $e->getResponse()->getContent(), true)['alasan'] ?? '');

            if ($status === 409) {
                return [
                    'hasil' => str_contains($alasan, 'versi') ? 'konflik_versi' : 'konflik_status',
                    'layar' => $this->layarMuatUlang($user, $chatId, $sppgId, 'Keputusan tidak diproses: pengajuan sudah diputuskan atau berubah sejak Anda melihatnya. Muat ulang untuk data terbaru.'),
                ];
            }

            return [
                'hasil' => 'ditolak_aturan',
                'layar' => $this->layarMuatUlang($user, $chatId, $sppgId, 'Keputusan tidak dapat diproses oleh aturan sistem. Periksa pengajuan ini di Mini App.'),
            ];
        } catch (ModelNotFoundException) {
            return ['hasil' => 'tidak_ada', 'layar' => $this->layarMuatUlang($user, $chatId, $sppgId, 'Pengajuan tidak ditemukan atau sudah tidak berlaku.')];
        } catch (AuthorizationException) {
            return ['hasil' => 'ditolak_hak', 'layar' => new BotLayar('Anda tidak berhak memutuskan pengajuan ini.', null, false)];
        } catch (HttpExceptionInterface $e) {
            return $e->getStatusCode() === 403
                ? ['hasil' => 'ditolak_hak', 'layar' => new BotLayar('Anda tidak berhak memutuskan pengajuan ini.', null, false)]
                : ['hasil' => 'tidak_ada', 'layar' => $this->layarMuatUlang($user, $chatId, $sppgId, 'Pengajuan tidak ditemukan atau sudah tidak berlaku.')];
        } catch (Throwable $e) {
            BotGalat::catat($e, 'putuskan');

            return ['hasil' => 'gagal', 'layar' => new BotLayar('Terjadi kesalahan saat memproses keputusan. Data tidak diubah. Coba lagi atau gunakan Mini App.', null, false)];
        }

        $kata = $keputusan === 'disetujui' ? 'DISETUJUI' : 'DITOLAK';
        $e = TelegramBotService::escapeHtml(...);

        if ($subjek instanceof Pengeluaran) {
            $teks = sprintf('<b>Pengeluaran %s</b>'."\n".'%s Rp %s (%s).', $kata, $e(self::labelKategori($subjek)), self::rp($subjek->nominal), $subjek->tanggal->toDateString());
            $rute = '/keuangan/pengeluaran/'.$subjek->id;
        } else {
            $teks = sprintf('<b>Menu %s</b>'."\n".'%s.', $kata, $e($this->namaMenu($subjek)));
            $rute = '/persetujuan-menu/'.$subjek->id;
        }

        $lagi = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_DAFTAR, ['sppg_id' => $sppgId]);
        $baris = [[$this->aksi->tombol('Kembali ke daftar', $lagi)]];
        $buka = TelegramBotService::webAppButton('Buka di Mini App', $this->bot->miniAppUrl(['r' => $rute, 'sppg' => $sppgId], $rute));

        if ($buka !== null) {
            $baris[] = [$buka];
        }

        return ['hasil' => $keputusan, 'layar' => new BotLayar($teks, ['inline_keyboard' => $baris], true, true)];
    }

    /**
     * Validasi catatan penolakan dengan aturan yang SAMA dengan Form Request (min/maks per jenis).
     *
     * @return array{ok: bool, catatan?: string, pesan?: string}
     */
    public function validasiCatatan(string $subjekTipe, string $teks): array
    {
        [$min, $maks] = self::batasCatatan($subjekTipe);
        $teks = trim($teks);
        $v = Validator::make(['catatan' => $teks], ['catatan' => ['required', 'string', 'min:'.$min, 'max:'.$maks]]);

        return $v->fails()
            ? ['ok' => false, 'pesan' => "Alasan penolakan harus {$min}-{$maks} karakter. Balas lagi pesan permintaan alasan di atas, atau ketik /batal."]
            : ['ok' => true, 'catatan' => $teks];
    }

    /** @return array{0: int, 1: int} */
    public static function batasCatatan(?string $subjekTipe): array
    {
        return $subjekTipe === BotAksi::SUBJEK_PENGELUARAN
            ? [KeputusanPengeluaranRequest::CATATAN_MIN, KeputusanPengeluaranRequest::CATATAN_MAX]
            : [KeputusanPengajuanRequest::CATATAN_MIN, KeputusanPengajuanRequest::CATATAN_MAX];
    }

    /** Layar pesan + tombol "Muat ulang" (token daftar baru untuk SPPG yang sama). */
    public function layarMuatUlang(User $user, int $chatId, int $sppgId, string $pesan): BotLayar
    {
        $aksi = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_DAFTAR, ['sppg_id' => $sppgId]);

        return new BotLayar(TelegramBotService::escapeHtml($pesan), ['inline_keyboard' => [[$this->aksi->tombol('Muat ulang', $aksi)]]], true, true);
    }

    // ------------------------------------------------------------------ util

    public static function modul(?string $subjekTipe): ?string
    {
        return match ($subjekTipe) {
            BotAksi::SUBJEK_MENU => 'persetujuan-menu',
            BotAksi::SUBJEK_PENGELUARAN => 'keuangan',
            default => null,
        };
    }

    private function muatSubjek(BotAksi $aksi): PengajuanMenu|Pengeluaran|null
    {
        if ($aksi->subjek_id === null) {
            return null;
        }

        return match ($aksi->subjek_tipe) {
            BotAksi::SUBJEK_MENU => PengajuanMenu::query()->withoutGlobalScope(SppgScope::class)->find($aksi->subjek_id),
            BotAksi::SUBJEK_PENGELUARAN => Pengeluaran::query()->withoutGlobalScope(SppgScope::class)->find($aksi->subjek_id),
            default => null,
        };
    }

    private function namaMenu(PengajuanMenu $p): string
    {
        $nama = $p->rincian['menu']['nama_menu'] ?? null;

        return is_string($nama) && $nama !== '' ? mb_substr($nama, 0, 120) : 'Menu';
    }

    private static function labelKategori(Pengeluaran $x): string
    {
        return $x->kategori instanceof KategoriPengeluaran ? $x->kategori->label() : (string) $x->kategori;
    }

    /** Angka gaya Indonesia: 43750000.00 -> 43.750.000. */
    private static function rp(mixed $nilai, int $desimal = 0): string
    {
        $f = is_numeric($nilai) ? (float) $nilai : 0.0;

        return number_format($f, floor($f) === $f ? 0 : $desimal, ',', '.');
    }
}

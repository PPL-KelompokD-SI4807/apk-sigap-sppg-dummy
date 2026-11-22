<?php

namespace App\Services\Telegram\Bot;

use App\Enums\Role;
use App\Enums\StatusPengajuanMenu;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Models\LogProduksi;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Keuangan\BudgetService;
use App\Services\Rekap\DailySummaryService;
use App\Services\Telegram\TelegramBotService;
use App\Support\Rbac;
use App\Support\SppgAktif;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ringkasan hari ini untuk beranda/sambutan Bot (R-02 v2, bagian 3.10), disaring per peran.
 *
 * - Memakai service yang sudah ada (DailySummaryService::hitung, BudgetService::ringkasan) dan query hitungan
 *   untuk antrean persetujuan; semua dengan sppg_id EKSPLISIT (pemanggil sudah memverifikasi SPPG pengguna).
 * - Tiap bagian hanya disusun bila peran berhak (Rbac::can R, bukan self-only; investor: canDecide). Relawan
 *   hanya melihat jadwal unitnya dan jumlah log hari ini miliknya.
 * - Privasi (3.5): HANYA hitungan, persentase, dan status. Tanpa nama orang, kontak, uraian, atau data anak.
 * - Cache `telegram.ringkasan_cache_detik` (bawaan 60 dtk; 0 = mati) per (SPPG, tanggal, PROFIL peran) dan
 *   hanya berisi baris agregat hasil saringan peran itu; tidak pernah dibagi lintas peran. Baris milik
 *   pengguna sendiri (log relawan) tidak di-cache.
 * Keluaran: daftar baris teks HTML yang sudah di-escape.
 */
class BotRingkasanService
{
    public function __construct(
        private readonly DailySummaryService $harian,
        private readonly BudgetService $anggaran,
    ) {}

    /** @return list<string> */
    public function untuk(User $user, int $sppgId): array
    {
        $ttl = (int) config('telegram.ringkasan_cache_detik', 60);

        // Admin baca-saja berjalan di transaksi yang di-rollback: cache bertipe database ikut batal dan tidak boleh
        // ditulis dari sana, jadi dihitung langsung (hitungan ringan; tanpa cache).
        if (app(SppgAktif::class)->sppgBacaAdmin($user) !== null) {
            $ttl = 0;
        }

        $susun = fn (): array => $this->susun($user, $sppgId);

        $umum = $ttl <= 0
            ? $susun()
            : Cache::remember(
                'bot:ringkasan:v1:'.$sppgId.':'.$this->harian->hariIni()->toDateString().':'.(BotLayarRegistry::profil($user) ?? '-'),
                $ttl,
                $susun,
            );

        return [...$umum, ...$this->milikSaya($user, $sppgId)];
    }

    /** @return list<string> */
    private function susun(User $user, int $sppgId): array
    {
        $baris = [];
        $hari = null;
        $ambil = function () use (&$hari, $sppgId): array {
            return $hari ??= $this->harian->hitung($sppgId);
        };

        if (self::bisaBaca($user, 'jadwal')) {
            $skop = Rbac::unitScope($user, 'jadwal');
            $h = $ambil();

            if ($skop === null || $skop === 'produksi') {
                $baris[] = sprintf('Jadwal produksi: %s (target %s porsi)', self::n($h['porsi']['jumlah_jadwal']), self::n($h['porsi']['target']));
            }

            if ($skop === null || $skop === 'distribusi') {
                $baris[] = sprintf('Jadwal distribusi: %s (tiba %s)', self::n($h['distribusi']['total']), self::n($h['distribusi']['tiba']));
            }
        }

        // Relawan hanya melihat jadwal unitnya (+ log miliknya, baris terpisah): tanpa hitungan keluhan/stok seluruh SPPG.
        if ($user->role === Role::Relawan) {
            return array_map(TelegramBotService::escapeHtml(...), $baris);
        }

        if (self::bisaBaca($user, 'bahan-baku')) {
            $s = $ambil()['stok'];
            $baris[] = sprintf('Stok: %s menipis, %s segera kedaluwarsa, %s sudah kedaluwarsa', self::n($s['menipis']), self::n($s['akan_kedaluwarsa']), self::n($s['sudah_kedaluwarsa']));
        }

        if (self::bisaBaca($user, 'keluhan')) {
            $k = $ambil()['keluhan'];
            $baris[] = sprintf('Keluhan terbuka: %s (kritis baru hari ini: %s)', self::n($k['terbuka']), self::n($k['kritis']));
        }

        if (self::bisaBaca($user, 'keuangan')) {
            $baris[] = $this->barisAnggaran($sppgId);
        }

        $antre = [];

        if (Rbac::canDecide($user, 'persetujuan-menu')) {
            $antre[] = 'menu '.self::n(PengajuanMenu::query()->withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $sppgId)->where('status', StatusPengajuanMenu::Diajukan->value)->count());
        }

        if (Rbac::canDecide($user, 'keuangan')) {
            $antre[] = 'pengeluaran '.self::n(Pengeluaran::query()->withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $sppgId)->where('status_persetujuan', StatusPersetujuanPengeluaran::Diajukan->value)->count());
        }

        if ($antre !== []) {
            $baris[] = 'Menunggu keputusan: '.implode(', ', $antre);
        }

        return array_map(TelegramBotService::escapeHtml(...), $baris);
    }

    private function barisAnggaran(int $sppgId): string
    {
        $r = $this->anggaran->ringkasan($sppgId, $this->anggaran->periodeBerjalan());
        $ambang = (int) config('operasional.keuangan.ambang_peringatan_persen', 80);

        $status = match ($r['status']) {
            'peringatan' => 'PERINGATAN, mencapai '.$ambang.'%',
            'habis' => 'HABIS',
            'melampaui' => 'MELEBIHI anggaran',
            'belum_ada_anggaran' => 'belum ada pencairan dana',
            default => 'aman',
        };

        return $r['persen'] === null
            ? sprintf('Anggaran %s: %s', $r['periode'], $status)
            : sprintf('Anggaran %s: serapan %s%% (%s)', $r['periode'], number_format((float) $r['persen'], 1, ',', '.'), $status);
    }

    /**
     * Baris yang bergantung pada pengguna sendiri (tidak di-cache): jumlah log produksi relawan hari ini.
     *
     * @return list<string>
     */
    private function milikSaya(User $user, int $sppgId): array
    {
        if ($user->role !== Role::Relawan || ! self::bisaBaca($user, 'log-produksi', true)) {
            return [];
        }

        $jumlah = LogProduksi::query()->withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)
            ->where('dicatat_oleh', $user->getKey())
            ->whereDate('created_at', Carbon::now($this->harian->zona())->toDateString())
            ->count();

        return ['Log produksi saya hari ini: '.self::n($jumlah)];
    }

    /** Hak R pada modul; kecuali $bolehMilikSendiri, sel R* (milik sendiri saja) tidak dihitung. */
    private static function bisaBaca(User $user, string $modul, bool $bolehMilikSendiri = false): bool
    {
        return Rbac::can($user, $modul, 'R') && ($bolehMilikSendiri || ! Rbac::isSelfOnly($user, $modul, 'R'));
    }

    private static function n(mixed $nilai): string
    {
        return number_format((float) $nilai, 0, ',', '.');
    }
}

<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Enums\Role;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\RingkasanDashboard;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotTombol;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Dashboard (layar `db`, R-07): 5-8 angka kunci per peran dari DashboardService, yang sama dengan GET /api/v1/dashboard
 * dan tool Agen AI `ringkas_dashboard_saya` (RingkasanDashboard). Baca-saja.
 *
 * - Hanya sesi penuh (BotNavigasi, HAK_SESI) dan Gate `dashboard.lihat` (izinkan). Hak baca tiap widget ditegakkan
 *   DashboardService (Rbac): widget tanpa hak tidak dihitung dan barisnya tidak muncul. Hak dihitung ulang setiap klik.
 * - Cakupan SPPG hanya dari token `n1` yang SUDAH divalidasi BotNavigasi (peran satu-SPPG: SPPG sendiri; investor:
 *   pivot investor_sppg; admin: SPPG ada, baca-saja). Tanpa SPPG pada token, admin/investor melihat GABUNGAN.
 * - Tanpa nama orang atau data pribadi: hanya hitungan, persen, nominal, label baku, dan nama SPPG (dibersihkan).
 */
final class LayarDashboard extends LayarModulDasar
{
    /** Rute Mini App untuk "Lihat detail"; null = Mini App utama. Isi '/dashboard' saat layar Mini App-nya tersedia. */
    public const RUTE_MINIAPP = null;

    /** Pilihan SPPG langsung di layar (investor); selebihnya lewat "Pilih SPPG". */
    private const MAKS_PILIHAN = 6;

    public function id(): string
    {
        return BotLayarRegistry::DASHBOARD;
    }

    public function modul(): ?string
    {
        return null;
    }

    public function hak(): string
    {
        return self::HAK_SESI;
    }

    public function butuhSppg(): bool
    {
        return false;
    }

    protected function judul(): string
    {
        return 'Dashboard';
    }

    public function jedaDetik(): int
    {
        return LayarKeuangan::jedaBerat();
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return RingkasanDashboard::tersediaUntuk($user);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        try {
            // Diresolusi saat dipakai: SppgAktif bersifat scoped (per permintaan/job), jangan disimpan di properti.
            $data = app(DashboardService::class)->bangun($user, $sppgId, DashboardService::RENTANG_BAWAAN);
        } catch (HttpResponseException) {
            return $this->susun($user, $sppgId, $konteks, ['Dashboard tidak dapat ditampilkan untuk akun Anda. Hubungi Admin.'], namaSppg: false);
        }

        $satu = ($data['mode'] ?? null) === 'satu';
        $cakupan = RingkasanDashboard::cakupan($data);
        $isi = [];

        if (! $satu) {
            $isi[] = '<i>'.self::e(sprintf('Gabungan %d SPPG', $cakupan['jumlah_sppg'])).'</i>';
        }

        $isi[] = 'Hari ini: '.self::e(FormatLayar::tanggal($data['periode']['hari_ini'] ?? null));

        $angka = RingkasanDashboard::dari($data);

        if ($angka === []) {
            $isi[] = 'Belum ada angka dashboard untuk hak akses Anda.';
        }

        foreach ($angka as $a) {
            $isi[] = '- <b>'.self::e($a['label']).'</b>: '.self::e($a['nilai']);
        }

        return $this->susun($user, $sppgId, $konteks, $isi, barisTombol: $this->tombol($user, $sppgId, $konteks, $data), namaSppg: $satu);
    }

    /**
     * Pilihan SPPG (investor / admin) dan "Lihat detail" ke Mini App. Semua `n1` hanya penunjuk: hak SPPG dicek ulang
     * BotNavigasi saat diklik.
     *
     * @param  array<string, mixed>  $data
     * @return list<list<array<string, mixed>>>
     */
    private function tombol(User $user, ?int $sppgId, KonteksLayar $konteks, array $data): array
    {
        $baris = [];

        if (BotLayarRegistry::bisaMemilihSppg($user)) {
            $pilihan = [];

            if ($sppgId !== null) {
                $pilihan[] = $konteks->tombol('Semua SPPG', $this->id(), null);
            }

            // Investor: tombol per SPPG miliknya (sumber: pivot lewat DashboardService) bila sedikit; selain itu pemilih.
            $lain = $user->role === Role::Investor
                ? array_values(array_filter((array) ($data['sppg_pilihan'] ?? []), fn (array $s) => (int) $s['id'] !== $sppgId))
                : null;

            if ($lain !== null && count($lain) <= self::MAKS_PILIHAN) {
                foreach ($lain as $s) {
                    $pilihan[] = $konteks->tombol(mb_substr(RingkasanDashboard::bersihkan((string) $s['nama']), 0, 30), $this->id(), (int) $s['id']);
                }
            } else {
                $pilihan[] = $konteks->tombol('Pilih SPPG', BotLayarRegistry::PILIH_SPPG, $sppgId);
            }

            $baris = BotTombol::susun($pilihan);
        }

        $query = $user->role === Role::Investor && $sppgId !== null ? ['sppg' => $sppgId] : [];
        $detail = TelegramBotService::webAppButton('Lihat detail', app(TelegramBotService::class)->miniAppUrl($query, self::RUTE_MINIAPP));

        if ($detail !== null) {
            $baris[] = [$detail];
        }

        return $baris;
    }
}

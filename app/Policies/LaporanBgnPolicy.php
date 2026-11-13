<?php

namespace App\Policies;

use App\Enums\JenisLaporanBgn;
use App\Models\User;
use App\Support\Rbac;

/**
 * Laporan BGN Lampiran 30a-30d (R07-2). Bukan sumber daya per-record: gerbang per jenis laporan + SPPG.
 *
 * - Cetak/unduh penuh (30a-30d): admin, kepala_sppg, akuntan (`rbac.aksi_peran.lihat-laporan-bgn`).
 * - Investor: hanya agregat (30a, 30c, 30d-A, 30d-B; `lihat-laporan-bgn-agregat`); 30b dan 30d-C = 403 (Q-4).
 * - ahli_gizi, staf_gudang, relawan: tanpa akses.
 * - Wajib hak R modul `keuangan`, dan SPPG yang diminta harus SPPG aktif pengguna (investor: SPPG miliknya lewat
 *   header X-SPPG-Id yang sudah tervalidasi). Admin lintas SPPG.
 * Pengguna nonaktif/terhapus tidak punya hak (Rbac fail closed).
 */
class LaporanBgnPolicy
{
    public function lihat(User $user, JenisLaporanBgn $jenis, int $sppgId): bool
    {
        if (! $user->canAuthenticate() || ! Rbac::can($user, 'keuangan', 'R')) {
            return false;
        }

        $aksi = $jenis->agregat() ? 'lihat-laporan-bgn-agregat' : 'lihat-laporan-bgn';

        if (! Rbac::canAction($user, $aksi)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $aktif = $user->sppgAktifId();

        return $aktif !== null && $aktif === $sppgId;
    }

    /** Boleh menerbitkan nomor LPA baru: investor hanya membaca (nomor yang sudah terbit tetap tampil). */
    public function terbitkanNomor(User $user): bool
    {
        return $user->canAuthenticate() && Rbac::canAction($user, 'lihat-laporan-bgn');
    }
}

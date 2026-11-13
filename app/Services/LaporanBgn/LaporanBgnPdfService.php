<?php

namespace App\Services\LaporanBgn;

use App\Enums\JenisLaporanBgn;
use App\Enums\Role;
use App\Models\LaporanBgn;
use App\Models\Sppg;
use App\Models\User;
use App\Services\LaporanBgn\Pdf\PdfRenderer;
use App\Support\SppgAktif;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Orkestrasi PDF laporan BGN (R07-3): otorisasi ulang, data (LaporanBgnService::susun), foto bukti, render ke berkas
 * sementara di direktori kerja privat. Pemanggil (Job) wajib menghapus berkas setelah dikirim.
 */
class LaporanBgnPdfService
{
    public function __construct(
        private readonly LaporanBgnService $laporan,
        private readonly BuktiLaporanService $bukti,
        private readonly PdfRenderer $renderer,
    ) {}

    /**
     * Otorisasi yang sama dengan permintaan HTTP (LaporanBgnPolicy::lihat), dihitung ulang tanpa header/permintaan:
     * investor lewat pivot investor_sppg (SppgAktif::untuk), peran satu-SPPG lewat users.sppg_id, admin lintas SPPG.
     */
    public function boleh(User $pengguna, JenisLaporanBgn $jenis, int $sppgId): bool
    {
        $cek = fn (): bool => Gate::forUser($pengguna)->allows('lihat', [LaporanBgn::class, $jenis, $sppgId]);

        if ($pengguna->isAdmin()) {
            return $cek();
        }

        try {
            return (bool) app(SppgAktif::class)->untuk($pengguna, $sppgId, $cek);
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * Pilihan penandatangan harus pengguna aktif SPPG ini dengan peran yang tepat (Q-7); dicek saat permintaan
     * (sebelum antre) supaya kesalahan input tidak baru muncul di dalam job.
     *
     * @param  array{akuntan_id?: int|null, kepala_sppg_id?: int|null}  $opsi
     */
    public function validasiPenandatangan(Sppg $sppg, array $opsi): void
    {
        foreach (['akuntan_id' => Role::Akuntan, 'kepala_sppg_id' => Role::KepalaSppg] as $param => $peran) {
            $id = $opsi[$param] ?? null;

            if ($id !== null && ! User::query()->withoutGlobalScopes()->where('id', $id)->where('sppg_id', $sppg->getKey())->where('role', $peran->value)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([$param => ['Pengguna yang dipilih bukan '.strtolower($peran->label()).' aktif SPPG ini.']]);
            }
        }
    }

    /**
     * @param  array{akuntan_id?: int|null, kepala_sppg_id?: int|null}  $opsi
     * @return string jalur berkas PDF sementara
     */
    public function buat(User $pengguna, JenisLaporanBgn $jenis, Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir, array $opsi = []): string
    {
        $data = $this->laporan->susun($jenis, $sppg, $awal, $akhir, $pengguna, $opsi);
        $kumpulan = $this->bukti->kumpulkan($jenis, $sppg, $awal, $akhir, $pengguna);
        $items = $kumpulan['items'];

        $bukti = (function () use ($items) {
            foreach ($items as $item) {
                yield ['label' => $item['label'], 'jpeg' => $this->bukti->jpeg($item['lampiran'])];
            }
        })();

        $tujuan = $this->berkasSementara();

        try {
            $this->renderer->render($data, $bukti, $tujuan, ['bukti_dilewati' => $kumpulan['dilewati']]);
        } catch (\Throwable $e) {
            @unlink($tujuan);

            throw $e;
        }

        return $tujuan;
    }

    /** Nama berkas unduhan: hanya ASCII, tanpa data pribadi. */
    public static function namaBerkas(JenisLaporanBgn $jenis, CarbonImmutable $awal, CarbonImmutable $akhir): string
    {
        $periode = $awal->equalTo($akhir) ? $awal->toDateString() : $awal->toDateString().'_'.$akhir->toDateString();

        return 'laporan-bgn-'.strtolower($jenis->lampiran()).'-'.$periode.'.pdf';
    }

    private function berkasSementara(): string
    {
        $dir = storage_path((string) config('laporan-bgn.direktori_kerja', 'app/private/laporan-bgn-tmp'));

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Direktori kerja PDF tidak dapat dibuat.');
        }

        $dasar = tempnam($dir, 'lap');

        if ($dasar === false) {
            throw new RuntimeException('Berkas sementara PDF tidak dapat dibuat.');
        }

        return $dasar;
    }
}

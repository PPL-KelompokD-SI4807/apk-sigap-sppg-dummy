<?php

namespace App\Services\PenerimaManfaat;

use App\Enums\KelompokSasaran;
use Illuminate\Support\Facades\DB;

/**
 * Agregat penerima manfaat per SPPG (R-06a): HANYA hitungan, tanpa nama/kelas/alergi/ID penerima.
 *
 * Penyamaran sel kecil (R06a-S-02, docs/decisions/2026-10-26-ambang-agregat-k5.md) untuk peran yang
 * hanya punya hak agregat (investor):
 *  1. Sel primer: hitungan 1..k-1 -> `jumlah: null` + `penanda: "<k"`. Nol tetap ditampilkan.
 *  2. Marginal: `aktif` disamarkan bila 1..k-1; `total` disamarkan bila total ATAU nonaktif
 *     (total - aktif) bernilai 1..k-1, agar jumlah nonaktif tidak dapat dihitung dari total - aktif.
 *  3. Sel sekunder (pelengkap) per tabel: selama ada sel tersamar dan (banyak sel tersamar < 2 ATAU
 *     jumlah nilai tersamar < k), sel positif terkecil berikutnya ikut disamarkan, sehingga sel kecil
 *     tidak dapat dihitung dari `aktif` dikurangi sel yang terlihat.
 *  4. Bila `aktif` sendiri kecil (SPPG sangat kecil), seluruh rincian disamarkan termasuk nol.
 *  Semua sel tersamar memakai penanda yang SAMA (sel primer dan pelengkap tidak dapat dibedakan).
 *  Titik layanan TIDAK PERNAH disilangkan dengan kelompok sasaran (tidak ada tabel titik x kelompok).
 */
final class AgregatPenerimaService
{
    /** @return array<string, mixed> bentuk lihat docs/api (PenerimaManfaatAgregat) */
    public function hitung(int $sppgId, bool $samarkan): array
    {
        $basis = fn () => DB::table('penerima_manfaats')
            ->where('penerima_manfaats.sppg_id', $sppgId)
            ->whereNull('penerima_manfaats.deleted_at');

        $perKelompok = $basis()->where('penerima_manfaats.is_active', true)
            ->groupBy('kelompok_sasaran')->selectRaw('kelompok_sasaran, COUNT(*) as jumlah')
            ->pluck('jumlah', 'kelompok_sasaran');

        // R06a-S-04: titik layanan yang sudah dihapus lunak tidak ikut tampil (namanya tidak bocor).
        $perTitik = $basis()->where('penerima_manfaats.is_active', true)
            ->join('titik_layanans', 'titik_layanans.id', '=', 'penerima_manfaats.titik_layanan_id')
            ->whereNull('titik_layanans.deleted_at')
            ->groupBy('titik_layanans.id', 'titik_layanans.nama')
            ->orderBy('titik_layanans.nama')->orderBy('titik_layanans.id')
            ->get(['titik_layanans.id', 'titik_layanans.nama', DB::raw('COUNT(*) as jumlah')]);

        $data = [
            'sppg_id' => $sppgId,
            'total' => $basis()->count(),
            'aktif' => (int) $perKelompok->sum(),
            'per_kelompok_sasaran' => array_map(fn (KelompokSasaran $k) => [
                'kelompok_sasaran' => $k->value,
                'label' => $k->label(),
                'jumlah' => (int) ($perKelompok[$k->value] ?? 0),
            ], KelompokSasaran::cases()),
            'per_titik_layanan' => $perTitik->map(fn ($t) => [
                'titik_layanan_id' => (int) $t->id,
                'nama' => (string) $t->nama,
                'jumlah' => (int) $t->jumlah,
            ])->values()->all(),
        ];

        return $samarkan ? self::samarkan($data, self::ambang()) : self::tanpaPenyamaran($data);
    }

    /** Ambang k dari config/rbac.php (minimal 2). */
    public static function ambang(): int
    {
        return max(2, (int) config('rbac.agregat_ambang.k', 5));
    }

    /**
     * Terapkan penyamaran sel kecil pada data agregat mentah (lihat docblock kelas).
     *
     * @param  array{sppg_id: int, total: int, aktif: int, per_kelompok_sasaran: list<array<string, mixed>>, per_titik_layanan: list<array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    public static function samarkan(array $data, int $k): array
    {
        $kecil = fn (int $n): bool => $n > 0 && $n < $k;
        $total = (int) $data['total'];
        $aktif = (int) $data['aktif'];

        $aktifKecil = $kecil($aktif);
        $aktifTersamar = $aktifKecil;
        $totalTersamar = $kecil($total) || $kecil($total - $aktif);

        $topeng = [];

        foreach (['per_kelompok_sasaran', 'per_titik_layanan'] as $tabel) {
            $nilai = array_map(fn (array $sel) => (int) $sel['jumlah'], $data[$tabel]);
            // Marginal tabel diketahui pembaca bila jumlah selnya sama dengan `aktif` yang ditampilkan.
            [$topeng[$tabel], $harusSamarkanAktif] = self::topengTabel($nilai, $k, ! $aktifTersamar && array_sum($nilai) === $aktif);
            $aktifTersamar = $aktifTersamar || $harusSamarkanAktif;
        }

        $penanda = '<'.$k;
        $hasil = [
            'sppg_id' => $data['sppg_id'],
            'total' => $totalTersamar ? null : $total,
            'total_penanda' => $totalTersamar ? $penanda : null,
            'aktif' => $aktifTersamar ? null : $aktif,
            'aktif_penanda' => $aktifTersamar ? $penanda : null,
            'penyamaran' => ['diterapkan' => true, 'ambang_k' => $k, 'penanda' => $penanda],
        ];

        foreach (['per_kelompok_sasaran', 'per_titik_layanan'] as $tabel) {
            $hasil[$tabel] = [];

            foreach ($data[$tabel] as $i => $sel) {
                // SPPG sangat kecil (aktif < k): seluruh rincian disamarkan, termasuk nol.
                $tersamar = $aktifKecil || $topeng[$tabel][$i];
                $sel['jumlah'] = $tersamar ? null : (int) $sel['jumlah'];
                $sel['penanda'] = $tersamar ? $penanda : null;
                $hasil[$tabel][] = $sel;
            }
        }

        return $hasil;
    }

    /**
     * Topeng satu tabel aditif: primer (1..k-1), lalu sekunder (sel positif terkecil) sampai sel
     * tersamar >= 2 dan jumlah nilainya >= k. Mengembalikan [topeng, aktifHarusDisamarkan]; yang kedua
     * true hanya bila marginal diketahui dan tidak ada sel pelengkap tersisa.
     *
     * @param  list<int>  $nilai
     * @return array{0: list<bool>, 1: bool}
     */
    public static function topengTabel(array $nilai, int $k, bool $marginalDiketahui = true): array
    {
        $topeng = array_map(fn (int $n): bool => $n > 0 && $n < $k, $nilai);

        while (in_array(true, $topeng, true)) {
            $tersamar = array_keys(array_filter($topeng));
            $jumlah = array_sum(array_map(fn (int $i) => $nilai[$i], $tersamar));

            if (count($tersamar) >= 2 && $jumlah >= $k) {
                break;
            }

            $calon = null;

            foreach ($nilai as $i => $n) {
                if (! $topeng[$i] && $n > 0 && ($calon === null || $n < $nilai[$calon])) {
                    $calon = $i;
                }
            }

            if ($calon === null) {
                // Tak ada sel pelengkap: sel tersamar dapat dihitung dari marginal -> marginal disamarkan.
                return [$topeng, $marginalDiketahui];
            }

            $topeng[$calon] = true;
        }

        return [$topeng, false];
    }

    /**
     * Bentuk respons yang sama tanpa penyamaran (peran ber-hak R penuh: Admin, Kepala SPPG, Ahli Gizi).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function tanpaPenyamaran(array $data): array
    {
        $sel = fn (array $baris) => array_map(fn (array $s) => $s + ['penanda' => null], $baris);

        return [
            'sppg_id' => $data['sppg_id'],
            'total' => $data['total'],
            'total_penanda' => null,
            'aktif' => $data['aktif'],
            'aktif_penanda' => null,
            'penyamaran' => ['diterapkan' => false, 'ambang_k' => null, 'penanda' => null],
            'per_kelompok_sasaran' => $sel($data['per_kelompok_sasaran']),
            'per_titik_layanan' => $sel($data['per_titik_layanan']),
        ];
    }
}

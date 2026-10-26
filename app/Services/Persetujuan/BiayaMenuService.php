<?php

namespace App\Services\Persetujuan;

use App\Enums\StatusJadwalProduksi;
use App\Models\BahanBaku;
use App\Models\HargaPemasok;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\Pemasok;
use App\Models\PenerimaManfaat;
use App\Models\Scopes\SppgScope;
use Illuminate\Support\Collection;

/**
 * Rincian biaya menu (R-06b-1): bahan x takaran x harga pemasok terkini = biaya per porsi;
 * total = biaya per porsi x jumlah porsi (asumsi dihitung server, klien tidak pernah mengirim angka).
 *
 * Pembulatan konsisten 2 desimal: biaya per baris, biaya per porsi (jumlah baris yang sudah
 * dibulatkan), dan total. Bahan tanpa harga berlaku atau tanpa konversi satuan ditandai di rincian.
 */
class BiayaMenuService
{
    public const MASALAH_TANPA_HARGA = 'tanpa_harga';

    public const MASALAH_TANPA_KONVERSI = 'tanpa_konversi';

    public const SUMBER_JADWAL = 'jadwal_produksi';

    public const SUMBER_KELOMPOK = 'penerima_manfaat_kelompok';

    public const SUMBER_TOTAL = 'penerima_manfaat_total';

    /** Batas atas (eksklusif) biaya per porsi: kolom decimal(14,2) -> maks. 999.999.999.999,99. */
    public const BATAS_BIAYA_PER_PORSI = 1.0e12;

    /** Batas atas (eksklusif) total biaya: kolom decimal(16,2) -> maks. 99.999.999.999.999,99. */
    public const BATAS_TOTAL_BIAYA = 1.0e14;

    /** Satuan -> gram per satuan. Asumsi 1 ml = 1 g; satuan lain memakai harga_pemasoks.gram_per_satuan. */
    private const GRAM_PER_SATUAN = ['kg' => 1000.0, 'liter' => 1000.0, 'l' => 1000.0, 'g' => 1.0, 'gram' => 1.0, 'ml' => 1.0];

    /** True bila satuan bahan sudah punya konversi baku (gram_per_satuan pada harga tidak wajib). */
    public static function satuanBaku(?string $satuan): bool
    {
        return array_key_exists(mb_strtolower(trim((string) $satuan)), self::GRAM_PER_SATUAN);
    }

    /**
     * @return array{
     *     menu_id: int, menu_versi: int, bahan: list<array<string, mixed>>, biaya_per_porsi: float,
     *     jumlah_porsi: int, sumber_porsi: string, keterangan_asumsi: string, total_biaya: float,
     *     lengkap: bool, bahan_bermasalah: list<array<string, mixed>>, tanggal_acuan: string
     * }
     */
    public function hitung(Menu $menu): array
    {
        $sppgId = (int) $menu->sppg_id;
        $tanggal = $this->hariIni();

        $komposisi = $menu->bahanBakus()->withoutGlobalScope(SppgScope::class)->withTrashed()->get();
        $harga = $this->hargaTerkini($sppgId, $komposisi, $tanggal);
        $namaPemasok = Pemasok::withoutGlobalScope(SppgScope::class)->withTrashed()
            ->whereIn('id', collect($harga)->pluck('pemasok_id')->all())->pluck('nama', 'id');

        $baris = [];
        $bermasalah = [];

        foreach ($komposisi->sortBy(fn (BahanBaku $b) => mb_strtolower($b->nama).'|'.str_pad((string) $b->id, 12, '0', STR_PAD_LEFT)) as $bahan) {
            $gram = (float) $bahan->pivot->gram_per_porsi;
            $h = $harga[$bahan->id] ?? null;
            $row = [
                'bahan_baku_id' => $bahan->id,
                'nama' => $bahan->nama,
                'satuan' => $bahan->satuan,
                'gram_per_porsi' => round($gram, 2),
                'gram_per_satuan' => null,
                'harga_per_satuan' => null,
                'pemasok_id' => null,
                'pemasok_nama' => null,
                'pemasok_utama' => false,
                'berlaku_mulai' => null,
                'biaya_per_porsi' => null,
                'masalah' => null,
            ];

            if ($h === null) {
                $row['masalah'] = self::MASALAH_TANPA_HARGA;
            } else {
                $gpu = self::GRAM_PER_SATUAN[mb_strtolower(trim((string) $bahan->satuan))] ?? ($h->gram_per_satuan === null ? null : (float) $h->gram_per_satuan);
                $row['gram_per_satuan'] = $gpu;
                $row['harga_per_satuan'] = (float) $h->harga;
                $row['pemasok_id'] = (int) $h->pemasok_id;
                $row['pemasok_nama'] = $namaPemasok[$h->pemasok_id] ?? null;
                $row['pemasok_utama'] = $bahan->pemasok_id !== null && (int) $bahan->pemasok_id === (int) $h->pemasok_id;
                $row['berlaku_mulai'] = $h->berlaku_mulai->toDateString();

                if ($gpu === null || $gpu <= 0) {
                    $row['masalah'] = self::MASALAH_TANPA_KONVERSI;
                } else {
                    $row['biaya_per_porsi'] = round($gram / $gpu * (float) $h->harga, 2);
                }
            }

            if ($row['masalah'] !== null) {
                $bermasalah[] = ['bahan_baku_id' => $bahan->id, 'nama' => $bahan->nama, 'masalah' => $row['masalah']];
            }

            $baris[] = $row;
        }

        $perPorsi = round(array_sum(array_map(fn (array $r) => (float) ($r['biaya_per_porsi'] ?? 0), $baris)), 2);
        [$porsi, $sumber, $keterangan] = $this->jumlahPorsi($menu, $tanggal);

        return [
            'menu_id' => (int) $menu->id,
            'menu_versi' => (int) $menu->versi,
            'bahan' => $baris,
            'biaya_per_porsi' => $perPorsi,
            'jumlah_porsi' => $porsi,
            'sumber_porsi' => $sumber,
            'keterangan_asumsi' => $keterangan,
            'total_biaya' => round($perPorsi * $porsi, 2),
            'lengkap' => $baris !== [] && $bermasalah === [],
            'bahan_bermasalah' => $bermasalah,
            'tanggal_acuan' => $tanggal,
        ];
    }

    /**
     * True bila ada angka hasil hitung yang tidak muat di kolom snapshot (decimal 14,2 / 16,2) atau
     * tidak finite (presisi tak aman). Pemanggil menjawab 422 alih-alih membiarkan galat basis data (500).
     *
     * @param  array{bahan: list<array<string, mixed>>, biaya_per_porsi: float, total_biaya: float}  $hitung
     */
    public static function melebihiBatas(array $hitung): bool
    {
        foreach ($hitung['bahan'] as $row) {
            $b = $row['biaya_per_porsi'];

            if ($b !== null && (! is_finite((float) $b) || (float) $b >= self::BATAS_BIAYA_PER_PORSI)) {
                return true;
            }
        }

        return ! is_finite($hitung['biaya_per_porsi']) || $hitung['biaya_per_porsi'] >= self::BATAS_BIAYA_PER_PORSI
            || ! is_finite($hitung['total_biaya']) || $hitung['total_biaya'] >= self::BATAS_TOTAL_BIAYA;
    }

    /**
     * Harga berlaku per bahan: pemasok utama bahan diutamakan bila punya harga berlaku; selain itu
     * entri terbaru (berlaku_mulai, lalu id terbesar). Hanya pemasok aktif SPPG yang sama; entri
     * bertanggal mendatang diabaikan.
     *
     * @param  Collection<int, BahanBaku>  $bahan
     * @return array<int, HargaPemasok> bahan_baku_id => entri harga
     */
    public function hargaTerkini(int $sppgId, Collection $bahan, string $tanggal): array
    {
        if ($bahan->isEmpty()) {
            return [];
        }

        $utama = $bahan->mapWithKeys(fn (BahanBaku $b) => [$b->id => $b->pemasok_id === null ? null : (int) $b->pemasok_id]);

        $entri = HargaPemasok::withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)
            ->whereIn('bahan_baku_id', $bahan->pluck('id')->all())
            ->where('berlaku_mulai', '<=', $tanggal)
            ->whereIn('pemasok_id', Pemasok::withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $sppgId)->where('is_active', true)->select('id'))
            ->orderByDesc('berlaku_mulai')->orderByDesc('id')
            ->get()
            ->groupBy('bahan_baku_id');

        $hasil = [];

        foreach ($entri as $bahanId => $daftar) {
            $pilih = $daftar->first(fn (HargaPemasok $h) => $utama[$bahanId] !== null && (int) $h->pemasok_id === $utama[$bahanId]) ?? $daftar->first();
            $hasil[(int) $bahanId] = $pilih;
        }

        return $hasil;
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function jumlahPorsi(Menu $menu, string $tanggal): array
    {
        $sppgId = (int) $menu->sppg_id;

        $jadwal = JadwalProduksi::withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)->where('menu_id', $menu->id)
            ->whereDate('tanggal_produksi', '>=', $tanggal)
            ->where('status', '!=', StatusJadwalProduksi::Dibatalkan->value)
            ->orderBy('tanggal_produksi')->orderBy('id')->first();

        if ($jadwal !== null) {
            return [
                (int) $jadwal->target_porsi,
                self::SUMBER_JADWAL,
                sprintf('Target porsi jadwal produksi terdekat (%s).', $jadwal->tanggal_produksi->toDateString()),
            ];
        }

        $aktif = PenerimaManfaat::withoutGlobalScope(SppgScope::class)->where('sppg_id', $sppgId)->where('is_active', true);
        $kelompok = (clone $aktif)->where('kelompok_sasaran', $menu->kelompok_sasaran->value)->count();

        if ($kelompok > 0) {
            return [$kelompok, self::SUMBER_KELOMPOK, sprintf('Belum ada jadwal produksi; memakai jumlah penerima manfaat aktif kelompok %s.', $menu->kelompok_sasaran->label())];
        }

        return [
            $aktif->count(),
            self::SUMBER_TOTAL,
            'Belum ada jadwal produksi dan penerima kelompok sasaran menu; memakai total penerima manfaat aktif.',
        ];
    }

    private function hariIni(): string
    {
        return now((string) config('operasional.timezone', 'Asia/Jakarta'))->toDateString();
    }
}

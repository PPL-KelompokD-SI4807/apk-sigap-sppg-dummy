<?php

namespace App\Services\Gizi;

use App\Models\BahanBaku;
use App\Models\Menu;
use App\Models\Scopes\SppgScope;
use App\Models\StandarGizi;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Komposisi bahan menu dan perbandingan gizi per porsi dengan standar kelompok sasaran (FR-05).
 */
class GiziService
{
    /** kolom menu => [label, satuan, awalan kolom standar] */
    public const KOLOM = [
        'energi_kkal' => ['Energi', 'kkal', 'energi'],
        'protein_g' => ['Protein', 'g', 'protein'],
        'lemak_g' => ['Lemak', 'g', 'lemak'],
        'karbohidrat_g' => ['Karbohidrat', 'g', 'karbohidrat'],
        'serat_g' => ['Serat', 'g', 'serat'],
    ];

    /**
     * Ganti seluruh komposisi menu. Bahan HARUS milik SPPG menu (divalidasi Form Request;
     * dicek ulang di sini agar aman dipakai dari jalur lain).
     *
     * @param  list<array{bahan_baku_id: int, gram_per_porsi: float|int|string}>  $bahan
     */
    public function sinkronKomposisi(Menu $menu, array $bahan): void
    {
        DB::transaction(function () use ($menu, $bahan): void {
            $sync = [];

            foreach ($bahan as $b) {
                $sync[(int) $b['bahan_baku_id']] = ['gram_per_porsi' => $b['gram_per_porsi']];
            }

            $sah = BahanBaku::withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $menu->sppg_id)->whereIn('id', array_keys($sync))->pluck('id')->all();

            if (count($sah) !== count($sync)) {
                throw ValidationException::withMessages(['bahan' => ['Terdapat bahan baku yang tidak ditemukan.']]);
            }

            $menu->bahanBakus()->sync($sync);
        });
    }

    public function standarUntuk(Menu $menu): ?StandarGizi
    {
        return StandarGizi::withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $menu->sppg_id)
            ->where('kelompok_sasaran', $menu->kelompok_sasaran->value)
            ->first();
    }

    /**
     * Peringatan nilai di luar rentang standar. Kosong bila standar belum dikonfigurasi.
     *
     * @return list<array{kolom: string, label: string, status: string, nilai: float, minimum: float, maksimum: float, pesan: string}>
     */
    public function peringatan(Menu $menu, ?StandarGizi $standar = null): array
    {
        $standar ??= $this->standarUntuk($menu);

        if ($standar === null) {
            return [];
        }

        $hasil = [];

        foreach ($standar->peringatanUntuk($menu) as $kolom => $status) {
            [$label, $satuan, $awalan] = self::KOLOM[$kolom];
            $nilai = (float) $menu->{$kolom};
            $min = (float) $standar->{$awalan.'_min'};
            $max = (float) $standar->{$awalan.'_max'};

            $hasil[] = [
                'kolom' => $kolom,
                'label' => $label,
                'status' => $status,
                'nilai' => $nilai,
                'minimum' => $min,
                'maksimum' => $max,
                'pesan' => $status === 'kurang'
                    ? sprintf('%s %s %s di bawah standar minimum %s %s.', $label, self::angka($nilai), $satuan, self::angka($min), $satuan)
                    : sprintf('%s %s %s melebihi standar maksimum %s %s.', $label, self::angka($nilai), $satuan, self::angka($max), $satuan),
            ];
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    public function ringkasan(Menu $menu): array
    {
        $menu->loadMissing('bahanBakus');
        $standar = $this->standarUntuk($menu);
        $peringatan = $this->peringatan($menu, $standar);

        $komposisi = $menu->bahanBakus->map(fn ($b) => [
            'bahan_baku_id' => $b->id,
            'nama' => $b->nama,
            'satuan' => $b->satuan,
            'gram_per_porsi' => (float) $b->pivot->gram_per_porsi,
        ])->values()->all();

        return [
            'menu_id' => $menu->id,
            'nama_menu' => $menu->nama_menu,
            'kelompok_sasaran' => $menu->kelompok_sasaran->value,
            'sumber_gizi' => 'input_menu',
            'per_porsi' => collect(array_keys(self::KOLOM))->mapWithKeys(fn ($k) => [$k => (float) $menu->{$k}])->all(),
            'total_gram_per_porsi' => round(array_sum(array_column($komposisi, 'gram_per_porsi')), 2),
            'komposisi' => $komposisi,
            'standar' => $standar,
            'standar_tersedia' => $standar !== null,
            'sesuai_standar' => $standar === null ? null : $peringatan === [],
            'peringatan' => $peringatan,
        ];
    }

    private static function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 2, '.', ''), '0'), '.');
    }
}

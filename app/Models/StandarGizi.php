<?php

namespace App\Models;

use App\Enums\KelompokSasaran;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandarGizi extends Model
{
    use Auditable, BelongsToSppg, HasFactory, SoftDeletes;

    protected $table = 'standar_gizis';

    /** @var list<string> */
    protected $fillable = [
        'kelompok_sasaran',
        'energi_min',
        'energi_max',
        'protein_min',
        'protein_max',
        'lemak_min',
        'lemak_max',
        'karbohidrat_min',
        'karbohidrat_max',
        'serat_min',
        'serat_max',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kelompok_sasaran' => KelompokSasaran::class,
            'energi_min' => 'decimal:2',
            'energi_max' => 'decimal:2',
            'protein_min' => 'decimal:2',
            'protein_max' => 'decimal:2',
            'lemak_min' => 'decimal:2',
            'lemak_max' => 'decimal:2',
            'karbohidrat_min' => 'decimal:2',
            'karbohidrat_max' => 'decimal:2',
            'serat_min' => 'decimal:2',
            'serat_max' => 'decimal:2',
        ];
    }

    /**
     * Bandingkan gizi per porsi menu dengan rentang standar.
     *
     * @return array<string, string> kolom => 'kurang'|'lebih' untuk nilai di luar rentang
     */
    public function peringatanUntuk(Menu $menu): array
    {
        $peta = [
            'energi_kkal' => 'energi',
            'protein_g' => 'protein',
            'lemak_g' => 'lemak',
            'karbohidrat_g' => 'karbohidrat',
            'serat_g' => 'serat',
        ];
        $hasil = [];
        foreach ($peta as $kolomMenu => $awalan) {
            $nilai = (float) $menu->{$kolomMenu};
            if ($nilai < (float) $this->{$awalan.'_min'}) {
                $hasil[$kolomMenu] = 'kurang';
            } elseif ($nilai > (float) $this->{$awalan.'_max'}) {
                $hasil[$kolomMenu] = 'lebih';
            }
        }

        return $hasil;
    }
}

<?php

namespace App\Models;

use App\Enums\KelompokSasaran;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Menu extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'menus';

    /** @var list<string> */
    protected array $searchable = ['nama_menu', 'siklus'];

    /** @var list<string> */
    protected $fillable = [
        'nama_menu',
        'kelompok_sasaran',
        'tanggal_sajian',
        'siklus',
        'energi_kkal',
        'protein_g',
        'lemak_g',
        'karbohidrat_g',
        'serat_g',
        'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kelompok_sasaran' => KelompokSasaran::class,
            'tanggal_sajian' => 'date:Y-m-d',
            'energi_kkal' => 'decimal:2',
            'protein_g' => 'decimal:2',
            'lemak_g' => 'decimal:2',
            'karbohidrat_g' => 'decimal:2',
            'serat_g' => 'decimal:2',
        ];
    }

    public function bahanBakus(): BelongsToMany
    {
        return $this->belongsToMany(BahanBaku::class, 'menu_bahan', 'menu_id', 'bahan_baku_id')->using(MenuBahan::class)->withPivot('gram_per_porsi');
    }

    public function jadwalProduksis(): HasMany
    {
        return $this->hasMany(JadwalProduksi::class);
    }
}

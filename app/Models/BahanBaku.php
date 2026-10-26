<?php

namespace App\Models;

use App\Enums\KategoriBahan;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BahanBaku extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'bahan_bakus';

    /** @var list<string> */
    protected array $searchable = ['nama', 'kode'];

    /** @var list<string> */
    protected $fillable = [
        'pemasok_id',
        'kode',
        'nama',
        'kategori',
        'satuan',
        'stok',
        'stok_minimum',
        'kedaluwarsa_terdekat',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kategori' => KategoriBahan::class,
            'stok' => 'decimal:3',
            'stok_minimum' => 'decimal:3',
            'kedaluwarsa_terdekat' => 'date:Y-m-d',
        ];
    }

    public function pemasok(): BelongsTo
    {
        return $this->belongsTo(Pemasok::class);
    }

    public function mutasiStoks(): HasMany
    {
        return $this->hasMany(MutasiStok::class);
    }

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'menu_bahan', 'bahan_baku_id', 'menu_id')->using(MenuBahan::class)->withPivot('gram_per_porsi');
    }

    public function scopeStokMenipis(Builder $query): Builder
    {
        return $query->whereColumn('stok', '<=', 'stok_minimum');
    }

    public function scopeAkanKedaluwarsa(Builder $query, int $hari = 3): Builder
    {
        return $query->whereNotNull('kedaluwarsa_terdekat')
            ->whereDate('kedaluwarsa_terdekat', '<=', now()->addDays($hari)->toDateString());
    }

    public function isStokMenipis(): bool
    {
        return (float) $this->stok <= (float) $this->stok_minimum;
    }

    public function isAkanKedaluwarsa(int $hari = 3): bool
    {
        return $this->kedaluwarsa_terdekat !== null
            && $this->kedaluwarsa_terdekat->lte(now()->addDays($hari)->startOfDay());
    }
}

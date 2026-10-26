<?php

namespace App\Models;

use App\Enums\JenisPemasok;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pemasok extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'pemasoks';

    /** @var list<string> */
    protected array $searchable = ['nama', 'jenis_bahan', 'kontak'];

    /** @var list<string> */
    protected $fillable = [
        'nama',
        'jenis',
        'kontak',
        'alamat',
        'jenis_bahan',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'jenis' => JenisPemasok::class,
            'is_active' => 'boolean',
        ];
    }

    public function bahanBakus(): HasMany
    {
        return $this->hasMany(BahanBaku::class);
    }

    public function pengeluarans(): HasMany
    {
        return $this->hasMany(Pengeluaran::class);
    }

    public function mutasiStoks(): HasMany
    {
        return $this->hasMany(MutasiStok::class);
    }
}

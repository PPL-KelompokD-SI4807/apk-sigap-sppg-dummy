<?php

namespace App\Models;

use App\Enums\StatusArmada;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Armada extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'armadas';

    /** @var list<string> */
    protected array $searchable = ['plat_nomor', 'jenis'];

    /** @var list<string> */
    protected $fillable = [
        'plat_nomor',
        'jenis',
        'kapasitas_box',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kapasitas_box' => 'integer',
            'status' => StatusArmada::class,
        ];
    }

    public function jadwalDistribusis(): HasMany
    {
        return $this->hasMany(JadwalDistribusi::class);
    }
}

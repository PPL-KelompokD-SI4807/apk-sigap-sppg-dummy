<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pengemudi extends Model
{
    use BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'pengemudis';

    /** @var list<string> */
    protected array $searchable = ['nama', 'no_hp'];

    /** @var list<string> */
    protected $fillable = [
        'nama',
        'no_hp',
        'no_sim',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function jadwalDistribusis(): HasMany
    {
        return $this->hasMany(JadwalDistribusi::class);
    }
}

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
        'sim_berlaku_hingga',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sim_berlaku_hingga' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /** SIM tidak dicatat atau masih berlaku hari ini (dipakai validasi penugasan distribusi). */
    public function simBerlaku(): bool
    {
        return $this->sim_berlaku_hingga === null || ! $this->sim_berlaku_hingga->isBefore(today());
    }

    /** @return 'berlaku'|'segera_berakhir'|'kedaluwarsa'|null */
    public function simStatus(int $ambangHari = 30): ?string
    {
        if ($this->sim_berlaku_hingga === null) {
            return null;
        }

        if ($this->sim_berlaku_hingga->isBefore(today())) {
            return 'kedaluwarsa';
        }

        return $this->sim_berlaku_hingga->lte(today()->addDays($ambangHari)) ? 'segera_berakhir' : 'berlaku';
    }

    public function jadwalDistribusis(): HasMany
    {
        return $this->hasMany(JadwalDistribusi::class);
    }
}

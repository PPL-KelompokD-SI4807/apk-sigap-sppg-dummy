<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Model;

/**
 * Penanda idempoten peringatan serapan anggaran (ambang '80', '100', 'melampaui') per periode.
 * Ditulis HANYA oleh BudgetService (insertOrIgnore pada indeks unik).
 */
class PeringatanAnggaran extends Model
{
    use BelongsToSppg;

    protected $table = 'peringatan_anggarans';

    /** @var list<string> */
    protected $fillable = ['periode', 'ambang', 'anggaran', 'terpakai', 'persen'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'anggaran' => 'decimal:2',
            'terpakai' => 'decimal:2',
            'persen' => 'decimal:2',
        ];
    }
}

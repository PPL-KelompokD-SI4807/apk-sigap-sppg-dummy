<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PencairanDana extends Model
{
    use Auditable, BelongsToSppg, HasFactory, HasLampiran, Searchable, SoftDeletes;

    protected $table = 'pencairan_danas';

    /** @var list<string> */
    protected array $searchable = ['periode', 'sumber_dana', 'no_referensi'];

    /** @var list<string> */
    protected $fillable = [
        'dicatat_oleh',
        'periode',
        'sumber_dana',
        'nominal',
        'tanggal_cair',
        'no_referensi',
        'bukti_path',
        'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'tanggal_cair' => 'date:Y-m-d',
        ];
    }

    public function pengeluarans(): HasMany
    {
        return $this->hasMany(Pengeluaran::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    public function totalPengeluaran(): string
    {
        return (string) ($this->pengeluarans()->sum('nominal') ?? '0');
    }
}

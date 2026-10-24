<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MenuBahan extends Pivot
{
    use HasFactory;

    protected $table = 'menu_bahan';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'menu_id',
        'bahan_baku_id',
        'gram_per_porsi',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gram_per_porsi' => 'decimal:2',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }
}

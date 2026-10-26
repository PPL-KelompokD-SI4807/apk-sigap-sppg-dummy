<?php

namespace App\Models;

use App\Enums\StatusKirim;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RekapHarian extends Model
{
    use BelongsToSppg, HasFactory;

    protected $table = 'rekap_harians';

    /** @var list<string> */
    protected $fillable = [
        'tanggal',
        'ringkasan',
        'dikirim_pada',
        'status_kirim',
        'catatan',
        'disusun_pada',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'ringkasan' => 'array',
            'dikirim_pada' => 'datetime',
            'status_kirim' => StatusKirim::class,
            'disusun_pada' => 'datetime',
        ];
    }

    public function penyusun(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disusun_oleh');
    }
}

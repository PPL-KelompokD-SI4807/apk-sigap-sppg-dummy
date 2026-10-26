<?php

namespace App\Models;

use App\Enums\KelompokSasaran;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PenerimaManfaat extends Model
{
    use Auditable, BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'penerima_manfaats';

    /** @var list<string> */
    protected array $searchable = ['nama', 'kelas_keterangan', 'catatan_alergi'];

    /** @var list<string> */
    protected $fillable = [
        'titik_layanan_id',
        'nama',
        'kelompok_sasaran',
        'kelas_keterangan',
        'catatan_alergi',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kelompok_sasaran' => KelompokSasaran::class,
            'is_active' => 'boolean',
        ];
    }

    public function titikLayanan(): BelongsTo
    {
        return $this->belongsTo(TitikLayanan::class);
    }
}

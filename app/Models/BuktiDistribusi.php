<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BuktiDistribusi extends Model
{
    use Auditable, BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'bukti_distribusis';

    /** @var list<string> */
    protected array $searchable = ['nama_pic', 'no_berita_acara'];

    /** @var list<string> */
    protected $fillable = [
        'jadwal_distribusi_id',
        'dicatat_oleh',
        'waktu_berangkat',
        'waktu_tiba',
        'waktu_ambil_foto',
        'porsi_diterima',
        'nama_pic',
        'no_berita_acara',
        'berita_acara_path',
        'foto_path',
        'latitude',
        'longitude',
        'catatan_selisih',
        'terlambat',
        'menit_keterlambatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'waktu_berangkat' => 'datetime',
            'waktu_tiba' => 'datetime',
            'waktu_ambil_foto' => 'datetime',
            'porsi_diterima' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'terlambat' => 'boolean',
            'menit_keterlambatan' => 'integer',
        ];
    }

    public function jadwalDistribusi(): BelongsTo
    {
        return $this->belongsTo(JadwalDistribusi::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }
}

<?php

namespace App\Models;

use App\Enums\HasilMutu;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PemeriksaanMutu extends Model
{
    use Auditable, BelongsToSppg, HasFactory, Searchable, SoftDeletes;

    protected $table = 'pemeriksaan_mutus';

    /** @var list<string> */
    protected array $searchable = ['catatan_organoleptik', 'tindakan_korektif'];

    /** @var list<string> */
    protected $fillable = [
        'jadwal_produksi_id',
        'pemeriksa_id',
        'waktu_pemeriksaan',
        'suhu_makanan_c',
        'kebersihan_dapur',
        'kebersihan_petugas',
        'organoleptik_baik',
        'catatan_organoleptik',
        'sampel_disimpan',
        'hasil',
        'tindakan_korektif',
        'foto_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'waktu_pemeriksaan' => 'datetime',
            'suhu_makanan_c' => 'decimal:1',
            'kebersihan_dapur' => 'boolean',
            'kebersihan_petugas' => 'boolean',
            'organoleptik_baik' => 'boolean',
            'sampel_disimpan' => 'boolean',
            'hasil' => HasilMutu::class,
        ];
    }

    public function jadwalProduksi(): BelongsTo
    {
        return $this->belongsTo(JadwalProduksi::class);
    }

    public function pemeriksa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pemeriksa_id');
    }
}

<?php

namespace App\Models;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pengeluaran extends Model
{
    use Auditable, BelongsToSppg, HasFactory, HasLampiran, Searchable, SoftDeletes;

    protected $table = 'pengeluarans';

    /** @var list<string> */
    protected array $searchable = ['uraian'];

    /** Teks bebas dan jalur nota tidak masuk jejak audit (R-06b-2: audit tanpa data pribadi). @var list<string> */
    protected array $auditExclude = ['uraian', 'nota_path'];

    /**
     * status_persetujuan, versi, dan pembelian_bahan_id TIDAK mass-assignable: hanya diubah
     * BudgetService/PersetujuanPengeluaranService/PembelianBahanService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pencairan_dana_id',
        'pemasok_id',
        'dicatat_oleh',
        'tanggal',
        'kategori',
        'uraian',
        'nominal',
        'melebihi_anggaran',
        'nota_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'kategori' => KategoriPengeluaran::class,
            'nominal' => 'decimal:2',
            'melebihi_anggaran' => 'boolean',
            'status_persetujuan' => StatusPersetujuanPengeluaran::class,
            'versi' => 'integer',
        ];
    }

    public function pencairanDana(): BelongsTo
    {
        return $this->belongsTo(PencairanDana::class);
    }

    public function pemasok(): BelongsTo
    {
        return $this->belongsTo(Pemasok::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    public function persetujuans(): HasMany
    {
        return $this->hasMany(PersetujuanPengeluaran::class)->orderBy('nomor');
    }

    public function scopeDisetujui(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status_persetujuan'), StatusPersetujuanPengeluaran::Disetujui->value);
    }
}

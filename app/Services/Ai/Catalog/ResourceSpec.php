<?php

namespace App\Services\Ai\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Definisi deklaratif satu sumber daya untuk tool Agen AI generik (satu definisi per sumber daya,
 * lihat CatalogDefinitions). ToolCatalog membangun tool cari_/buat_/ubah_/hapus_ darinya:
 * tool baca memakai kolom di bawah (ringkas), tool tulis menurunkan skema argumen dari Form Request.
 *
 * Kolom ($list/$detail): 'kolom' atau 'alias' => 'kolom' atau 'alias' => 'relasi.kolom'
 * (jalur relasi di-eager-load; kolom sensitif ditolak RecordPresenter::assertSafe()).
 */
final class ResourceSpec
{
    /**
     * @param  string  $key  akhiran nama tool, mis. 'titik_layanan' -> cari_titik_layanan
     * @param  string  $module  slug config/rbac.php
     * @param  string  $label  kata benda untuk deskripsi tool (bahasa Indonesia)
     * @param  class-string<Model>  $model
     * @param  list<string>  $actions  huruf yang dibangun otomatis: R, C, U, D
     * @param  list<string>  $search  kolom pencarian (boleh 'relasi.kolom')
     * @param  array<int|string, string>  $list
     * @param  array<int|string, string>  $detail  kolom tambahan saat melihat satu data (argumen id)
     * @param  array<string, array<string, mixed>>  $filters  nama => ['type'|'enum'|'column'|'scope'|'description']
     * @param  string|null  $date  kolom tanggal untuk filter dari/sampai
     * @param  list<array{0: string, 1: string}>  $order
     * @param  list<string>  $rupiah  kolom nominal rupiah (dikembalikan integer)
     * @param  string  $scopeColumn  kolom SPPG untuk pembatasan eksplisit (selain SppgScope)
     * @param  class-string<FormRequest>|null  $store
     * @param  class-string<FormRequest>|null  $update
     * @param  list<string>  $skip  nama tool yang sudah ditulis sebagai kelas sendiri
     * @param  string|null  $marker  penanda sub-modul pada draf bila slug modul dipakai >1 sumber daya
     * @param  string|null  $jenis  sub-jenis unit relawan (config rbac unit_rules.*.jenis), mis. 'produksi'
     * @param  array<string, string>  $hints  deskripsi kolom form untuk LLM
     * @param  list<string>  $exclude  kolom form yang tidak ditawarkan ke LLM
     * @param  list<string>  $initials  nama kolom hasil ($list/$detail) yang disajikan sebagai inisial
     *                                  (nama orang, mis. anak penerima manfaat; UU PDP)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $label,
        public readonly string $model,
        public readonly array $actions = ['R', 'C', 'U', 'D'],
        public readonly array $search = [],
        public readonly array $list = [],
        public readonly array $detail = [],
        public readonly array $filters = [],
        public readonly ?string $date = null,
        public readonly array $order = [['id', 'desc']],
        public readonly array $rupiah = [],
        public readonly string $scopeColumn = 'sppg_id',
        public readonly ?string $store = null,
        public readonly ?string $update = null,
        public readonly ?string $routeParameter = null,
        public readonly array $skip = [],
        public readonly ?string $marker = null,
        public readonly ?string $jenis = null,
        public readonly array $hints = [],
        public readonly array $exclude = [],
        public readonly array $initials = [],
    ) {}

    public function toolName(string $letter): string
    {
        return match ($letter) {
            'R' => 'cari_'.$this->key,
            'C' => 'buat_'.$this->key,
            'U' => 'ubah_'.$this->key,
            'D' => 'hapus_'.$this->key,
        };
    }
}

<?php

namespace App\Http\Requests\Pembelian;

use App\Http\Requests\Lampiran\AturanLampiranIds;
use Illuminate\Validation\Rule;

/**
 * Aturan bersama pembelian bahan (store, update, dan dry-run draf Agen AI). `total`, `satuan`, `status`,
 * dan `versi` server-side: klien/LLM tidak pernah menentukan total, status, atau satuan.
 */
trait AturanPembelianBahan
{
    public const JUMLAH_MAKS = 999_999_999;

    public const HARGA_MAKS = 99_999_999_999;

    /** @return array<string, list<mixed>> */
    protected function aturanPembelian(bool $wajib, ?int $sppgId): array
    {
        $req = $wajib ? 'required' : 'sometimes';
        $milikSppg = fn (string $tabel, bool $aktif = false) => Rule::exists($tabel, 'id')
            ->where(function ($q) use ($sppgId, $aktif): void {
                $q->where('sppg_id', $sppgId)->whereNull('deleted_at');

                if ($aktif) {
                    $q->where('is_active', true);
                }
            });

        $lampiran = AturanLampiranIds::untuk('pembelian-bahan');
        // Draf Agen AI (dry-run) tidak membawa lampiran: foto nota diunggah pengguna di Mini App saat konfirmasi
        // (berkas foto Telegram tidak disimpan). Endpoint nyata TETAP mewajibkan minimal satu nota.
        $lampiran['lampiran_ids'] = $wajib && ! $this->dryRunDraf()
            ? ['required', 'array', 'min:1', 'max:'.(int) config('unggahan.modul.pembelian-bahan.maks', 3)]
            : $lampiran['lampiran_ids'];

        return [
            'bahan_baku_id' => [$req, 'integer', $milikSppg('bahan_bakus')],
            'pemasok_id' => [$req, 'integer', $milikSppg('pemasoks', true)],
            'tanggal_beli' => [$req, 'date_format:Y-m-d', 'before_or_equal:today'],
            'jumlah' => [$req, 'numeric', 'gt:0', 'max:'.self::JUMLAH_MAKS, 'decimal:0,3'],
            'harga_satuan' => [$req, 'numeric', 'gt:0', 'max:'.self::HARGA_MAKS, 'decimal:0,2'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ] + $lampiran;
    }

    /** True bila dijalankan DraftValidator (permintaan sintetis ke /ai-dry-run) atau RulesSchema (/ai-schema). */
    private function dryRunDraf(): bool
    {
        return str_starts_with($this->path(), 'ai-');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bahan_baku_id.exists' => 'Bahan baku tidak ditemukan.',
            'pemasok_id.exists' => 'Pemasok tidak ditemukan atau tidak aktif.',
            'tanggal_beli.before_or_equal' => 'Tanggal pembelian tidak boleh di masa depan.',
            'jumlah.gt' => 'Jumlah harus lebih dari 0.',
            'jumlah.decimal' => 'Jumlah maksimal 3 angka desimal.',
            'jumlah.max' => 'Jumlah terlalu besar.',
            'harga_satuan.gt' => 'Harga satuan harus lebih dari 0.',
            'harga_satuan.decimal' => 'Harga satuan maksimal 2 angka desimal.',
            'harga_satuan.max' => 'Harga satuan terlalu besar.',
            'lampiran_ids.required' => 'Foto nota wajib dilampirkan.',
            'lampiran_ids.min' => 'Foto nota wajib dilampirkan.',
        ];
    }
}

<?php

namespace App\Http\Requests\Keuangan;

use App\Enums\KategoriPengeluaran;
use Illuminate\Validation\Rule;

/**
 * Aturan bersama Data Keuangan (dipakai juga dry-run draf Agen AI). Nominal = rupiah bulat
 * (integer > 0, maks 13 digit sesuai kolom decimal(15,2)); tanpa pecahan/float.
 */
trait AturanKeuangan
{
    public const NOMINAL_MAKS = 9_999_999_999_999;

    /** @return list<mixed> */
    protected function aturanNominal(string $req): array
    {
        return [$req, 'integer', 'min:1', 'max:'.self::NOMINAL_MAKS];
    }

    /** @return list<mixed> */
    protected function aturanBerkas(): array
    {
        return ['sometimes', 'nullable', 'file', 'max:'.intdiv((int) config('unggahan.maks_byte', 5242880), 1024)];
    }

    /** @return array<string, list<mixed>> */
    protected function aturanPencairan(bool $wajib): array
    {
        $req = $wajib ? 'required' : 'sometimes';

        return [
            'periode' => [$req, 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'sumber_dana' => [$req, 'string', 'max:100'],
            'nominal' => $this->aturanNominal($req),
            'tanggal_cair' => [$req, 'date_format:Y-m-d', 'before_or_equal:today'],
            'no_referensi' => ['sometimes', 'nullable', 'string', 'max:50'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'bukti' => $this->aturanBerkas(),
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function aturanPengeluaran(bool $wajib): array
    {
        $req = $wajib ? 'required' : 'sometimes';
        $sppgId = $this->user()?->sppg_id;
        $milikSppg = fn (string $tabel) => Rule::exists($tabel, 'id')
            ->where(fn ($q) => $q->where('sppg_id', $sppgId)->whereNull('deleted_at'));

        return [
            'tanggal' => [$req, 'date_format:Y-m-d', 'before_or_equal:today'],
            'kategori' => [$req, Rule::enum(KategoriPengeluaran::class)],
            'uraian' => [$req, 'string', 'max:255'],
            'nominal' => $this->aturanNominal($req),
            'pemasok_id' => ['sometimes', 'nullable', 'integer', $milikSppg('pemasoks')],
            'pencairan_dana_id' => ['sometimes', 'nullable', 'integer', $milikSppg('pencairan_danas')],
            'nota' => $this->aturanBerkas(),
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'periode.regex' => 'Periode harus berformat YYYY-MM (mis. 2026-11).',
            'nominal.integer' => 'Nominal harus rupiah bulat tanpa desimal.',
            'nominal.min' => 'Nominal harus lebih dari 0.',
            'nominal.max' => 'Nominal terlalu besar.',
            'tanggal.before_or_equal' => 'Tanggal pengeluaran tidak boleh di masa depan.',
            'tanggal_cair.before_or_equal' => 'Tanggal cair tidak boleh di masa depan.',
            'pemasok_id.exists' => 'Pemasok tidak ditemukan.',
            'pencairan_dana_id.exists' => 'Pencairan dana tidak ditemukan.',
            'nota.max' => 'Ukuran nota maksimal 5 MB.',
            'bukti.max' => 'Ukuran bukti maksimal 5 MB.',
        ];
    }
}

<?php

namespace App\Http\Requests\MenuGizi;

use App\Enums\KelompokSasaran;
use App\Models\Menu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan menu + komposisi (satu sumber; dipakai juga untuk dry-run draf Agen AI). */
class StoreMenuRequest extends FormRequest
{
    /** Policy dicek sebelum validasi agar peran tanpa hak mendapat 403 (bukan 422). */
    public function authorize(): bool
    {
        $menu = $this->menuRoute();

        return $menu !== null ? $this->user()->can('update', $menu) : $this->user()->can('create', Menu::class);
    }

    protected function sppgId(): ?int
    {
        return $this->user()->sppg_id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $wajib = $this->isMethod('POST') ? 'required' : 'sometimes';
        $gizi = ['sometimes', 'numeric', 'min:0', 'max:99999'];

        return [
            'nama_menu' => [$wajib, 'string', 'max:255'],
            'kelompok_sasaran' => [$wajib, Rule::enum(KelompokSasaran::class)],
            'tanggal_sajian' => ['nullable', 'date_format:Y-m-d'],
            'siklus' => ['nullable', 'string', 'max:50'],
            'energi_kkal' => $gizi,
            'protein_g' => $gizi,
            'lemak_g' => $gizi,
            'karbohidrat_g' => $gizi,
            'serat_g' => $gizi,
            'catatan' => ['nullable', 'string', 'max:2000'],
            'bahan' => ['sometimes', 'array', 'max:50'],
            'bahan.*.bahan_baku_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('bahan_bakus', 'id')->where('sppg_id', $this->sppgId())->whereNull('deleted_at'),
            ],
            'bahan.*.gram_per_porsi' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['bahan.*.bahan_baku_id' => 'bahan baku', 'bahan.*.gram_per_porsi' => 'gram per porsi'];
    }

    protected function menuRoute(): ?Menu
    {
        $menu = $this->route('menu');

        return $menu instanceof Menu ? $menu : null;
    }
}

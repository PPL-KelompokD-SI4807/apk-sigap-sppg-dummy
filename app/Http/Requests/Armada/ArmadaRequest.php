<?php

namespace App\Http\Requests\Armada;

use App\Enums\StatusArmada;
use App\Http\Requests\Concerns\HasAiDraftId;
use App\Http\Requests\Concerns\ResolvesTargetSppg;
use App\Models\Armada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aturan validasi armada. Plat nomor dinormalkan (huruf besar, spasi tunggal) dan unik per SPPG;
 * pengecekan unik mencakup armada terhapus lunak karena indeks unik DB juga menghitungnya.
 */
abstract class ArmadaRequest extends FormRequest
{
    use HasAiDraftId, ResolvesTargetSppg;

    abstract protected function creating(): bool;

    protected function prepareForValidation(): void
    {
        $plat = $this->input('plat_nomor');

        if (is_string($plat)) {
            $this->merge(['plat_nomor' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $plat) ?? $plat))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->creating() ? 'required' : 'sometimes';
        $armada = $this->route('armada');
        $sppgId = $armada instanceof Armada ? (int) $armada->sppg_id : $this->targetSppgId();

        $rules = [
            'plat_nomor' => [$req, 'string', 'max:20', 'regex:/^[A-Z]{1,2} \d{1,4}( [A-Z]{1,3})?$/',
                Rule::unique('armadas', 'plat_nomor')->where('sppg_id', $sppgId)
                    ->ignore($armada instanceof Armada ? $armada->getKey() : null)],
            'jenis' => [$req, 'string', 'max:50'],
            'kapasitas_box' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::enum(StatusArmada::class)],
        ];

        if ($this->creating()) {
            $rules['sppg_id'] = $this->sppgRule();
        }

        return $rules + $this->aiDraftRules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'plat_nomor.regex' => 'Format plat nomor tidak valid (contoh: B 1234 XY).',
            'plat_nomor.unique' => 'Plat nomor sudah terdaftar di SPPG ini (mungkin pada data yang sudah dihapus).',
        ];
    }
}

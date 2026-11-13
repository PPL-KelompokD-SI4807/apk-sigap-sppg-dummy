<?php

namespace App\Http\Requests\LaporanBgn;

use App\Enums\JenisLaporanBgn;
use App\Models\LaporanBgn;
use App\Services\LaporanBgn\LaporanBgnService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Query laporan BGN (R07-2). Jenis laporan datang dari default rute (`jenis`), bukan dari input pengguna.
 *
 * Otorisasi (LaporanBgnPolicy) dijalankan SEBELUM validasi sehingga peran tanpa hak selalu 403, bukan 422.
 * Non-admin: sppg_id opsional dan wajib sama dengan SPPG aktifnya (lintas SPPG = 403). Admin: sppg_id wajib.
 */
class LaporanBgnRequest extends FormRequest
{
    public function jenis(): JenisLaporanBgn
    {
        return JenisLaporanBgn::from((string) $this->route('jenis'));
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $diminta = $this->query('sppg_id');
        $sppgId = is_scalar($diminta) && ctype_digit((string) $diminta) ? (int) $diminta : null;

        if ($user->isAdmin()) {
            $sppgId ??= 0;
        } elseif ($diminta === null) {
            $sppgId = (int) $user->sppgAktifId();
        } else {
            $sppgId ??= -1;
        }

        return Gate::forUser($user)->allows('lihat', [LaporanBgn::class, $this->jenis(), $sppgId]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $aturan = [
            'sppg_id' => $this->user()?->isAdmin()
                ? ['required', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')]
                : ['nullable', 'integer'],
            'akuntan_id' => ['nullable', 'integer', 'min:1'],
            'kepala_sppg_id' => ['nullable', 'integer', 'min:1'],
        ];

        return $aturan + match ($this->jenis()) {
            JenisLaporanBgn::Harian => ['tanggal' => ['required', 'date_format:Y-m-d']],
            JenisLaporanBgn::Porsi, JenisLaporanBgn::DuaMingguan => [
                'tanggal_awal' => ['required', 'date_format:Y-m-d'],
                'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            ],
            default => ['bulan' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tanggal.date_format' => 'Format tanggal harus YYYY-MM-DD.',
            'tanggal_awal.date_format' => 'Format tanggal_awal harus YYYY-MM-DD.',
            'tanggal_akhir.date_format' => 'Format tanggal_akhir harus YYYY-MM-DD.',
            'tanggal_akhir.after_or_equal' => 'tanggal_akhir tidak boleh sebelum tanggal_awal.',
            'bulan.regex' => 'Format bulan harus YYYY-MM.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! in_array($this->jenis(), [JenisLaporanBgn::Porsi, JenisLaporanBgn::DuaMingguan], true) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                [$awal, $akhir] = $this->periode();

                if ($awal->diffInDays($akhir) + 1 > LaporanBgnService::MAKS_HARI_PERIODE) {
                    $validator->errors()->add('tanggal_akhir', 'Periode laporan maksimal '.LaporanBgnService::MAKS_HARI_PERIODE.' hari.');
                }
            },
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function periode(): array
    {
        $zona = (string) config('operasional.timezone', 'Asia/Jakarta');
        $data = $this->all();

        return match ($this->jenis()) {
            JenisLaporanBgn::Harian => [
                $t = CarbonImmutable::createFromFormat('Y-m-d', (string) $data['tanggal'], $zona)->startOfDay(), $t,
            ],
            JenisLaporanBgn::Porsi, JenisLaporanBgn::DuaMingguan => [
                CarbonImmutable::createFromFormat('Y-m-d', (string) $data['tanggal_awal'], $zona)->startOfDay(),
                CarbonImmutable::createFromFormat('Y-m-d', (string) $data['tanggal_akhir'], $zona)->startOfDay(),
            ],
            default => [
                $a = CarbonImmutable::createFromFormat('Y-m-d', $data['bulan'].'-01', $zona)->startOfDay(), $a->endOfMonth()->startOfDay(),
            ],
        };
    }
}

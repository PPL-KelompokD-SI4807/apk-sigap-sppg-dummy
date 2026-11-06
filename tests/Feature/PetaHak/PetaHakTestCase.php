<?php

namespace Tests\Feature\PetaHak;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use Tests\Feature\Pembelian\PembelianTestCase;

/**
 * Dasar tes peta hak server (docs/api/peta-hak.md): sembilan profil pengguna di SPPG A.
 * Fixture dibuat tanpa pengguna login (BelongsToSppg memaksa sppg_id = SPPG pengguna login).
 */
abstract class PetaHakTestCase extends PembelianTestCase
{
    protected User $relawanDistribusiA;

    protected User $relawanTanpaUnitA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relawanDistribusiA = $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $this->relawanTanpaUnitA = $this->userOf(Role::Relawan, $this->sppgA);
        $this->relawanTanpaUnitA->forceFill(['unit' => null])->saveQuietly();
        $this->relawanTanpaUnitA = $this->relawanTanpaUnitA->fresh();
    }

    /** @return array<string, User> sembilan profil: admin, kepala_sppg, ahli_gizi, akuntan, staf_gudang, relawan.dapur, relawan.distribusi, relawan.tanpa_unit, investor */
    protected function profil(): array
    {
        return [
            'admin' => $this->admin,
            'kepala_sppg' => $this->kepalaA,
            'ahli_gizi' => $this->ahliGiziA,
            'akuntan' => $this->akuntanA,
            'staf_gudang' => $this->stafGudangA,
            'relawan.dapur' => $this->relawanA,
            'relawan.distribusi' => $this->relawanDistribusiA,
            'relawan.tanpa_unit' => $this->relawanTanpaUnitA,
            'investor' => $this->investorAB,
        ];
    }

    /** Login penuh sebagai $user (investor: SPPG A sebagai SPPG aktif). */
    protected function masuk(User $user): static
    {
        return $this->sebagai($user, $user->role === Role::Investor ? $this->sppgA : null);
    }
}

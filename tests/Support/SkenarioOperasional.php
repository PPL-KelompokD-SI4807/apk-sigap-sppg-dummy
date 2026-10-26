<?php

namespace Tests\Support;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\JadwalDistribusi;
use App\Models\PemeriksaanMutu;
use App\Models\TitikLayanan;
use App\Models\User;

/** Pembuat data fiktif untuk tes PBI 2, 15, 16. Buat data SEBELUM Sanctum::actingAs (scope tenant). */
trait SkenarioOperasional
{
    use CreatesSppg;

    protected function pengguna(Role $role, int $sppgId, ?RelawanUnit $unit = null): User
    {
        return User::factory()->role($role, $unit)->create(['sppg_id' => $role === Role::Admin ? null : $sppgId]);
    }

    /**
     * Jadwal distribusi siap berangkat dengan batch produksi bermutu Layak.
     *
     * @param  array<string, mixed>  $titik
     * @param  array<string, mixed>  $jadwal
     */
    protected function jadwalSiap(int $sppgId, array $titik = [], array $jadwal = [], bool $layak = true): JadwalDistribusi
    {
        $titikLayanan = TitikLayanan::factory()->create(array_merge(['sppg_id' => $sppgId, 'jarak_km' => 5], $titik));
        $dist = JadwalDistribusi::factory()->siap()->create(array_merge([
            'sppg_id' => $sppgId,
            'titik_layanan_id' => $titikLayanan->id,
        ], $jadwal));

        PemeriksaanMutu::factory()->{$layak ? 'layak' : 'tidakLayak'}()->create([
            'sppg_id' => $sppgId,
            'jadwal_produksi_id' => $dist->jadwal_produksi_id,
        ]);

        return $dist;
    }
}

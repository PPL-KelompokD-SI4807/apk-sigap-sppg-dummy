<?php

namespace App\Services\Ai\Referensi;

use App\Models\User;

/**
 * Tool tulis yang memetakan rujukan nama -> ID di server (ResolverReferensi) dan dapat menyimpan rujukan AMBIGU sebagai
 * pilihan tertunda pada draf (payload `_pilihan`, hanya ID kandidat). Pengguna memilih lewat tombol Bot (ai_pilih)
 * atau di formulir Mini App; draf dengan pilihan tertunda tidak pernah dijalankan dari chat.
 *
 * Bentuk satu slot `_pilihan`: {jenis, kolom, indeks, dicari, jumlah, satuan, kandidat: list<int>, lebih_banyak}.
 */
interface MemakaiPilihanReferensi
{
    /**
     * Terapkan data master pilihan pengguna ($item, sudah diverifikasi milik SPPG pengguna) ke slot pada payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>|string payload baru, atau pesan (mis. satuan tidak kompatibel) bila tidak dapat dipakai
     */
    public function terapkanPilihan(User $user, array $payload, array $slot, KandidatReferensi $item): array|string;

    /**
     * Keterangan singkat slot untuk layar pilihan (mis. "10 gram per porsi").
     *
     * @param  array<string, mixed>  $slot
     */
    public function keteranganSlot(array $slot): string;
}

<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Enums\Role;
use App\Http\Requests\Pengguna\StoreUndanganRequest;
use App\Models\AiDraft;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use App\Services\Ai\Tools\MenerimaArgumenTerlindungi;
use App\Services\Users\InvitationService;

/**
 * `buat_undangan_pengguna` (Admin): DRAF tautan undangan Telegram untuk akun baru dengan peran (+ unit relawan) dan SPPG
 * tujuan. Dijalankan setelah Admin menekan Lanjutkan (POST /pengguna/undangan sebagai sub-request; StoreUndanganRequest +
 * InvitationService::prepareUserInvitation dijalankan lagi). Undangan peran Admin = aksi berbahaya (dua langkah).
 *
 * Token/tautan undangan TIDAK PERNAH melewati LLM: tool hanya membuat draf (tanpa token); token hanya ada di respons
 * eksekusi dan dikirim LANGSUNG ke Admin sebagai pesan terlindungi; draf/audit hanya menyimpan id + status.
 */
class BuatUndanganPenggunaTool extends PenggunaDraftTool implements MenerimaArgumenTerlindungi
{
    protected string $name = 'buat_undangan_pengguna';

    protected string $description = 'Siapkan DRAF tautan undangan akun baru (peran, unit relawan, SPPG tujuan). Undangan baru dibuat setelah Admin menekan tombol konfirmasi; tautan tidak pernah Anda lihat.';

    protected string $action = 'C';

    public function argumenTerlindungiSah(): array
    {
        return ['role', 'unit', 'sppg_id'];
    }

    protected function formRequest(): ?string
    {
        return StoreUndanganRequest::class;
    }

    protected function fields(): array
    {
        return ['properties' => self::skemaPeran(), 'required' => ['role']];
    }

    public function draftResource(): ?string
    {
        return 'undangan';
    }

    protected function toPayload(User $user, array $arguments): array
    {
        return self::normalisasiPeran(array_intersect_key($arguments, array_flip(['role', 'unit', 'sppg_id'])));
    }

    /** Mini App belum punya formulir undangan terisi dari draf (?draft=): tombol Ubah disembunyikan. */
    public function eksekusi(): ?SpesifikasiEksekusi
    {
        return SpesifikasiEksekusi::rest('api.v1.pengguna.undangan.store', 'POST', bisaUbah: false, responsRahasia: true);
    }

    public function berbahaya(AiDraft $draft): bool
    {
        return (((array) $draft->payload)['role'] ?? null) === Role::Admin->value;
    }

    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $p = (array) $draft->payload;
        $role = Role::tryFrom((string) ($p['role'] ?? ''));
        $sppg = $role?->tanpaSppgId() ? 'tidak terikat SPPG (lintas SPPG)' : (self::namaSppg($p['sppg_id'] ?? null) ?? 'tidak ditemukan');

        $peringatan = match ($role) {
            Role::Admin => 'PERINGATAN: peran Admin memberi akses penuh ke SEMUA SPPG dan data pengguna.',
            Role::Investor => 'Investor hanya membaca; kepemilikan SPPG diatur terpisah di Mini App setelah akun aktif.',
            default => null,
        };

        return new RingkasanDraf('Buat tautan undangan pengguna', [
            'Peran: '.self::labelPeran($p['role'] ?? null, $p['unit'] ?? null),
            'SPPG tujuan: '.$sppg,
            'Tautan sekali pakai, berlaku '.InvitationService::TTL_HOURS.' jam, dan hanya ditampilkan sekali di chat ini.',
        ], null, $peringatan);
    }

    /**
     * Tautan gagal sampai ke Admin: undangan yang baru dibuat (milik Admin ini, belum dipakai) dicabut lewat
     * InvitationService::revoke (aturan sama dengan DELETE /pengguna/undangan/{id}); hak Admin dicek ulang.
     */
    public function cabutHasil(User $user, AiDraft $draft): bool
    {
        $id = ((array) $draft->hasil)['undangan_id'] ?? null;

        if (! is_int($id) || ! $this->isAvailableFor($user)) {
            return false;
        }

        $undangan = Invitation::query()->whereKey($id)->where('created_by', $user->getKey())->whereNull('used_at')->first();

        if ($undangan === null) {
            return false;
        }

        app(InvitationService::class)->revoke($undangan);

        return true;
    }

    /**
     * Simpan hanya id undangan + status. Rahasia (tautan/token) hanya dikembalikan untuk pesan langsung ke Admin.
     */
    public function olahHasil(array $json, AiDraft $draft): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $token = is_string($data['token'] ?? null) ? $data['token'] : null;
        $tautan = is_string($data['deep_link'] ?? null) ? $data['deep_link'] : null;
        $rahasia = $tautan ?? ($token === null ? null : '/start '.InvitationService::DEEP_LINK_PREFIX.$token);

        return [['undangan_id' => is_int($data['id'] ?? null) ? $data['id'] : null, 'status' => 'aktif'], $rahasia];
    }
}

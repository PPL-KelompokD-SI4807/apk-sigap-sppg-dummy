<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SPPG aktif per permintaan (R-06a). Didaftarkan sebagai instance scoped (per request/job).
 *
 * - Admin: tidak berubah (lintas SPPG, header diabaikan) -> id() null.
 * - Peran satu-SPPG: selalu users.sppg_id. Header X-SPPG-Id yang berbeda ditolak middleware.
 * - Investor: header X-SPPG-Id WAJIB termasuk SPPG miliknya (pivot investor_sppg, SPPG belum
 *   dihapus). Tanpa header: SPPG satu-satunya bila hanya punya satu; selain itu tidak ada SPPG
 *   aktif (fail closed: SppgScope 1 = 0, Policy menolak, middleware membalas galat terstruktur).
 *
 * Nilai selalu dihitung dari basis data + header permintaan saat ini (bukan state klien) dan
 * di-cache hanya selama satu permintaan (reset() dipanggil middleware di awal permintaan).
 */
final class SppgAktif
{
    public const HEADER = 'X-SPPG-Id';

    /** Header berisi SPPG yang tidak boleh diakses pengguna (bukan miliknya / format salah). */
    public const DITOLAK = 'sppg_aktif_ditolak';

    /** Investor dengan lebih dari satu SPPG belum memilih SPPG aktif. */
    public const BELUM_DIPILIH = 'sppg_aktif_belum_dipilih';

    /** Investor belum memiliki SPPG apa pun. */
    public const TANPA_SPPG = 'tanpa_sppg';

    /** @var array<int, array{header: ?string, id: ?int, alasan: ?string}> */
    private array $cache = [];

    /** @var array<int, list<int>> */
    private array $milik = [];

    /** Identitas objek permintaan pemilik cache (cache tidak dipakai ulang lintas permintaan). */
    private ?int $permintaan = null;

    public function reset(): void
    {
        $this->cache = [];
        $this->milik = [];
    }

    /** SPPG aktif pengguna, atau null (admin / tidak ada / ditolak). */
    public function id(User $user): ?int
    {
        return $this->resolve($user)['id'];
    }

    /**
     * Hasil resolusi lengkap. `alasan` = null bila sah (termasuk admin).
     *
     * @return array{id: ?int, alasan: ?string}
     */
    public function resolve(User $user): array
    {
        $this->segarkanBilaPermintaanBaru();
        $header = self::headerPermintaan();
        $key = (int) $user->getKey();
        $cached = $this->cache[$key] ?? null;

        if ($cached !== null && $cached['header'] === $header) {
            return ['id' => $cached['id'], 'alasan' => $cached['alasan']];
        }

        $hasil = $this->hitung($user, $header);
        $this->cache[$key] = ['header' => $header] + $hasil;

        return $hasil;
    }

    /**
     * ID SPPG (belum dihapus) yang dimiliki investor, terurut. Peran lain: [].
     *
     * @return list<int>
     */
    public function milik(User $user): array
    {
        if ($user->role !== Role::Investor) {
            return [];
        }

        $this->segarkanBilaPermintaanBaru();
        $key = (int) $user->getKey();

        return $this->milik[$key] ??= DB::table('investor_sppg')
            ->join('sppgs', 'sppgs.id', '=', 'investor_sppg.sppg_id')
            ->where('investor_sppg.user_id', $key)
            ->whereNull('sppgs.deleted_at')
            ->orderBy('sppgs.id')
            ->pluck('sppgs.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array{id: ?int, alasan: ?string} */
    private function hitung(User $user, ?string $header): array
    {
        if ($user->isAdmin()) {
            return ['id' => null, 'alasan' => null];
        }

        $diminta = $header === null ? null : (preg_match('/^[1-9][0-9]{0,18}$/', $header) === 1 ? (int) $header : 0);

        if ($user->role !== Role::Investor) {
            $sendiri = $user->sppg_id === null ? null : (int) $user->sppg_id;

            // SPPG peran lain tidak pernah berubah oleh header: header lain hanya ditolak.
            return ['id' => $sendiri, 'alasan' => $diminta === null || ($sendiri !== null && $diminta === $sendiri) ? null : self::DITOLAK];
        }

        $milik = $this->milik($user);

        if ($diminta !== null) {
            return in_array($diminta, $milik, true)
                ? ['id' => $diminta, 'alasan' => null]
                : ['id' => null, 'alasan' => self::DITOLAK];
        }

        return match (count($milik)) {
            0 => ['id' => null, 'alasan' => self::TANPA_SPPG],
            1 => ['id' => $milik[0], 'alasan' => null],
            default => ['id' => null, 'alasan' => self::BELUM_DIPILIH],
        };
    }

    private function segarkanBilaPermintaanBaru(): void
    {
        $id = app()->bound('request') ? spl_object_id(app('request')) : null;

        if ($id !== $this->permintaan) {
            $this->reset();
            $this->permintaan = $id;
        }
    }

    private static function headerPermintaan(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $value = app('request')->headers->get(self::HEADER);

        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}

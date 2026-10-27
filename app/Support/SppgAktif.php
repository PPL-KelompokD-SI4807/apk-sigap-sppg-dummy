<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Sppg;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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

    /**
     * Penimpaan SPPG aktif eksplisit di luar HTTP (Bot, R-02), hanya selama untuk() berjalan.
     *
     * @var array<int, int>
     */
    private array $penimpa = [];

    /**
     * Penimpa baca-saja admin (Bot, R-02), hanya selama untukBacaAdmin() berjalan.
     *
     * @var array<int, int>
     */
    private array $bacaAdmin = [];

    public function reset(): void
    {
        $this->cache = [];
        $this->milik = [];
    }

    /**
     * Buang cache pivot investor_sppg milik $user agar pembacaan berikutnya segar dari basis data
     * (jalur keputusan Bot: pivot dapat dicabut antara pemeriksaan dan pemanggilan service).
     */
    public function segarkanMilik(User $user): void
    {
        unset($this->milik[(int) $user->getKey()], $this->cache[(int) $user->getKey()]);
    }

    /**
     * Jalankan $callback dengan SPPG aktif $user = $sppgId (tanpa header HTTP), mis. di job Bot.
     *
     * - Investor: $sppgId wajib termasuk SPPG miliknya (pivot investor_sppg, SPPG belum dihapus).
     * - Peran satu-SPPG: wajib users.sppg_id sendiri. Admin: tidak didukung (tidak ada SPPG aktif).
     * - Penimpaan hanya berlaku untuk $user ini, selalu dibersihkan di finally (aman untuk worker
     *   yang berjalan lama), dan bersarang dengan benar. Selain jalur ini tidak ada cara membuat
     *   SPPG aktif non-null di luar HTTP.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws AuthorizationException bila SPPG bukan milik/penempatan $user
     */
    public function untuk(User $user, int $sppgId, Closure $callback): mixed
    {
        $this->segarkanMilik($user);

        $sah = match (true) {
            $user->isAdmin() => false,
            $user->role === Role::Investor => in_array($sppgId, $this->milik($user), true),
            default => $user->sppg_id !== null && (int) $user->sppg_id === $sppgId,
        };

        if (! $sah || $sppgId <= 0) {
            throw new AuthorizationException('SPPG tidak termasuk hak pengguna.');
        }

        $key = (int) $user->getKey();
        $sebelumnya = $this->penimpa[$key] ?? null;
        $this->penimpa[$key] = $sppgId;

        try {
            return $callback();
        } finally {
            if ($sebelumnya === null) {
                unset($this->penimpa[$key]);
            } else {
                $this->penimpa[$key] = $sebelumnya;
            }
        }
    }

    /**
     * Penimpa BACA-SAJA untuk admin (Bot R-02): selama $callback berjalan, SppgBound::apply membatasi query
     * tool admin ke $sppgId. Hanya MEMPERSEMPIT akses admin (yang memang lintas SPPG), tidak pernah melebarkan.
     *
     * - Hanya untuk admin aktif; peran lain -> AuthorizationException (tidak pernah aktif untuk non-admin).
     * - SPPG wajib ada dan belum dihapus.
     * - TIDAK mengubah id()/resolve(): SppgScope, BelongsToSppg, Policy, dan daftar tool admin tetap sama.
     * - Baca-saja: $callback dijalankan di transaksi yang SELALU di-rollback pada koneksi DB BAWAAN, jadi tulisan DB
     *   di koneksi itu (termasuk token bot_aksi) tidak tersimpan. Efek samping NON-DB (antrean, event, notifikasi,
     *   cache, HTTP) TIDAK dibatalkan: pemanggil tidak boleh memicunya di dalam closure (layar Bot hanya membaca).
     * - Dibersihkan di finally (aman untuk worker yang berjalan lama) dan bersarang dengan benar.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws AuthorizationException
     */
    public function untukBacaAdmin(User $user, int $sppgId, Closure $callback): mixed
    {
        if (! $user->isAdmin() || ! $user->canAuthenticate() || $sppgId <= 0
            || ! Sppg::query()->withoutGlobalScopes()->whereKey($sppgId)->whereNull('deleted_at')->exists()) {
            throw new AuthorizationException('SPPG tidak dapat dibaca.');
        }

        $key = (int) $user->getKey();
        $sebelumnya = $this->bacaAdmin[$key] ?? null;
        $this->bacaAdmin[$key] = $sppgId;
        DB::beginTransaction();

        try {
            return $callback();
        } finally {
            DB::rollBack();

            if ($sebelumnya === null) {
                unset($this->bacaAdmin[$key]);
            } else {
                $this->bacaAdmin[$key] = $sebelumnya;
            }
        }
    }

    /** SPPG baca-saja admin yang sedang aktif (untukBacaAdmin), atau null. Selalu null untuk non-admin. */
    public function sppgBacaAdmin(User $user): ?int
    {
        return $user->isAdmin() ? ($this->bacaAdmin[(int) $user->getKey()] ?? null) : null;
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
        $key = (int) $user->getKey();

        if (isset($this->penimpa[$key])) {
            return ['id' => $this->penimpa[$key], 'alasan' => null];
        }

        $header = self::headerPermintaan();
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

        $diminta = self::idDariHeader($header);

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

    /**
     * Nilai header -> ID diminta. null = tanpa header; 0 = format tidak sah (selalu ditolak).
     * Hanya bilangan bulat positif tanpa nol di depan, MAKS. 17 digit (R06a-S-10): jauh di bawah
     * PHP_INT_MAX sehingga tidak pernah terjadi pembulatan/clamp diam-diam saat konversi (int).
     */
    public static function idDariHeader(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }

        return preg_match('/^[1-9][0-9]{0,16}$/D', $header) === 1 ? (int) $header : 0;
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

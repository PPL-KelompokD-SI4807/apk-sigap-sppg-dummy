<?php

namespace App\Services\Ai\Referensi;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Memetakan rujukan NAMA (atau ID) dari pengguna/LLM ke satu data master milik SPPG pengguna, di SERVER (bukan oleh
 * LLM). Dipakai tool tulis Agen AI (mis. komposisi bahan pada buat_menu/ubah_menu) agar pengguna tidak pernah
 * diminta menyebut ID.
 *
 * Keamanan:
 * - Hanya bila pengguna berhak MEMBACA jenis data itu (Rbac huruf R, bukan "*" data sendiri, + Policy viewAny) dan
 *   punya SPPG (users.sppg_id; Admin lintas SPPG / Investor / tanpa SPPG = ditolak, fail closed).
 * - Query selalu dibatasi kolom SPPG = users.sppg_id (selain SppgScope); data terhapus (soft delete) tidak ikut.
 *   ID dari LLM (hanya bila nama tidak disebut) dicek dengan batasan yang sama: ID milik SPPG lain = "tidak ada".
 * - Nama = DATA: hanya parameter terikat (binding), karakter LIKE (! % _) di-escape dengan ESCAPE '!' sehingga
 *   %, _, dan \ dicocokkan apa adanya. Nama dinormalisasi (karakter kontrol dibuang, spasi dirapikan) dan dibatasi
 *   MAKS_PANJANG karakter. Nama hasil dari basis data diratakan satu baris + dipotong (KandidatReferensi).
 *
 * Urutan cocok: sama persis (tanpa beda huruf besar) -> awalan -> mengandung. Tingkat pertama yang menghasilkan data
 * menentukan: 1 = tepat, >1 = ambigu (maks MAKS_PILIHAN pilihan, tidak pernah menebak), 0 di semua tingkat = tidak ada.
 */
class ResolverReferensi
{
    public const MAKS_PILIHAN = 5;

    public const MAKS_PANJANG = 100;

    public function bolehMembaca(User $user, JenisReferensi $jenis): bool
    {
        return $user->canAuthenticate()
            && $this->sppgId($user) !== null
            && Rbac::can($user, $jenis->modul, 'R')
            && ! Rbac::isSelfOnly($user, $jenis->modul, 'R')
            && Gate::forUser($user)->allows('viewAny', $jenis->model);
    }

    /**
     * Rujukan gabungan dari argumen tool. Bila NAMA disebut, resolusi SELALU memakai nama (ID dari LLM tidak dipercaya
     * dan tidak pernah memotong pemilihan ambigu): hasil nama tepat = dipakai (ID LLM yang berbeda diabaikan), ambigu
     * = slot pilihan, tidak ada = tidak ditemukan. ID hanya dipakai bila nama tidak disebut, dan tetap diverifikasi
     * milik SPPG pengguna (denganId).
     */
    public function resolusi(User $user, JenisReferensi $jenis, mixed $nama, mixed $id): HasilResolusi
    {
        if (is_string($nama) && self::normalisasi($nama) !== '') {
            return $this->denganNama($user, $jenis, $nama);
        }

        $id = self::idBulat($id);

        if ($id !== null && $id > 0) {
            return $this->denganId($user, $jenis, $id);
        }

        // Tanpa nama dan ID kosong/null/<= 0 (placeholder model) = rujukan tidak valid: ditanyakan ("sebutkan nama
        // bahannya"), BUKAN "tidak ditemukan" (yang menawari membuat data master baru).
        return new HasilResolusi(HasilResolusi::TIDAK_VALID);
    }

    /**
     * ID bilangan bulat dari argumen JSON: int apa adanya; float bulat (5.0, lolos skema "integer") menjadi int;
     * selain itu (pecahan, NaN/INF, di luar rentang, string, bool) = null.
     */
    public static function idBulat(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_float($id) && is_finite($id) && floor($id) === $id && abs($id) <= 2 ** 53) {
            return (int) $id;
        }

        return null;
    }

    public function denganId(User $user, JenisReferensi $jenis, int $id): HasilResolusi
    {
        if (! $this->bolehMembaca($user, $jenis)) {
            return new HasilResolusi(HasilResolusi::DITOLAK, dicari: '#'.$id);
        }

        $baris = $this->dasar($user, $jenis)->whereKey($id)->limit(1)->get();

        return $baris->isEmpty()
            ? new HasilResolusi(HasilResolusi::TIDAK_ADA, dicari: '#'.$id)
            : new HasilResolusi(HasilResolusi::TEPAT, $this->kandidat($jenis, $baris->first()), dicari: '#'.$id);
    }

    /**
     * Data master milik SPPG pengguna untuk sekumpulan ID (urutan masukan dipertahankan; yang tidak ada dilewati).
     *
     * @param  list<int>  $ids
     * @return array<int, KandidatReferensi>
     */
    public function banyakId(User $user, JenisReferensi $jenis, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(self::idBulat(...), $ids), fn ($i) => $i !== null && $i > 0)));

        if ($ids === [] || ! $this->bolehMembaca($user, $jenis)) {
            return [];
        }

        $baris = $this->dasar($user, $jenis)->whereKey($ids)->limit(100)->get()->keyBy(fn (Model $m) => (int) $m->getKey());
        $hasil = [];

        foreach ($ids as $id) {
            if ($baris->has($id)) {
                $hasil[$id] = $this->kandidat($jenis, $baris->get($id));
            }
        }

        return $hasil;
    }

    public function denganNama(User $user, JenisReferensi $jenis, string $nama): HasilResolusi
    {
        $dicari = self::normalisasi($nama);

        if ($dicari === '') {
            return new HasilResolusi(HasilResolusi::TIDAK_VALID);
        }

        if (! $this->bolehMembaca($user, $jenis)) {
            return new HasilResolusi(HasilResolusi::DITOLAK, dicari: $dicari);
        }

        $kecil = mb_strtolower($dicari);
        $kolom = $this->kolom($jenis);
        $escape = self::escapeLike($kecil);

        $tingkat = [
            fn (Builder $q) => $q->whereRaw("LOWER({$kolom}) = ?", [$kecil]),
            fn (Builder $q) => $q->whereRaw("LOWER({$kolom}) LIKE ? ESCAPE '!'", [$escape.'%']),
            fn (Builder $q) => $q->whereRaw("LOWER({$kolom}) LIKE ? ESCAPE '!'", ['%'.$escape.'%']),
        ];

        foreach ($tingkat as $filter) {
            $baris = $filter($this->dasar($user, $jenis))
                ->orderBy($jenis->kolomNama)->orderBy((new $jenis->model)->getKeyName())
                ->limit(self::MAKS_PILIHAN + 1)
                ->get();

            if ($baris->count() === 1) {
                return new HasilResolusi(HasilResolusi::TEPAT, $this->kandidat($jenis, $baris->first()), dicari: $dicari);
            }

            if ($baris->count() > 1) {
                return new HasilResolusi(
                    HasilResolusi::AMBIGU,
                    kandidat: $baris->take(self::MAKS_PILIHAN)->map(fn (Model $m) => $this->kandidat($jenis, $m))->values()->all(),
                    lebihBanyak: $baris->count() > self::MAKS_PILIHAN,
                    dicari: $dicari,
                );
            }
        }

        return new HasilResolusi(HasilResolusi::TIDAK_ADA, dicari: $dicari);
    }

    /** Karakter format (bidi, zero-width) dibuang, kontrol/spasi beruntun menjadi satu spasi, dipotong MAKS_PANJANG. */
    public static function normalisasi(string $nama): string
    {
        $nama = (string) preg_replace('/\p{Cf}+/u', '', $nama);
        $nama = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $nama));

        return mb_substr($nama, 0, self::MAKS_PANJANG);
    }

    /** Escape pola LIKE dengan karakter escape '!' (dipakai bersama ESCAPE '!'): ! % _ menjadi harfiah. */
    public static function escapeLike(string $teks): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $teks);
    }

    /** @return Builder<Model> */
    private function dasar(User $user, JenisReferensi $jenis): Builder
    {
        /** @var Model $model */
        $model = new $jenis->model;
        $kolom = array_values(array_filter([$model->getKeyName(), $jenis->kolomNama, $jenis->kolomSatuan]));

        // SppgScope (bila ActingUser) + batasan eksplisit SPPG aktif pengguna; SoftDeletes mengecualikan data terhapus.
        return $jenis->model::query()
            ->where($model->qualifyColumn($jenis->kolomSppg), $this->sppgId($user) ?? 0)
            ->select(array_map(fn (string $k) => $model->qualifyColumn($k), $kolom));
    }

    private function kolom(JenisReferensi $jenis): string
    {
        /** @var Model $model */
        $model = new $jenis->model;

        return $model->getConnection()->getQueryGrammar()->wrap($model->qualifyColumn($jenis->kolomNama));
    }

    private function kandidat(JenisReferensi $jenis, Model $m): KandidatReferensi
    {
        $satuan = $jenis->kolomSatuan === null ? null : $m->getAttribute($jenis->kolomSatuan);

        return new KandidatReferensi((int) $m->getKey(), (string) $m->getAttribute($jenis->kolomNama), is_string($satuan) ? $satuan : null);
    }

    /**
     * SPPG pengguna = `users.sppg_id`, sumber yang SAMA dengan PilihanReferensiDraf::milik(), AiDraftExecutor, dan
     * StoreMenuRequest (exists bahan_bakus.sppg_id). Admin (lintas SPPG) dan Investor (baca saja, tanpa tool tulis)
     * tidak memakai resolusi nama (null = ditolak, fail closed).
     */
    private function sppgId(User $user): ?int
    {
        return $user->isAdmin() || $user->isInvestor() || $user->sppg_id === null ? null : (int) $user->sppg_id;
    }
}

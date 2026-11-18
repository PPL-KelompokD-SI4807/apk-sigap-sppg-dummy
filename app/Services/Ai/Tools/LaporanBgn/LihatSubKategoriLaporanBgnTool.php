<?php

namespace App\Services\Ai\Tools\LaporanBgn;

use App\Enums\JenisLaporanBgn;
use App\Http\Requests\LaporanBgn\LaporanBgnRequest;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Ai\Catalog\RecordPresenter;
use App\Services\Ai\Drafts\DraftValidator;
use App\Services\Ai\Tools\ArgumenTidakValid;
use App\Services\Ai\Tools\ReadTool;
use App\Services\LaporanBgn\SubKategoriOperasionalService;
use App\Support\Rbac;
use App\Support\SppgAktif;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Tool BACA `lihat_sub_kategori_laporan_bgn` (R07-5): 17 baris Biaya Operasional laporan BGN (Lampiran 30b-B harian /
 * 30d-B bulanan) per sub-kategori + status kelengkapan isian `sub_kategori` pengeluaran operasional.
 *
 * Hak (peta hak laporan BGN, config rbac.aksi_peran + LaporanBgnPolicy::lihat, sama dengan REST GET /laporan-bgn/*):
 *  - Ditawarkan bila R modul `keuangan` + aksi `lihat-laporan-bgn-agregat`: admin, kepala_sppg, akuntan, investor.
 *  - `tanggal` = jenis `harian` (30b) -> investor 403 (Q-4); `bulan` = jenis `bulanan_anggaran` (30d-B, agregat).
 *  - Otorisasi + validasi argumen dijalankan dengan Form Request REST yang SAMA (LaporanBgnRequest, dry-run
 *    DraftValidator, route `jenis` diisi server): aturan didefinisikan sekali, peran tanpa hak ditolak sebelum validasi.
 *  - Daftar pengeluaran yang belum bersub-kategori (id, tanggal, kategori, nominal, uraian tersamar) hanya untuk peran
 *    `lihat-laporan-bgn` (bukan investor: investor hanya angka agregat).
 *
 * Scope SPPG (anti-IDOR): `sppg_id` tidak pernah diterima dari LLM (argumen terlindungi, selalu dibuang). SPPG diikat ke
 * pengguna: peran satu-SPPG = SPPG aktifnya; investor = SPPG aktif tervalidasi atau satu SPPG MILIKNYA (pivot) yang
 * disebut lewat `sppg` (kode/nama persis); admin = SPPG baca-saja Bot (SppgAktif::sppgBacaAdmin) atau `sppg` (kode/nama
 * persis). Tidak cocok / bukan milik = penolakan yang sama (tanpa membocorkan keberadaan SPPG lain).
 *
 * Data tak tepercaya: kode/nama SPPG dan uraian pengeluaran ditulis pengguna. Kode/nama diratakan + dipotong di sini
 * (RecordPresenter::text); uraian SUDAH disamarkan oleh SubKategoriOperasionalService (redactPersonal: nama orang ->
 * inisial, NIK/HP/kontak -> [disamarkan], lalu text()). Hasil selalu membawa RecordPresenter::NOTE; AiAgentService
 * membungkus hasil tool dalam blok `<hasil_tool_{nonce}>`.
 * Tool ini hanya membaca (transaksi ReadTool di-rollback) dan tidak pernah memanggil tool lain atau membuat draf.
 */
class LihatSubKategoriLaporanBgnTool extends ReadTool
{
    protected string $name = 'lihat_sub_kategori_laporan_bgn';

    protected string $description = 'Lihat rincian Biaya Operasional laporan BGN (17 baris sub-kategori, Lampiran 30b-B/30d-B) SPPG pengguna: nominal per sub-kategori dari pengeluaran disetujui dan status kelengkapan (pengeluaran operasional yang belum diberi sub-kategori). Isi `bulan` (YYYY-MM, bawaan bulan ini) atau `tanggal` (YYYY-MM-DD, laporan harian). Hanya baca.';

    protected string $module = 'keuangan';

    protected string $action = 'R';

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulan' => ['type' => 'string', 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$', 'description' => 'Bulan laporan YYYY-MM (30d-B). Kosongkan bersama tanggal = bulan ini.'],
                'tanggal' => ['type' => 'string', 'format' => 'date', 'description' => 'Tanggal laporan harian YYYY-MM-DD (30b-B).'],
                'sppg' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Khusus admin dan investor: kode atau nama SPPG. Kosongkan untuk SPPG aktif.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function isAvailableFor(User $user): bool
    {
        return $user->canAuthenticate()
            && Rbac::can($user, $this->module, 'R')
            && Rbac::canAction($user, 'lihat-laporan-bgn-agregat');
    }

    /**
     * @throws AuthorizationException
     */
    protected function handle(User $user, array $arguments): array
    {
        $tanggal = isset($arguments['tanggal']) ? (string) $arguments['tanggal'] : null;
        $bulan = isset($arguments['bulan']) ? (string) $arguments['bulan'] : null;

        if ($tanggal !== null && $bulan !== null) {
            throw new ArgumenTidakValid('Isi salah satu saja: bulan atau tanggal.');
        }

        $zona = (string) config('operasional.timezone', 'Asia/Jakarta');
        $jenis = $tanggal !== null ? JenisLaporanBgn::Harian : JenisLaporanBgn::BulananAnggaran;
        $periode = $tanggal !== null ? ['tanggal' => $tanggal] : ['bulan' => $bulan ?? CarbonImmutable::now($zona)->format('Y-m')];
        $sppgId = $this->sppgTujuan($user, isset($arguments['sppg']) ? (string) $arguments['sppg'] : null);

        $jalankan = fn (): array => $this->susun($user, $sppgId, $jenis, $periode, $zona);

        // Investor: SPPG aktif ditimpa ke SPPG miliknya yang dipilih (SppgAktif::untuk memvalidasi pivot) agar
        // LaporanBgnPolicy dan SppgScope memakai SPPG yang sama. Peran lain: SPPG aktif sudah = SPPG tujuan.
        return $user->isInvestor() ? app(SppgAktif::class)->untuk($user, $sppgId, $jalankan) : $jalankan();
    }

    /**
     * @param  array<string, string>  $periode
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    private function susun(User $user, int $sppgId, JenisLaporanBgn $jenis, array $periode, string $zona): array
    {
        // Form Request REST yang sama: authorize (LaporanBgnPolicy::lihat, sebelum validasi) + rules + after.
        $cek = app(DraftValidator::class)->validate(LaporanBgnRequest::class, $periode + ['sppg_id' => $sppgId], $user, ['jenis' => $jenis->value]);

        if (! $cek->authorized) {
            throw new AuthorizationException('Laporan BGN ini tidak dapat diakses.');
        }

        if ($cek->errors !== []) {
            throw new ArgumenTidakValid('Argumen tidak valid: '.implode(' ', array_slice(array_merge(...array_values($cek->errors)), 0, 3)));
        }

        if ($jenis === JenisLaporanBgn::Harian) {
            $awal = CarbonImmutable::createFromFormat('Y-m-d', $periode['tanggal'], $zona)->startOfDay();
            $akhir = $awal;
        } else {
            $awal = CarbonImmutable::createFromFormat('Y-m-d', $periode['bulan'].'-01', $zona)->startOfDay();
            $akhir = $awal->endOfMonth()->startOfDay();
        }

        $sppg = Sppg::query()->whereKey($sppgId)->first(['id', 'kode', 'nama']);

        if ($sppg === null) {
            throw new AuthorizationException('SPPG tidak dapat diakses.');
        }

        $rinci = Rbac::canAction($user, 'lihat-laporan-bgn');
        $data = app(SubKategoriOperasionalService::class)->ringkas($sppgId, $awal, $akhir, $rinci);

        $hasil = [
            'laporan' => $jenis === JenisLaporanBgn::Harian ? 'Lampiran 30b-B (harian)' : 'Lampiran 30d-B (bulanan)',
            'sppg' => ['kode' => RecordPresenter::text((string) $sppg->kode, 60), 'nama' => RecordPresenter::text((string) $sppg->nama, 60)],
            'periode' => ['awal' => $awal->toDateString(), 'akhir' => $akhir->toDateString()],
            // Hanya baris berisi agar hemat token; baris lain bernilai 0.
            'baris_terisi' => array_values(array_filter($data['baris'], fn (array $b) => $b['nominal'] !== 0 || $b['transaksi'] !== 0)),
            'jumlah_baris_baku' => count($data['baris']),
            'total_operasional' => $data['total_operasional'],
            'kelengkapan' => $data['kelengkapan'],
        ];

        if ($rinci) {
            // `uraian` sudah disamarkan + dipotong oleh SubKategoriOperasionalService (tidak ada uraian mentah di sini).
            $hasil['belum_diisi'] = $data['belum_diisi'] ?? [];
            $hasil['belum_diisi_ada_lagi'] = (bool) ($data['belum_diisi_ada_lagi'] ?? false);
        }

        $hasil['catatan'] = RecordPresenter::NOTE;

        return $hasil;
    }

    /**
     * SPPG tujuan, selalu diikat ke pengguna yang bertindak (bukan argumen bebas LLM).
     *
     * @throws AuthorizationException
     */
    private function sppgTujuan(User $user, ?string $diminta): int
    {
        $diminta = $diminta === null ? null : trim($diminta);
        $diminta = $diminta === '' ? null : $diminta;
        $sppgAktif = app(SppgAktif::class);

        if ($user->isAdmin()) {
            $baca = $sppgAktif->sppgBacaAdmin($user);

            if ($diminta === null) {
                return $baca ?? throw new ArgumenTidakValid('Sebutkan kode atau nama SPPG yang laporannya ingin dilihat.');
            }

            // Penimpa baca-saja Bot hanya mempersempit: admin di dalamnya tidak dapat memilih SPPG lain.
            return $this->cocokkan($diminta, $baca === null ? null : [$baca]);
        }

        if ($user->isInvestor()) {
            if ($diminta === null) {
                return $user->sppgAktifId() ?? throw new ArgumenTidakValid('Anda memiliki beberapa SPPG: sebutkan kode atau nama SPPG milik Anda.');
            }

            return $this->cocokkan($diminta, $sppgAktif->milik($user));
        }

        $sendiri = $user->sppgAktifId() ?? throw new AuthorizationException('Pengguna tidak terikat SPPG.');

        return $diminta === null ? $sendiri : $this->cocokkan($diminta, [$sendiri]);
    }

    /**
     * Cocokkan kode/nama PERSIS (tanpa beda huruf besar) di antara SPPG yang boleh (null = semua, khusus admin).
     *
     * @param  list<int>|null  $boleh
     *
     * @throws AuthorizationException
     */
    private function cocokkan(string $diminta, ?array $boleh): int
    {
        $kecil = mb_strtolower($diminta);
        // Tabel langsung (seperti RingkasDashboardSayaTool): himpunan SPPG yang boleh ditentukan eksplisit di bawah,
        // bukan oleh SppgScope (investor belum ditimpa ke SPPG pilihan pada tahap ini).
        $query = DB::table('sppgs')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereRaw('LOWER(kode) = ?', [$kecil])->orWhereRaw('LOWER(nama) = ?', [$kecil]));

        if ($boleh !== null) {
            $query->whereIn('id', $boleh === [] ? [0] : $boleh);
        }

        $cocok = $query->limit(2)->pluck('id');

        // Tidak ada, ambigu, atau bukan milik: pesan sama (tanpa membocorkan SPPG lain).
        if ($cocok->count() !== 1) {
            throw new AuthorizationException('SPPG tidak ditemukan di antara SPPG yang dapat Anda akses.');
        }

        return (int) $cocok->first();
    }
}

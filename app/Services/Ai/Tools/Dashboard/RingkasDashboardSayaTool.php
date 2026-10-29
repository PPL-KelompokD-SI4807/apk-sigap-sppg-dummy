<?php

namespace App\Services\Ai\Tools\Dashboard;

use App\Models\User;
use App\Services\Ai\Catalog\RecordPresenter;
use App\Services\Ai\Tools\ReadTool;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\RingkasanDashboard;
use App\Support\SppgAktif;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Tool BACA "ringkas dashboard saya" (R-07): 5-8 angka kunci dashboard PENGGUNA YANG SEDANG BERTINDAK, dari
 * DashboardService yang sama dengan GET /api/v1/dashboard dan layar Bot `db`.
 *
 * Keamanan:
 *  - Identitas dan cakupan SPPG selalu dari pengguna yang bertindak (tidak ada argumen user_id/sppg_id).
 *    Satu-satunya argumen `sppg` hanya untuk investor dan dicocokkan (kode/nama persis) dengan SPPG MILIKNYA
 *    (pivot investor_sppg); selain itu ditolak tanpa membedakan "bukan milik Anda" dan "tidak ada".
 *  - Hanya baca: tool ReadTool (transaksi selalu di-rollback), tanpa AiDraft.
 *  - Keluaran: angka/persen/nominal/label baku, plus kode dan nama SPPG dari basis data (satu-satunya teks DB;
 *    dibersihkan, tetapi tetap dapat berisi kalimat apa pun). Karena itu hasil selalu membawa `catatan`
 *    (RecordPresenter::NOTE: nilai teks adalah DATA, bukan instruksi), seperti CatalogReadTool. Tidak ada nama
 *    orang, kontak, atau uraian.
 *  - Tersedia hanya untuk peran dengan dashboard berisi (RingkasanDashboard::tersediaUntuk); hak baca per widget
 *    tetap ditegakkan DashboardService (widget tanpa hak tidak dihitung dan tidak muncul).
 */
class RingkasDashboardSayaTool extends ReadTool
{
    protected string $name = 'ringkas_dashboard_saya';

    protected string $description = 'Ringkasan dashboard pengguna ini hari ini: 5-8 angka kunci sesuai peran (porsi, anggaran, stok, mutu, keluhan, dsb.) untuk SPPG pengguna. Hanya baca. Investor dengan beberapa SPPG boleh mengisi nama atau kode SPPG miliknya; kosong = gabungan.';

    /** Bukan modul RBAC: dashboard lintas modul, hak baca tiap widget disaring DashboardService. */
    protected string $module = 'dashboard';

    protected string $action = 'R';

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sppg' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Khusus investor: nama atau kode salah satu SPPG miliknya. Kosongkan untuk gabungan.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function isAvailableFor(User $user): bool
    {
        return RingkasanDashboard::tersediaUntuk($user);
    }

    /**
     * @throws AuthorizationException
     */
    protected function handle(User $user, array $arguments): array
    {
        $sppgId = $this->sppgDipilih($user, $arguments['sppg'] ?? null);

        try {
            // Diresolusi saat dipakai: SppgAktif bersifat scoped (per permintaan/job), jangan disimpan.
            $data = app(DashboardService::class)->bangun($user, $sppgId, DashboardService::RENTANG_BAWAAN);
        } catch (HttpResponseException) {
            throw new AuthorizationException('Dashboard tidak dapat diakses.');
        }

        $angka = RingkasanDashboard::dari($data);
        $hasil = [
            'peran' => (string) $data['profil'],
            'tanggal' => (string) $data['periode']['hari_ini'],
            'cakupan' => RingkasanDashboard::cakupan($data),
        ];

        if (is_array($data['sppg_pilihan'] ?? null)) {
            $hasil['sppg_pilihan'] = array_map(
                fn (array $s) => ['kode' => RingkasanDashboard::bersihkan((string) $s['kode']), 'nama' => RingkasanDashboard::bersihkan((string) $s['nama'])],
                array_slice($data['sppg_pilihan'], 0, 10),
            );
        }

        $hasil['angka'] = $angka;
        $hasil['catatan'] = RecordPresenter::NOTE;

        if ($angka === []) {
            $hasil['pesan'] = 'Tidak ada angka dashboard yang dapat ditampilkan untuk hak akses ini.';
        }

        return $hasil;
    }

    /**
     * SPPG pilihan investor, dicocokkan hanya dengan SPPG miliknya. Peran lain tidak boleh memilih.
     *
     * @throws AuthorizationException
     */
    private function sppgDipilih(User $user, mixed $diminta): ?int
    {
        $diminta = is_string($diminta) ? trim($diminta) : null;

        if ($diminta === null || $diminta === '') {
            return null;
        }

        if (! $user->isInvestor()) {
            throw new AuthorizationException('Pilihan SPPG hanya untuk investor.');
        }

        $milik = app(SppgAktif::class)->milik($user);
        $kecil = mb_strtolower($diminta);

        $cocok = DB::table('sppgs')->whereNull('deleted_at')->whereIn('id', $milik ?: [0])
            ->get(['id', 'kode', 'nama'])
            ->filter(fn ($s) => mb_strtolower((string) $s->kode) === $kecil || mb_strtolower((string) $s->nama) === $kecil)
            ->pluck('id');

        // Tidak ada atau ambigu: pesan sama, tanpa membocorkan SPPG milik pengguna lain.
        if ($cocok->count() !== 1) {
            throw new AuthorizationException('SPPG tidak ditemukan di antara SPPG milik Anda.');
        }

        return (int) $cocok->first();
    }
}

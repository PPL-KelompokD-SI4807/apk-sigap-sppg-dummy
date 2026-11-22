<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\PrioritasKeluhan;
use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusKeluhan;
use App\Services\Auth\AuditAuth;
use App\Services\Dashboard\Cakupan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widget Admin: ringkasan operasional lintas SPPG (angka per SPPG, tanpa daftar rinci), pengguna aktif per
 * peran, login gagal, antrean job/webhook, dan draf AI. Tidak memuat nama pengguna, IP, payload job, atau
 * isi draf AI. Widget "sistem" (login gagal, job, webhook) selalu mencakup seluruh sistem.
 */
final class AdminWidgets
{
    use Format;

    public function __construct(private readonly Cakupan $c, private readonly StokWidgets $stok) {}

    /** True bila Admin memilih satu SPPG (?sppg_id=); jika tidak, seluruh SPPG. */
    private function terpilih(): bool
    {
        return $this->c->mode === Cakupan::MODE_SATU;
    }

    /** @return array<string, mixed> */
    public function operasional(): array
    {
        $target = [];
        $realisasi = [];
        $distribusi = [];
        $keluhan = [];

        foreach ($this->c->pada($this->c->tabel('jadwal_produksis'), 'jadwal_produksis.tanggal_produksi')
            ->where('jadwal_produksis.status', '!=', StatusJadwalProduksi::Dibatalkan->value)
            ->selectRaw('jadwal_produksis.sppg_id as sppg_id, COALESCE(SUM(jadwal_produksis.target_porsi), 0) as n')
            ->groupBy('jadwal_produksis.sppg_id')->get() as $r) {
            $target[(int) $r->sppg_id] = (int) $r->n;
        }

        foreach ($this->c->pada(
            $this->c->tabel('log_produksis')->join('jadwal_produksis as jp', 'jp.id', '=', 'log_produksis.jadwal_produksi_id'),
            'jp.tanggal_produksi',
        )->whereNull('jp.deleted_at')->where('jp.status', '!=', StatusJadwalProduksi::Dibatalkan->value)
            ->selectRaw('log_produksis.sppg_id as sppg_id, COALESCE(SUM(log_produksis.realisasi_porsi), 0) as n')
            ->groupBy('log_produksis.sppg_id')->get() as $r) {
            $realisasi[(int) $r->sppg_id] = (int) $r->n;
        }

        foreach ($this->c->pada($this->c->tabel('jadwal_distribusis'), 'jadwal_distribusis.tanggal_distribusi')
            ->where('jadwal_distribusis.status', '!=', StatusJadwalDistribusi::Dibatalkan->value)
            ->selectRaw('jadwal_distribusis.sppg_id as sppg_id, COUNT(*) as total, SUM(CASE WHEN jadwal_distribusis.status = ? THEN 1 ELSE 0 END) as selesai', [StatusJadwalDistribusi::Diterima->value])
            ->groupBy('jadwal_distribusis.sppg_id')->get() as $r) {
            $distribusi[(int) $r->sppg_id] = ['total' => (int) $r->total, 'selesai' => (int) $r->selesai];
        }

        foreach ($this->c->tabel('feedback_keluhans')->whereIn('feedback_keluhans.status', StatusKeluhan::terbukaValues())
            ->selectRaw('feedback_keluhans.sppg_id as sppg_id, COUNT(*) as terbuka, SUM(CASE WHEN feedback_keluhans.prioritas = ? THEN 1 ELSE 0 END) as kritis', [PrioritasKeluhan::Kritis->value])
            ->groupBy('feedback_keluhans.sppg_id')->get() as $r) {
            $keluhan[(int) $r->sppg_id] = ['terbuka' => (int) $r->terbuka, 'kritis' => (int) $r->kritis];
        }

        $stok = $this->stok->perSppg();
        $items = [];

        foreach ($this->c->sppg as $s) {
            $id = $s['id'];
            $t = $target[$id] ?? 0;
            $re = $realisasi[$id] ?? 0;
            $items[] = [
                'sppg_id' => $id,
                'kode' => $s['kode'],
                'nama' => $s['nama'],
                'status_operasional' => $s['status_operasional'],
                'target_porsi' => $t,
                'realisasi_porsi' => $re,
                'capaian_persen' => $this->persen($re, $t),
                'distribusi_jadwal' => $distribusi[$id]['total'] ?? 0,
                'distribusi_selesai' => $distribusi[$id]['selesai'] ?? 0,
                'keluhan_terbuka' => $keluhan[$id]['terbuka'] ?? 0,
                'keluhan_kritis' => $keluhan[$id]['kritis'] ?? 0,
                'stok_di_bawah_minimum' => $stok[$id]['menipis'] ?? 0,
            ];
        }

        $jumlah = fn (string $k): int => array_sum(array_column($items, $k));

        return $this->widget($this->c->periodeHariIni(), [
            'jumlah_sppg' => count($items),
            'total' => [
                'target_porsi' => $jumlah('target_porsi'),
                'realisasi_porsi' => $jumlah('realisasi_porsi'),
                'capaian_persen' => $this->persen($jumlah('realisasi_porsi'), $jumlah('target_porsi')),
                'distribusi_jadwal' => $jumlah('distribusi_jadwal'),
                'distribusi_selesai' => $jumlah('distribusi_selesai'),
                'keluhan_terbuka' => $jumlah('keluhan_terbuka'),
                'keluhan_kritis' => $jumlah('keluhan_kritis'),
                'stok_di_bawah_minimum' => $jumlah('stok_di_bawah_minimum'),
            ],
            'per_sppg' => $items,
        ]);
    }

    /** @return array<string, mixed> */
    public function penggunaAktif(): array
    {
        $q = DB::table('users')->whereNull('users.deleted_at');

        if ($this->terpilih()) {
            $q->whereIn('users.sppg_id', $this->c->ids);
        }

        $per = [];

        foreach ($q->selectRaw('users.role as peran, SUM(CASE WHEN users.is_active = 1 THEN 1 ELSE 0 END) as aktif, SUM(CASE WHEN users.is_active = 1 THEN 0 ELSE 1 END) as nonaktif')
            ->groupBy('users.role')->get() as $r) {
            $per[(string) $r->peran] = ['aktif' => (int) $r->aktif, 'nonaktif' => (int) $r->nonaktif];
        }

        $items = array_map(fn (Role $r) => [
            'peran' => $r->value,
            'label' => $r->label(),
            'aktif' => $per[$r->value]['aktif'] ?? 0,
            'nonaktif' => $per[$r->value]['nonaktif'] ?? 0,
        ], Role::cases());

        return $this->widget($this->c->periodeSaatIni(), [
            'cakupan' => $this->terpilih() ? 'sppg_terpilih' : 'semua_sppg',
            'total_aktif' => array_sum(array_column($items, 'aktif')),
            'items' => $items,
            'labels' => array_column($items, 'label'),
            'series' => ['aktif' => array_column($items, 'aktif')],
        ]);
    }

    /** @return array<string, mixed> */
    public function loginGagal(): array
    {
        $event = [
            AuditAuth::MASUK_GAGAL => 'masuk_gagal',
            AuditAuth::TOKEN_SALAH => 'token_salah',
            AuditAuth::AKUN_TERKUNCI => 'akun_terkunci',
            AuditAuth::DITOLAK_TERKUNCI => 'ditolak_terkunci',
        ];
        $per = array_fill_keys(array_values($event), []);

        foreach ($this->c->antara(DB::table('audit_logs')->whereIn('audit_logs.event', array_keys($event)), 'audit_logs.created_at', $this->c->mulaiTren(), $this->c->hari())
            ->selectRaw('DATE(audit_logs.created_at) as hari, audit_logs.event as event, COUNT(*) as n')
            ->groupBy('hari', 'audit_logs.event')->get() as $r) {
            $per[$event[(string) $r->event]][$this->hari($r->hari)] = (int) $r->n;
        }

        $terkunci = DB::table('users')->whereNull('deleted_at')->where('locked_until', '>', now())->count();
        $hariIni = fn (string $k): int => $per[$k][$this->c->hari()] ?? 0;

        return $this->widget($this->c->periodeTren(), [
            'cakupan' => 'sistem',
            'hari_ini' => [
                'masuk_gagal' => $hariIni('masuk_gagal'),
                'token_salah' => $hariIni('token_salah'),
                'akun_terkunci' => $hariIni('akun_terkunci'),
            ],
            'total_rentang' => array_sum(array_map('array_sum', $per)),
            'akun_terkunci_saat_ini' => $terkunci,
            'tren' => [
                'labels' => $this->c->hariTren(),
                'series' => array_map(fn (array $p) => $this->c->deret($p), $per),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function antreanJobWebhook(): array
    {
        $antrean = null;
        $gagal = null;

        if (Schema::hasTable('jobs')) {
            $r = DB::table('jobs')->selectRaw('COUNT(*) as total, SUM(CASE WHEN reserved_at IS NULL THEN 0 ELSE 1 END) as diproses')->first();
            $antrean = ['menunggu_atau_ditunda' => (int) $r->total - (int) ($r->diproses ?? 0), 'sedang_diproses' => (int) ($r->diproses ?? 0)];
        }

        if (Schema::hasTable('failed_jobs')) {
            $r = DB::table('failed_jobs')->selectRaw('COUNT(*) as total, SUM(CASE WHEN failed_at >= ? THEN 1 ELSE 0 END) as rentang', [$this->c->mulaiTren()])->first();
            $gagal = ['total' => (int) $r->total, 'dalam_rentang' => (int) ($r->rentang ?? 0)];
        }

        // Webhook Telegram yang belum selesai diproses lebih dari 2 menit dianggap tersangkut.
        $w = DB::table('telegram_updates')->whereNull('processed_at')->where('created_at', '<', now()->subMinutes(2))
            ->selectRaw('COUNT(*) as n, MIN(created_at) as tertua')->first();

        return $this->widget($this->c->periodeSaatIni(), [
            'cakupan' => 'sistem',
            'antrean' => $antrean,
            'job_gagal' => $gagal,
            'webhook_tersangkut' => [
                'jumlah' => (int) ($w->n ?? 0),
                'tertua_menit' => ($w->tertua ?? null) === null ? null : (int) abs(now()->diffInMinutes(Carbon::parse($w->tertua))),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function drafAi(): array
    {
        $q = DB::table('ai_drafts')->whereNull('ai_drafts.deleted_at')->where('ai_drafts.status', 'pending');

        if ($this->terpilih()) {
            $this->filterSppg($q, 'ai_drafts.sppg_id');
        }

        $sekarang = now()->toDateTimeString();
        $per = [];

        foreach ($q->selectRaw('ai_drafts.module as modul, SUM(CASE WHEN ai_drafts.expires_at > ? THEN 1 ELSE 0 END) as aktif, SUM(CASE WHEN ai_drafts.expires_at > ? THEN 0 ELSE 1 END) as kedaluwarsa', [$sekarang, $sekarang])
            ->groupBy('ai_drafts.module')->orderBy('ai_drafts.module')->get() as $r) {
            $per[] = ['modul' => (string) $r->modul, 'menunggu_konfirmasi' => (int) $r->aktif, 'kedaluwarsa_belum_dibersihkan' => (int) $r->kedaluwarsa];
        }

        return $this->widget($this->c->periodeSaatIni(), [
            'cakupan' => $this->terpilih() ? 'sppg_terpilih' : 'semua_sppg',
            'menunggu_konfirmasi' => array_sum(array_column($per, 'menunggu_konfirmasi')),
            'kedaluwarsa_belum_dibersihkan' => array_sum(array_column($per, 'kedaluwarsa_belum_dibersihkan')),
            'per_modul' => $per,
        ]);
    }

    private function filterSppg(Builder $q, string $kolom): void
    {
        $q->whereIn($kolom, $this->c->ids);
    }
}

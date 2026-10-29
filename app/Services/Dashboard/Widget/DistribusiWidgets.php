<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\StatusArmada;
use App\Enums\StatusJadwalDistribusi;
use App\Services\Dashboard\Cakupan;

/**
 * Widget distribusi dan armada. Tidak memuat PIC titik layanan, no HP/SIM pengemudi, atau data
 * penerima manfaat; hanya nama titik layanan, plat armada, dan nama pengemudi (rekan kerja).
 */
final class DistribusiWidgets
{
    use Format;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Cakupan $c) {}

    /**
     * Jadwal distribusi hari ini (tidak dibatalkan) per status, termasuk porsi diterima dari bukti.
     *
     * @return array<string, array{jumlah: int, porsi: int, diterima: int}>
     */
    private function ringkasStatus(): array
    {
        return $this->memo['status'] ??= (function (): array {
            $hasil = [];

            foreach ($this->c->pada($this->c->tabel('jadwal_distribusis'), 'jadwal_distribusis.tanggal_distribusi')
                ->leftJoin('bukti_distribusis', fn ($j) => $j->on('bukti_distribusis.jadwal_distribusi_id', '=', 'jadwal_distribusis.id')->whereNull('bukti_distribusis.deleted_at'))
                ->selectRaw('jadwal_distribusis.status as status, COUNT(*) as jumlah, COALESCE(SUM(jadwal_distribusis.jumlah_porsi), 0) as porsi, COALESCE(SUM(bukti_distribusis.porsi_diterima), 0) as diterima')
                ->groupBy('jadwal_distribusis.status')->get() as $r) {
                $hasil[(string) $r->status] = ['jumlah' => (int) $r->jumlah, 'porsi' => (int) $r->porsi, 'diterima' => (int) $r->diterima];
            }

            return $hasil;
        })();
    }

    /** @return array{didistribusikan: int, diterima: int, jadwal: int, selesai: int} */
    public function porsi(): array
    {
        $jalan = [StatusJadwalDistribusi::Berangkat->value, StatusJadwalDistribusi::Diterima->value];
        $didistribusikan = 0;
        $diterima = 0;
        $jadwal = 0;

        foreach ($this->ringkasStatus() as $status => $v) {
            if ($status === StatusJadwalDistribusi::Dibatalkan->value) {
                continue;
            }

            $jadwal += $v['jumlah'];
            $diterima += $v['diterima'];

            if (in_array($status, $jalan, true)) {
                $didistribusikan += $v['porsi'];
            }
        }

        return [
            'didistribusikan' => $didistribusikan,
            'diterima' => $diterima,
            'jadwal' => $jadwal,
            'selesai' => $this->ringkasStatus()[StatusJadwalDistribusi::Diterima->value]['jumlah'] ?? 0,
        ];
    }

    /** @return array<string, mixed> Distribusi hari ini per titik layanan (kepala SPPG). */
    public function perTitik(): array
    {
        $baris = $this->c->pada($this->c->tabel('jadwal_distribusis'), 'jadwal_distribusis.tanggal_distribusi')
            ->join('titik_layanans', 'titik_layanans.id', '=', 'jadwal_distribusis.titik_layanan_id')
            ->leftJoin('bukti_distribusis', fn ($j) => $j->on('bukti_distribusis.jadwal_distribusi_id', '=', 'jadwal_distribusis.id')->whereNull('bukti_distribusis.deleted_at'))
            ->where('jadwal_distribusis.status', '!=', StatusJadwalDistribusi::Dibatalkan->value)
            ->groupBy('titik_layanans.id', 'titik_layanans.nama')
            ->orderBy('titik_layanans.nama')->orderBy('titik_layanans.id')
            ->selectRaw('titik_layanans.id as titik_id, titik_layanans.nama as nama, COUNT(*) as jadwal, COALESCE(SUM(jadwal_distribusis.jumlah_porsi), 0) as porsi, SUM(CASE WHEN jadwal_distribusis.status = ? THEN 1 ELSE 0 END) as selesai, COALESCE(SUM(bukti_distribusis.porsi_diterima), 0) as diterima, SUM(CASE WHEN bukti_distribusis.terlambat = 1 THEN 1 ELSE 0 END) as terlambat', [StatusJadwalDistribusi::Diterima->value])
            ->get();

        $items = $baris->map(fn ($r) => [
            'titik_layanan_id' => (int) $r->titik_id,
            'nama' => (string) $r->nama,
            'jumlah_jadwal' => (int) $r->jadwal,
            'jadwal_selesai' => (int) $r->selesai,
            'porsi_rencana' => (int) $r->porsi,
            'porsi_diterima' => (int) $r->diterima,
            'terlambat' => (int) $r->terlambat,
        ])->all();

        return $this->widget($this->c->periodeHariIni(), [
            'items' => $items,
            'labels' => array_column($items, 'nama'),
            'series' => [
                'porsi_rencana' => array_column($items, 'porsi_rencana'),
                'porsi_diterima' => array_column($items, 'porsi_diterima'),
            ],
        ]);
    }

    /** @return array<string, mixed> Rute dan titik layanan hari ini (relawan distribusi). */
    public function rute(): array
    {
        $baris = $this->c->pada($this->c->tabel('jadwal_distribusis'), 'jadwal_distribusis.tanggal_distribusi')
            ->join('titik_layanans', 'titik_layanans.id', '=', 'jadwal_distribusis.titik_layanan_id')
            ->leftJoin('armadas', 'armadas.id', '=', 'jadwal_distribusis.armada_id')
            ->leftJoin('pengemudis', 'pengemudis.id', '=', 'jadwal_distribusis.pengemudi_id')
            ->leftJoin('bukti_distribusis', fn ($j) => $j->on('bukti_distribusis.jadwal_distribusi_id', '=', 'jadwal_distribusis.id')->whereNull('bukti_distribusis.deleted_at'))
            ->where('jadwal_distribusis.status', '!=', StatusJadwalDistribusi::Dibatalkan->value)
            ->orderBy('jadwal_distribusis.jam_berangkat')->orderBy('jadwal_distribusis.id')->limit(50)
            ->get([
                'jadwal_distribusis.id as jadwal_id', 'titik_layanans.id as titik_id', 'titik_layanans.nama as titik_nama',
                'titik_layanans.jenis as titik_jenis', 'titik_layanans.alamat as titik_alamat', 'jadwal_distribusis.jam_berangkat',
                'jadwal_distribusis.jam_rencana_tiba', 'jadwal_distribusis.jumlah_porsi', 'jadwal_distribusis.status',
                'armadas.plat_nomor', 'pengemudis.nama as pengemudi_nama', 'bukti_distribusis.id as bukti_id',
            ]);

        $items = $baris->map(fn ($r) => [
            'jadwal_id' => (int) $r->jadwal_id,
            'titik_layanan' => [
                'id' => (int) $r->titik_id,
                'nama' => (string) $r->titik_nama,
                'jenis' => (string) $r->titik_jenis,
                'alamat' => (string) $r->titik_alamat,
            ],
            'jam_berangkat' => $r->jam_berangkat === null ? null : substr((string) $r->jam_berangkat, 0, 5),
            'jam_rencana_tiba' => $r->jam_rencana_tiba === null ? null : substr((string) $r->jam_rencana_tiba, 0, 5),
            'jumlah_porsi' => (int) $r->jumlah_porsi,
            'status' => (string) $r->status,
            'status_label' => StatusJadwalDistribusi::tryFrom((string) $r->status)?->label(),
            'armada_plat' => $r->plat_nomor,
            'pengemudi' => $r->pengemudi_nama,
            'bukti_ada' => $r->bukti_id !== null,
        ])->all();

        return $this->widget($this->c->periodeHariIni(), ['jumlah_titik' => count($items), 'items' => $items]);
    }

    /** @return array<string, mixed> Status kirim hari ini (jadwal per status + notifikasi perjalanan per status). */
    public function statusKirim(): array
    {
        $per = $this->ringkasStatus();

        $notif = [];

        foreach ($this->c->pada($this->c->tabel('notifikasi_perjalanans', false), 'notifikasi_perjalanans.created_at')
            ->selectRaw('status_kirim, COUNT(*) as jumlah')->groupBy('status_kirim')->get() as $r) {
            $notif[(string) $r->status_kirim] = (int) $r->jumlah;
        }

        return $this->widget($this->c->periodeHariIni(), [
            'total' => array_sum(array_column($per, 'jumlah')),
            'per_status' => array_map(fn (StatusJadwalDistribusi $s) => [
                'status' => $s->value,
                'label' => $s->label(),
                'jumlah' => $per[$s->value]['jumlah'] ?? 0,
            ], StatusJadwalDistribusi::cases()),
            'notifikasi_perjalanan' => [
                'terkirim' => $notif['terkirim'] ?? 0,
                'menunggu' => $notif['menunggu'] ?? 0,
                'gagal' => ($notif['gagal'] ?? 0) + ($notif['tidak_terkirim'] ?? 0),
            ],
        ]);
    }

    /** @return array<string, mixed> Jadwal hari ini yang sudah berangkat/diterima tetapi bukti distribusinya belum ada. */
    public function buktiBelumDiunggah(): array
    {
        $dasar = fn () => $this->c->pada($this->c->tabel('jadwal_distribusis'), 'jadwal_distribusis.tanggal_distribusi')
            ->leftJoin('bukti_distribusis', fn ($j) => $j->on('bukti_distribusis.jadwal_distribusi_id', '=', 'jadwal_distribusis.id')->whereNull('bukti_distribusis.deleted_at'))
            ->whereNull('bukti_distribusis.id')
            ->whereIn('jadwal_distribusis.status', [StatusJadwalDistribusi::Berangkat->value, StatusJadwalDistribusi::Diterima->value]);

        $jumlah = $dasar()->count();
        $items = $dasar()->join('titik_layanans', 'titik_layanans.id', '=', 'jadwal_distribusis.titik_layanan_id')
            ->orderBy('jadwal_distribusis.jam_rencana_tiba')->orderBy('jadwal_distribusis.id')->limit(10)
            ->get(['jadwal_distribusis.id as jadwal_id', 'titik_layanans.nama as titik_nama', 'jadwal_distribusis.jam_rencana_tiba', 'jadwal_distribusis.status'])
            ->map(fn ($r) => [
                'jadwal_id' => (int) $r->jadwal_id,
                'titik_layanan' => (string) $r->titik_nama,
                'jam_rencana_tiba' => $r->jam_rencana_tiba === null ? null : substr((string) $r->jam_rencana_tiba, 0, 5),
                'status' => (string) $r->status,
            ])->all();

        return $this->widget($this->c->periodeHariIni(), ['jumlah' => $jumlah, 'items' => $items]);
    }

    /** @return array<string, mixed> Armada per status dan pengemudi aktif (tanpa no HP/SIM). */
    public function armadaPengemudi(): array
    {
        $statusArmada = [];

        foreach ($this->c->tabel('armadas')->selectRaw('status, COUNT(*) as jumlah')->groupBy('status')->get() as $r) {
            $statusArmada[(string) $r->status] = (int) $r->jumlah;
        }

        $armada = $this->c->tabel('armadas')->orderBy('plat_nomor')->orderBy('id')->limit(30)
            ->get(['id', 'plat_nomor', 'jenis', 'kapasitas_box', 'status'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'plat_nomor' => (string) $r->plat_nomor,
                'jenis' => (string) $r->jenis,
                'kapasitas_box' => (int) $r->kapasitas_box,
                'status' => (string) $r->status,
            ])->all();

        $pengemudi = $this->c->tabel('pengemudis')->where('is_active', true)->orderBy('nama')->orderBy('id')->limit(30)
            ->get(['id', 'nama'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'nama' => (string) $r->nama])->all();

        $bertugas = $this->c->pada($this->c->tabel('jadwal_distribusis'), 'jadwal_distribusis.tanggal_distribusi')
            ->where('jadwal_distribusis.status', '!=', StatusJadwalDistribusi::Dibatalkan->value)
            ->selectRaw('COUNT(DISTINCT armada_id) as armada, COUNT(DISTINCT pengemudi_id) as pengemudi')->first();

        return $this->widget($this->c->periodeSaatIni(), [
            'armada_per_status' => array_map(fn (StatusArmada $s) => [
                'status' => $s->value,
                'label' => $s->label(),
                'jumlah' => $statusArmada[$s->value] ?? 0,
            ], StatusArmada::cases()),
            'armada' => $armada,
            'pengemudi_aktif' => $pengemudi,
            'bertugas_hari_ini' => ['armada' => (int) ($bertugas->armada ?? 0), 'pengemudi' => (int) ($bertugas->pengemudi ?? 0)],
        ]);
    }
}

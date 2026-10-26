<?php

namespace App\Services\Jadwal;

use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusPersetujuanMenu;
use App\Events\KekuranganBahan;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Perencanaan produksi (FR-06): validasi kapasitas harian, transisi status, dan estimasi kebutuhan
 * bahan dari komposisi menu x target porsi dibandingkan stok.
 */
class ProductionPlanningService
{
    /** Transisi status produksi yang valid. */
    private const TRANSISI = [
        'direncanakan' => ['dimasak', 'dibatalkan'],
        'dimasak' => ['selesai', 'dibatalkan'],
        'selesai' => [],
        'dibatalkan' => [],
    ];

    /** Kolom yang terkunci setelah jadwal tidak lagi berstatus direncanakan. */
    private const KOLOM_PERENCANAAN = ['menu_id', 'tanggal_produksi', 'jam_mulai_masak', 'target_porsi'];

    /**
     * @param  array{menu_id: int, tanggal_produksi: string, jam_mulai_masak?: ?string, target_porsi: int, catatan?: ?string}  $data
     */
    public function buat(User $pengguna, array $data): JadwalProduksi
    {
        return DB::transaction(function () use ($pengguna, $data): JadwalProduksi {
            $sppg = $this->kunciSppg((int) $pengguna->sppg_id);
            $this->pastikanMenuMilikSppg((int) $data['menu_id'], $sppg->id);
            $this->validasiKapasitas($sppg, $data['tanggal_produksi'], (int) $data['target_porsi']);

            $jadwal = new JadwalProduksi;
            $jadwal->fill([
                'menu_id' => $data['menu_id'],
                'tanggal_produksi' => $data['tanggal_produksi'],
                'jam_mulai_masak' => $data['jam_mulai_masak'] ?? null,
                'target_porsi' => $data['target_porsi'],
                'catatan' => $data['catatan'] ?? null,
                'status' => StatusJadwalProduksi::Direncanakan,
            ]);
            $jadwal->forceFill(['sppg_id' => $sppg->id, 'dibuat_oleh' => $pengguna->getKey()])->save();

            return $jadwal;
        });
    }

    /**
     * @param  array<string, mixed>  $data  subset: menu_id, tanggal_produksi, jam_mulai_masak, target_porsi, status, catatan
     */
    public function perbarui(JadwalProduksi $jadwal, array $data): JadwalProduksi
    {
        return DB::transaction(function () use ($jadwal, $data): JadwalProduksi {
            $sppg = $this->kunciSppg((int) $jadwal->sppg_id);
            $jadwal = JadwalProduksi::withoutGlobalScope(SppgScope::class)->whereKey($jadwal->getKey())->lockForUpdate()->firstOrFail();
            $berubah = $this->kolomBerubah($jadwal, $data);

            if ($jadwal->status !== StatusJadwalProduksi::Direncanakan && $berubah !== []) {
                throw ValidationException::withMessages([
                    $berubah[0] => ['Jadwal sudah berjalan atau ditutup; hanya status dan catatan yang dapat diubah.'],
                ]);
            }

            if (in_array('menu_id', $berubah, true)) {
                $this->pastikanMenuMilikSppg((int) $data['menu_id'], $sppg->id);
            }

            if (in_array('tanggal_produksi', $berubah, true) && $data['tanggal_produksi'] < now()->toDateString()) {
                throw ValidationException::withMessages(['tanggal_produksi' => ['Tanggal produksi tidak boleh di masa lalu.']]);
            }

            $statusBaru = isset($data['status']) ? StatusJadwalProduksi::from($data['status']) : $jadwal->status;

            if ($statusBaru !== $jadwal->status) {
                $this->validasiTransisi($jadwal->status, $statusBaru);
            }

            if ($statusBaru !== StatusJadwalProduksi::Dibatalkan
                && (in_array('tanggal_produksi', $berubah, true) || in_array('target_porsi', $berubah, true))) {
                $this->validasiKapasitas(
                    $sppg,
                    $data['tanggal_produksi'] ?? $jadwal->tanggal_produksi->toDateString(),
                    (int) ($data['target_porsi'] ?? $jadwal->target_porsi),
                    $jadwal->id,
                );
            }

            if (in_array('target_porsi', $berubah, true)) {
                $this->pastikanTargetMenampungDistribusi($jadwal, (int) $data['target_porsi']);
            }

            if ($statusBaru === StatusJadwalProduksi::Dibatalkan && $jadwal->status !== $statusBaru) {
                $this->batalkanDistribusiTerkait($jadwal);
            }

            $jadwal->fill(array_intersect_key($data, array_flip(['menu_id', 'tanggal_produksi', 'jam_mulai_masak', 'target_porsi', 'catatan'])));
            $jadwal->status = $statusBaru;
            $jadwal->save();

            return $jadwal;
        });
    }

    public function hapus(JadwalProduksi $jadwal): void
    {
        DB::transaction(function () use ($jadwal): void {
            $this->kunciSppg((int) $jadwal->sppg_id);

            if (! in_array($jadwal->status, [StatusJadwalProduksi::Direncanakan, StatusJadwalProduksi::Dibatalkan], true)) {
                throw ValidationException::withMessages(['status' => ['Hanya jadwal berstatus direncanakan atau dibatalkan yang dapat dihapus.']]);
            }

            if ($jadwal->logProduksi()->exists()) {
                throw ValidationException::withMessages(['jadwal' => ['Jadwal sudah memiliki log produksi dan tidak dapat dihapus.']]);
            }

            if ($jadwal->jadwalDistribusis()->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)->exists()) {
                throw ValidationException::withMessages(['jadwal' => ['Jadwal masih memiliki jadwal distribusi aktif. Batalkan distribusi terlebih dahulu.']]);
            }

            $jadwal->delete();
        });
    }

    /**
     * Tolak bila target > kapasitas harian atau total jadwal (tidak dibatalkan) pada tanggal tsb > kapasitas.
     * Dipanggil di dalam transaksi dengan baris SPPG terkunci agar dua permintaan bersamaan tidak lolos bersama.
     */
    public function validasiKapasitas(Sppg $sppg, string $tanggal, int $target, ?int $kecualiId = null): void
    {
        $kapasitas = (int) $sppg->kapasitas_porsi_harian;

        if ($target > $kapasitas) {
            throw ValidationException::withMessages([
                'target_porsi' => [sprintf('Target porsi %d melebihi kapasitas harian SPPG (%d porsi).', $target, $kapasitas)],
            ]);
        }

        $sudah = (int) JadwalProduksi::withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppg->id)
            ->whereDate('tanggal_produksi', $tanggal)
            ->where('status', '!=', StatusJadwalProduksi::Dibatalkan->value)
            ->when($kecualiId !== null, fn ($q) => $q->where('id', '!=', $kecualiId))
            ->sum('target_porsi');

        if ($sudah + $target > $kapasitas) {
            throw ValidationException::withMessages([
                'target_porsi' => [sprintf(
                    'Total jadwal tanggal %s menjadi %d porsi (%d sudah terjadwal + %d baru), melebihi kapasitas harian %d porsi. Sisa kapasitas %d porsi.',
                    $tanggal, $sudah + $target, $sudah, $target, $kapasitas, max(0, $kapasitas - $sudah),
                )],
            ]);
        }
    }

    /**
     * Estimasi kebutuhan bahan vs stok.
     *
     * @return array<string, mixed>
     */
    public function kebutuhanBahan(JadwalProduksi $jadwal): array
    {
        $menu = Menu::withoutGlobalScope(SppgScope::class)->withTrashed()
            ->where('sppg_id', $jadwal->sppg_id)->whereKey($jadwal->menu_id)->first();
        $konversi = (array) config('produksi.konversi_gram', []);
        $target = (int) $jadwal->target_porsi;
        $bahan = [];

        foreach ($menu?->bahanBakus()->withoutGlobalScope(SppgScope::class)->withTrashed()->get() ?? [] as $b) {
            $gram = (float) $b->pivot->gram_per_porsi;
            $kebutuhanGram = round($gram * $target, 2);
            $faktor = $konversi[mb_strtolower(trim($b->satuan))] ?? null;
            $stok = (float) $b->stok;

            if ($faktor === null) {
                $item = ['kebutuhan' => null, 'kekurangan' => null, 'cukup' => null, 'dapat_dibandingkan' => false];
            } else {
                $kebutuhan = round($kebutuhanGram / $faktor, 3);
                $kurang = max(0, StockService::milli($kebutuhan) - StockService::milli($stok));
                $item = [
                    'kebutuhan' => $kebutuhan,
                    'kekurangan' => $kurang / 1000,
                    'cukup' => $kurang === 0,
                    'dapat_dibandingkan' => true,
                ];
            }

            $bahan[] = [
                'bahan_baku_id' => $b->id,
                'nama' => $b->nama,
                'satuan' => $b->satuan,
                'gram_per_porsi' => $gram,
                'kebutuhan_gram' => $kebutuhanGram,
                ...$item,
                'stok' => $stok,
            ];
        }

        $kurang = array_filter($bahan, fn (array $b) => $b['cukup'] === false);

        return [
            'jadwal_produksi_id' => $jadwal->id,
            'tanggal_produksi' => $jadwal->tanggal_produksi->toDateString(),
            'target_porsi' => $target,
            'menu' => ['id' => $menu?->id, 'nama_menu' => $menu?->nama_menu],
            'bahan' => $bahan,
            'cukup' => $kurang === [],
            'jumlah_bahan_kurang' => count($kurang),
            'jumlah_bahan_tidak_dapat_dibandingkan' => count(array_filter($bahan, fn (array $b) => ! $b['dapat_dibandingkan'])),
        ];
    }

    /**
     * Hitung kekurangan bahan dan pancarkan KekuranganBahan bila ada (notifikasi Staf Gudang).
     *
     * @return list<array<string, mixed>>
     */
    public function umumkanKekurangan(JadwalProduksi $jadwal): array
    {
        $kebutuhan = $this->kebutuhanBahan($jadwal);
        $kurang = array_values(array_filter($kebutuhan['bahan'], fn (array $b) => $b['cukup'] === false));

        if ($kurang !== []) {
            Event::dispatch(new KekuranganBahan(
                (int) $jadwal->sppg_id,
                (int) $jadwal->id,
                $jadwal->tanggal_produksi->toDateString(),
                (string) $kebutuhan['menu']['nama_menu'],
                (int) $jadwal->target_porsi,
                array_map(fn (array $b) => [
                    'bahan_baku_id' => $b['bahan_baku_id'],
                    'nama' => $b['nama'],
                    'satuan' => $b['satuan'],
                    'kebutuhan' => (float) $b['kebutuhan'],
                    'stok' => (float) $b['stok'],
                    'kekurangan' => (float) $b['kekurangan'],
                ], $kurang),
            ));
        }

        return $kurang;
    }

    /** @return list<string> */
    private function kolomBerubah(JadwalProduksi $jadwal, array $data): array
    {
        $berubah = [];

        foreach (self::KOLOM_PERENCANAAN as $kolom) {
            if (! array_key_exists($kolom, $data)) {
                continue;
            }

            $lama = match ($kolom) {
                'tanggal_produksi' => $jadwal->tanggal_produksi->toDateString(),
                'jam_mulai_masak' => $jadwal->jam_mulai_masak === null ? null : substr((string) $jadwal->jam_mulai_masak, 0, 5),
                default => $jadwal->{$kolom},
            };
            $baru = $kolom === 'jam_mulai_masak' && $data[$kolom] !== null ? substr((string) $data[$kolom], 0, 5) : $data[$kolom];

            if ($lama !== null ? (string) $lama !== (string) $baru : $baru !== null) {
                $berubah[] = $kolom;
            }
        }

        return $berubah;
    }

    private function validasiTransisi(StatusJadwalProduksi $dari, StatusJadwalProduksi $ke): void
    {
        if (! in_array($ke->value, self::TRANSISI[$dari->value], true)) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Status tidak dapat berubah dari "%s" ke "%s".', $dari->label(), $ke->label())],
            ]);
        }
    }

    private function pastikanTargetMenampungDistribusi(JadwalProduksi $jadwal, int $targetBaru): void
    {
        $terjadwal = (int) $jadwal->jadwalDistribusis()
            ->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)->sum('jumlah_porsi');

        if ($targetBaru < $terjadwal) {
            throw ValidationException::withMessages([
                'target_porsi' => [sprintf('Target porsi %d lebih kecil dari total porsi distribusi yang sudah dijadwalkan (%d porsi).', $targetBaru, $terjadwal)],
            ]);
        }
    }

    private function batalkanDistribusiTerkait(JadwalProduksi $jadwal): void
    {
        $berjalan = $jadwal->jadwalDistribusis()
            ->whereIn('status', [StatusJadwalDistribusi::Berangkat->value, StatusJadwalDistribusi::Diterima->value])->exists();

        if ($berjalan) {
            throw ValidationException::withMessages([
                'status' => ['Jadwal tidak dapat dibatalkan karena distribusinya sudah berangkat atau diterima.'],
            ]);
        }

        $jadwal->jadwalDistribusis()
            ->whereIn('status', [StatusJadwalDistribusi::Menunggu->value, StatusJadwalDistribusi::Siap->value])
            ->get()
            ->each(fn ($d) => $d->update(['status' => StatusJadwalDistribusi::Dibatalkan]));
    }

    private function kunciSppg(int $sppgId): Sppg
    {
        return Sppg::query()->whereKey($sppgId)->lockForUpdate()->firstOrFail();
    }

    private function pastikanMenuMilikSppg(int $menuId, int $sppgId): void
    {
        // R3: kunci baris menu selama transaksi pemanggil (urutan kunci: sppg -> menu; PersetujuanMenuService
        // memakai menu -> pengajuan, tidak pernah mengunci sppg, jadi tidak ada urutan terbalik).
        $menu = Menu::withoutGlobalScope(SppgScope::class)->where('sppg_id', $sppgId)->whereKey($menuId)->lockForUpdate()->first();

        if ($menu === null) {
            throw ValidationException::withMessages(['menu_id' => ['Menu tidak ditemukan.']]);
        }

        // R-06b-1: hanya menu yang disetujui investor yang boleh dijadwalkan.
        if ($menu->status_persetujuan !== StatusPersetujuanMenu::Disetujui) {
            throw ValidationException::withMessages(['menu_id' => ['Menu belum disetujui investor sehingga belum dapat dijadwalkan.']]);
        }
    }
}

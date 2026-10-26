<?php

namespace App\Services\Jadwal;

use App\Enums\HasilMutu;
use App\Enums\StatusArmada;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Models\Armada;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\Pengemudi;
use App\Models\Scopes\SppgScope;
use App\Models\TitikLayanan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal distribusi (FR-06/FR-08): total porsi <= porsi produksi, armada/pengemudi tidak bentrok jam,
 * transisi status valid, dan gerbang mutu (hasil Tidak Layak menahan keberangkatan).
 */
class DistributionScheduleService
{
    private const TRANSISI = [
        'menunggu' => ['siap', 'dibatalkan'],
        'siap' => ['berangkat', 'dibatalkan'],
        'berangkat' => ['diterima', 'dibatalkan'],
        'diterima' => [],
        'dibatalkan' => [],
    ];

    /** Kolom yang hanya boleh berubah selagi jadwal masih menunggu/siap. */
    private const KOLOM_PERENCANAAN = [
        'titik_layanan_id', 'armada_id', 'pengemudi_id', 'petugas_id',
        'tanggal_distribusi', 'jam_berangkat', 'jam_rencana_tiba', 'jumlah_porsi',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(User $pengguna, array $data): JadwalDistribusi
    {
        return DB::transaction(function () use ($pengguna, $data): JadwalDistribusi {
            $produksi = $this->kunciProduksi((int) $data['jadwal_produksi_id'], (int) $pengguna->sppg_id);
            $this->pastikanProduksiAktif($produksi);

            $final = [
                'titik_layanan_id' => (int) $data['titik_layanan_id'],
                'armada_id' => $data['armada_id'] ?? null,
                'pengemudi_id' => $data['pengemudi_id'] ?? null,
                'petugas_id' => $data['petugas_id'] ?? ($pengguna->role->value === 'relawan' ? $pengguna->getKey() : null),
                'tanggal_distribusi' => $data['tanggal_distribusi'] ?? $produksi->tanggal_produksi->toDateString(),
                'jam_berangkat' => $this->jam($data['jam_berangkat'] ?? null),
                'jam_rencana_tiba' => $this->jam($data['jam_rencana_tiba'] ?? null),
                'jumlah_porsi' => (int) $data['jumlah_porsi'],
            ];

            $this->validasi($produksi, $final, null, null, null);

            $jadwal = new JadwalDistribusi;
            $jadwal->fill($final + [
                'jadwal_produksi_id' => $produksi->id,
                'catatan' => $data['catatan'] ?? null,
                'status' => StatusJadwalDistribusi::Menunggu,
            ]);
            $jadwal->forceFill(['sppg_id' => $produksi->sppg_id, 'dibuat_oleh' => $pengguna->getKey()])->save();

            return $jadwal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(JadwalDistribusi $jadwal, array $data): JadwalDistribusi
    {
        return DB::transaction(function () use ($jadwal, $data): JadwalDistribusi {
            $produksi = $this->kunciProduksi((int) $jadwal->jadwal_produksi_id, (int) $jadwal->sppg_id);
            $jadwal = JadwalDistribusi::withoutGlobalScope(SppgScope::class)->whereKey($jadwal->getKey())->lockForUpdate()->firstOrFail();

            $final = [
                'titik_layanan_id' => (int) ($data['titik_layanan_id'] ?? $jadwal->titik_layanan_id),
                'armada_id' => array_key_exists('armada_id', $data) ? $data['armada_id'] : $jadwal->armada_id,
                'pengemudi_id' => array_key_exists('pengemudi_id', $data) ? $data['pengemudi_id'] : $jadwal->pengemudi_id,
                'petugas_id' => array_key_exists('petugas_id', $data) ? $data['petugas_id'] : $jadwal->petugas_id,
                'tanggal_distribusi' => $data['tanggal_distribusi'] ?? $jadwal->tanggal_distribusi->toDateString(),
                'jam_berangkat' => array_key_exists('jam_berangkat', $data) ? $this->jam($data['jam_berangkat']) : $this->jam($jadwal->jam_berangkat),
                'jam_rencana_tiba' => array_key_exists('jam_rencana_tiba', $data) ? $this->jam($data['jam_rencana_tiba']) : $this->jam($jadwal->jam_rencana_tiba),
                'jumlah_porsi' => (int) ($data['jumlah_porsi'] ?? $jadwal->jumlah_porsi),
            ];

            $berubah = $this->kolomBerubah($jadwal, $final);

            if (! in_array($jadwal->status, [StatusJadwalDistribusi::Menunggu, StatusJadwalDistribusi::Siap], true) && $berubah !== []) {
                throw ValidationException::withMessages([
                    $berubah[0] => ['Distribusi sudah berangkat atau ditutup; hanya status dan catatan yang dapat diubah.'],
                ]);
            }

            $statusBaru = isset($data['status']) ? StatusJadwalDistribusi::from($data['status']) : $jadwal->status;

            if ($statusBaru !== $jadwal->status) {
                $this->validasiTransisi($jadwal->status, $statusBaru);

                if ($statusBaru === StatusJadwalDistribusi::Berangkat) {
                    $this->pastikanBolehBerangkat($jadwal);
                }
            }

            if ($berubah !== [] && $statusBaru !== StatusJadwalDistribusi::Dibatalkan) {
                $this->pastikanProduksiAktif($produksi);
                $this->validasi($produksi, $final, $jadwal->id, (int) $jadwal->titik_layanan_id, $jadwal->pengemudi_id === null ? null : (int) $jadwal->pengemudi_id);
            }

            $jadwal->fill($final + array_intersect_key($data, ['catatan' => true]));
            $jadwal->status = $statusBaru;
            $jadwal->save();

            return $jadwal;
        });
    }

    public function hapus(JadwalDistribusi $jadwal): void
    {
        if (! in_array($jadwal->status, [StatusJadwalDistribusi::Menunggu, StatusJadwalDistribusi::Siap, StatusJadwalDistribusi::Dibatalkan], true)) {
            throw ValidationException::withMessages(['status' => ['Distribusi yang sudah berangkat atau diterima tidak dapat dihapus.']]);
        }

        if ($jadwal->buktiDistribusi()->exists()) {
            throw ValidationException::withMessages(['jadwal' => ['Distribusi sudah memiliki bukti distribusi dan tidak dapat dihapus.']]);
        }

        $jadwal->delete();
    }

    /**
     * Gerbang mutu: distribusi hanya boleh berangkat bila pemeriksaan mutu TERBARU jadwal produksi
     * berhasil "Layak" (JadwalProduksi::isLayakDistribusi). Dipakai juga oleh modul lain yang
     * mengubah status distribusi menjadi berangkat (mis. notifikasi perjalanan).
     */
    public function pastikanBolehBerangkat(JadwalDistribusi $jadwal): void
    {
        $produksi = JadwalProduksi::withoutGlobalScope(SppgScope::class)->withTrashed()->find($jadwal->jadwal_produksi_id);

        if ($produksi === null) {
            throw ValidationException::withMessages(['status' => ['Jadwal produksi tidak ditemukan.']]);
        }

        if ($produksi->isLayakDistribusi()) {
            return;
        }

        $terbaru = $produksi->pemeriksaanMutuTerbaru();

        throw ValidationException::withMessages([
            'status' => [$terbaru === null
                ? 'Distribusi belum dapat berangkat: belum ada pemeriksaan mutu untuk jadwal produksi ini.'
                : ($terbaru->hasil === HasilMutu::TidakLayak
                    ? 'Distribusi ditahan: hasil pemeriksaan mutu terakhir Tidak Layak. Lakukan pemeriksaan ulang hingga Layak.'
                    : 'Distribusi belum dapat berangkat: pemeriksaan mutu belum Layak.')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $final
     */
    private function validasi(JadwalProduksi $produksi, array $final, ?int $kecualiId, ?int $titikLama, ?int $pengemudiLama): void
    {
        $sppgId = (int) $produksi->sppg_id;

        if ($final['tanggal_distribusi'] < $produksi->tanggal_produksi->toDateString()) {
            throw ValidationException::withMessages(['tanggal_distribusi' => ['Tanggal distribusi tidak boleh sebelum tanggal produksi.']]);
        }

        $titik = TitikLayanan::withoutGlobalScope(SppgScope::class)->where('sppg_id', $sppgId)->find($final['titik_layanan_id']);

        if ($titik === null) {
            throw ValidationException::withMessages(['titik_layanan_id' => ['Titik layanan tidak ditemukan.']]);
        }

        if (! $titik->is_active && $titik->id !== $titikLama) {
            throw ValidationException::withMessages(['titik_layanan_id' => ["Titik layanan {$titik->nama} tidak aktif."]]);
        }

        if ($final['armada_id'] !== null) {
            $armada = Armada::withoutGlobalScope(SppgScope::class)->where('sppg_id', $sppgId)->lockForUpdate()->find($final['armada_id']);

            if ($armada === null) {
                throw ValidationException::withMessages(['armada_id' => ['Armada tidak ditemukan.']]);
            }

            if ($armada->status === StatusArmada::Servis) {
                throw ValidationException::withMessages(['armada_id' => ["Armada {$armada->plat_nomor} sedang dalam servis."]]);
            }
        }

        if ($final['pengemudi_id'] !== null) {
            $pengemudi = Pengemudi::withoutGlobalScope(SppgScope::class)->where('sppg_id', $sppgId)->lockForUpdate()->find($final['pengemudi_id']);

            if ($pengemudi === null) {
                throw ValidationException::withMessages(['pengemudi_id' => ['Pengemudi tidak ditemukan.']]);
            }

            if (! $pengemudi->is_active) {
                throw ValidationException::withMessages(['pengemudi_id' => ["Pengemudi {$pengemudi->nama} tidak aktif."]]);
            }

            if ($pengemudi->id !== $pengemudiLama && ! $pengemudi->simBerlaku()) {
                throw ValidationException::withMessages(['pengemudi_id' => ["SIM pengemudi {$pengemudi->nama} sudah kedaluwarsa."]]);
            }
        }

        if ($final['petugas_id'] !== null) {
            $this->pastikanMilikSppg(User::class, (int) $final['petugas_id'], $sppgId, 'petugas_id', 'Petugas tidak ditemukan.');
        }

        if (($final['armada_id'] !== null || $final['pengemudi_id'] !== null) && $final['jam_berangkat'] === null) {
            throw ValidationException::withMessages(['jam_berangkat' => ['Jam berangkat wajib diisi bila armada atau pengemudi ditetapkan.']]);
        }

        if ($final['jam_berangkat'] !== null && $final['jam_rencana_tiba'] !== null
            && self::menit($final['jam_rencana_tiba']) <= self::menit($final['jam_berangkat'])) {
            throw ValidationException::withMessages(['jam_rencana_tiba' => ['Jam rencana tiba harus setelah jam berangkat.']]);
        }

        $terjadwal = (int) JadwalDistribusi::withoutGlobalScope(SppgScope::class)
            ->where('jadwal_produksi_id', $produksi->id)
            ->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)
            ->when($kecualiId !== null, fn ($q) => $q->where('id', '!=', $kecualiId))
            ->sum('jumlah_porsi');

        if ($terjadwal + $final['jumlah_porsi'] > (int) $produksi->target_porsi) {
            throw ValidationException::withMessages([
                'jumlah_porsi' => [sprintf(
                    'Total porsi distribusi menjadi %d (%d sudah terjadwal + %d baru), melebihi porsi produksi %d. Sisa porsi %d.',
                    $terjadwal + $final['jumlah_porsi'], $terjadwal, $final['jumlah_porsi'], $produksi->target_porsi,
                    max(0, (int) $produksi->target_porsi - $terjadwal),
                )],
            ]);
        }

        foreach (['armada_id' => 'Armada', 'pengemudi_id' => 'Pengemudi'] as $kolom => $label) {
            if ($final[$kolom] !== null && $final['jam_berangkat'] !== null) {
                $this->pastikanTidakBentrok($kolom, $label, $final, $sppgId, $kecualiId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $final
     */
    private function pastikanTidakBentrok(string $kolom, string $label, array $final, int $sppgId, ?int $kecualiId): void
    {
        $mulai = self::menit($final['jam_berangkat']);
        $selesai = $final['jam_rencana_tiba'] !== null
            ? self::menit($final['jam_rencana_tiba'])
            : $mulai + (int) config('produksi.durasi_distribusi_default_menit', 60);

        $lain = JadwalDistribusi::withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)
            ->where($kolom, $final[$kolom])
            ->whereDate('tanggal_distribusi', $final['tanggal_distribusi'])
            ->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)
            ->whereNotNull('jam_berangkat')
            ->when($kecualiId !== null, fn ($q) => $q->where('id', '!=', $kecualiId))
            ->get();

        foreach ($lain as $j) {
            $m = self::menit($j->jam_berangkat);
            $s = $j->jam_rencana_tiba !== null
                ? self::menit($j->jam_rencana_tiba)
                : $m + (int) config('produksi.durasi_distribusi_default_menit', 60);

            if ($mulai < $s && $m < $selesai) {
                throw ValidationException::withMessages([
                    $kolom => [sprintf(
                        '%s bentrok dengan jadwal distribusi #%d (%s-%s) pada tanggal yang sama.',
                        $label, $j->id, substr((string) $j->jam_berangkat, 0, 5), $j->jam_rencana_tiba !== null ? substr((string) $j->jam_rencana_tiba, 0, 5) : sprintf('%02d:%02d', intdiv($s, 60) % 24, $s % 60),
                    )],
                ]);
            }
        }
    }

    private function pastikanProduksiAktif(JadwalProduksi $produksi): void
    {
        if ($produksi->status === StatusJadwalProduksi::Dibatalkan) {
            throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi sudah dibatalkan.']]);
        }
    }

    private function validasiTransisi(StatusJadwalDistribusi $dari, StatusJadwalDistribusi $ke): void
    {
        if (! in_array($ke->value, self::TRANSISI[$dari->value], true)) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Status tidak dapat berubah dari "%s" ke "%s".', $dari->label(), $ke->label())],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $final
     * @return list<string>
     */
    private function kolomBerubah(JadwalDistribusi $jadwal, array $final): array
    {
        $berubah = [];

        foreach (self::KOLOM_PERENCANAAN as $kolom) {
            $lama = match ($kolom) {
                'tanggal_distribusi' => $jadwal->tanggal_distribusi->toDateString(),
                'jam_berangkat', 'jam_rencana_tiba' => $this->jam($jadwal->{$kolom}),
                default => $jadwal->{$kolom},
            };

            if ($lama !== $final[$kolom] && (string) $lama !== (string) $final[$kolom]) {
                $berubah[] = $kolom;
            }
        }

        return $berubah;
    }

    private function kunciProduksi(int $id, int $sppgId): JadwalProduksi
    {
        $produksi = JadwalProduksi::withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)->whereKey($id)->lockForUpdate()->first();

        if ($produksi === null) {
            throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi tidak ditemukan.']]);
        }

        return $produksi;
    }

    /** @param  class-string<Model>  $model */
    private function pastikanMilikSppg(string $model, int $id, int $sppgId, string $field, string $pesan): void
    {
        $query = $model::query()->withoutGlobalScope(SppgScope::class)->where('sppg_id', $sppgId)->whereKey($id);

        if (! $query->exists()) {
            throw ValidationException::withMessages([$field => [$pesan]]);
        }
    }

    private function jam(?string $nilai): ?string
    {
        return $nilai === null || $nilai === '' ? null : substr($nilai, 0, 5);
    }

    private static function menit(string $jam): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($jam, 0, 5)));

        return $h * 60 + $m;
    }
}

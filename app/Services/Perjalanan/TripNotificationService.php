<?php

namespace App\Services\Perjalanan;

use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusKirim;
use App\Enums\StatusPerjalanan;
use App\Events\PerjalananDimulai;
use App\Events\PerjalananTerlambat;
use App\Events\PerjalananTiba;
use App\Models\JadwalDistribusi;
use App\Models\NotifikasiPerjalanan;
use App\Models\Scopes\SppgScope;
use App\Models\TitikLayanan;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logika bisnis Notifikasi Perjalanan Mobil (FR-12).
 *
 * Estimasi tiba (zona waktu config('operasional.timezone') = Asia/Jakarta):
 *   1. manual   : estimasi_menit dari input pengguna;
 *   2. jarak    : ceil(jarak_km / kecepatan_rata_rata_kmj * 60) + buffer_menit (jarak_km > 0);
 *   3. jadwal   : jam_rencana_tiba - jam_berangkat pada jadwal distribusi (bila positif);
 *   4. default  : durasi_default_menit.
 *   Sumber 2-4 dibatasi ke [durasi_minimum_menit, durasi_maksimum_menit].
 *   estimasi_tiba = waktu_berangkat + durasi_menit.
 *
 * Service ini hanya memancarkan event (PerjalananDimulai/Tiba/Terlambat); tidak mengirim pesan.
 */
class TripNotificationService
{
    public const SUMBER_MANUAL = 'manual';

    public const SUMBER_JARAK = 'jarak';

    public const SUMBER_JADWAL = 'jadwal';

    public const SUMBER_DEFAULT = 'default';

    public function zona(): string
    {
        return (string) config('operasional.timezone', 'Asia/Jakarta');
    }

    public function sekarang(): Carbon
    {
        return Carbon::now($this->zona());
    }

    /**
     * @return array{menit: int, tiba: Carbon, sumber: string}
     */
    public function hitungEstimasi(JadwalDistribusi $jadwal, TitikLayanan $titik, CarbonInterface $berangkat, ?int $manualMenit = null): array
    {
        $berangkat = Carbon::instance($berangkat)->setTimezone($this->zona());
        $cfg = config('operasional.perjalanan');

        if ($manualMenit !== null) {
            $menit = $manualMenit;
            $sumber = self::SUMBER_MANUAL;
        } elseif ($titik->jarak_km !== null && (float) $titik->jarak_km > 0) {
            $kecepatan = max(1.0, (float) $cfg['kecepatan_rata_rata_kmj']);
            $menit = (int) ceil(((float) $titik->jarak_km / $kecepatan) * 60) + (int) $cfg['buffer_menit'];
            $sumber = self::SUMBER_JARAK;
        } elseif (($selisih = $this->selisihJadwal($jadwal)) !== null) {
            $menit = $selisih;
            $sumber = self::SUMBER_JADWAL;
        } else {
            $menit = (int) $cfg['durasi_default_menit'];
            $sumber = self::SUMBER_DEFAULT;
        }

        if ($sumber !== self::SUMBER_MANUAL) {
            $menit = max((int) $cfg['durasi_minimum_menit'], min((int) $cfg['durasi_maksimum_menit'], $menit));
        }

        return ['menit' => $menit, 'tiba' => $berangkat->copy()->addMinutes($menit), 'sumber' => $sumber];
    }

    /**
     * Pratinjau tanpa menyimpan (layar "Berangkat & Kirim Notifikasi").
     *
     * @return array{waktu_berangkat: Carbon, estimasi_tiba: Carbon, estimasi_menit: int, estimasi_sumber: string, penerima: string, pesan: string}
     */
    public function pratinjau(JadwalDistribusi $jadwal, ?CarbonInterface $berangkat = null, ?int $manualMenit = null): array
    {
        $this->pastikanBisaBerangkat($jadwal);

        $titik = $jadwal->titikLayanan;
        $berangkat = $berangkat !== null ? Carbon::instance($berangkat)->setTimezone($this->zona()) : $this->sekarang();
        $estimasi = $this->hitungEstimasi($jadwal, $titik, $berangkat, $manualMenit);

        return [
            'waktu_berangkat' => $berangkat,
            'estimasi_tiba' => $estimasi['tiba'],
            'estimasi_menit' => $estimasi['menit'],
            'estimasi_sumber' => $estimasi['sumber'],
            'penerima' => $titik->picTerhubung() ? 'pic' : 'kepala_sppg',
            'pesan' => $this->susunPesan($jadwal, $titik, $berangkat, $estimasi['tiba'], $estimasi['menit']),
        ];
    }

    /**
     * Mulai perjalanan: catat notifikasi berstatus "berangkat" lalu pancarkan PerjalananDimulai.
     */
    public function mulai(User $pemicu, JadwalDistribusi $jadwal, ?CarbonInterface $berangkat = null, ?int $manualMenit = null): NotifikasiPerjalanan
    {
        return DB::transaction(function () use ($pemicu, $jadwal, $berangkat, $manualMenit) {
            // Kunci baris jadwal agar dua pemicu bersamaan tidak membuat perjalanan ganda.
            $jadwal = JadwalDistribusi::query()->whereKey($jadwal->getKey())->lockForUpdate()->firstOrFail();
            $this->pastikanBisaBerangkat($jadwal);

            $titik = $jadwal->titikLayanan;
            $berangkat = $berangkat !== null ? Carbon::instance($berangkat)->setTimezone($this->zona()) : $this->sekarang();
            $estimasi = $this->hitungEstimasi($jadwal, $titik, $berangkat, $manualMenit);
            $pic = $titik->picTerhubung();

            $notifikasi = new NotifikasiPerjalanan;
            $notifikasi->forceFill(['sppg_id' => $jadwal->sppg_id]);
            $notifikasi->fill([
                'jadwal_distribusi_id' => $jadwal->id,
                'titik_layanan_id' => $titik->id,
                'dipicu_oleh' => $pemicu->getKey(),
                'penerima_chat_id' => $pic ? $titik->pic_telegram_chat_id : null,
                'dialihkan_ke_kepala' => ! $pic,
                // FR-12: PIC belum terhubung -> dialihkan ke Kepala SPPG dan dicatat "tidak terkirim".
                'status_kirim' => $pic ? StatusKirim::Menunggu : StatusKirim::TidakTerkirim,
                'status_perjalanan' => StatusPerjalanan::Berangkat,
                'waktu_berangkat' => $berangkat,
                'estimasi_tiba' => $estimasi['tiba'],
                'estimasi_menit' => $estimasi['menit'],
                'estimasi_sumber' => $estimasi['sumber'],
                'pesan' => $this->susunPesan($jadwal, $titik, $berangkat, $estimasi['tiba'], $estimasi['menit']),
            ]);
            $notifikasi->save();

            event(PerjalananDimulai::dari($notifikasi));

            return $notifikasi;
        });
    }

    /**
     * Ubah status sesuai transisi valid. Tiba memancarkan PerjalananTiba.
     */
    public function ubahStatus(NotifikasiPerjalanan $notifikasi, StatusPerjalanan $tujuan, ?string $alasan = null): NotifikasiPerjalanan
    {
        return DB::transaction(function () use ($notifikasi, $tujuan, $alasan) {
            $notifikasi = NotifikasiPerjalanan::query()->whereKey($notifikasi->getKey())->lockForUpdate()->firstOrFail();
            $asal = $notifikasi->status_perjalanan;

            if (! $asal->bolehKe($tujuan)) {
                throw ValidationException::withMessages([
                    'status' => ["Status perjalanan tidak dapat diubah dari {$asal->label()} ke {$tujuan->label()}."],
                ]);
            }

            $alasan = $alasan === null ? null : trim($alasan);

            if ($tujuan === StatusPerjalanan::Dibatalkan && ($alasan === null || $alasan === '')) {
                throw ValidationException::withMessages(['alasan' => ['Alasan pembatalan wajib diisi.']]);
            }

            $notifikasi->status_perjalanan = $tujuan;

            if ($tujuan === StatusPerjalanan::Tiba) {
                $notifikasi->waktu_tiba = $this->sekarang();
            } elseif ($tujuan === StatusPerjalanan::Dibatalkan) {
                $notifikasi->waktu_dibatalkan = $this->sekarang();
                $notifikasi->alasan_batal = $alasan;
            }

            $notifikasi->save();

            if ($tujuan === StatusPerjalanan::Tiba) {
                event(PerjalananTiba::dari($notifikasi));
            }

            return $notifikasi;
        });
    }

    /**
     * Tandai perjalanan aktif yang estimasinya terlewati. Sekali per perjalanan (penanda
     * terlambat_dideteksi_pada di-set atomik sehingga pemindai paralel tidak menggandakan event).
     *
     * @return int jumlah perjalanan yang baru ditandai terlambat
     */
    public function deteksiKeterlambatan(?CarbonInterface $sekarang = null): int
    {
        $sekarang = $sekarang !== null ? Carbon::instance($sekarang)->setTimezone($this->zona()) : $this->sekarang();
        $jumlah = 0;

        $ids = NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)
            ->aktif()
            ->whereNull('terlambat_dideteksi_pada')
            ->where('estimasi_tiba', '<', $sekarang)
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $diperbarui = NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)
                ->whereKey($id)
                ->aktif()
                ->whereNull('terlambat_dideteksi_pada')
                ->update(['terlambat_dideteksi_pada' => $sekarang]);

            if ($diperbarui !== 1) {
                continue;
            }

            $notifikasi = NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)->with('titikLayanan')->find($id);

            if ($notifikasi !== null) {
                event(PerjalananTerlambat::dari($notifikasi));
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /** @throws ValidationException */
    public function pastikanBisaBerangkat(JadwalDistribusi $jadwal): void
    {
        $galat = fn (string $pesan) => ValidationException::withMessages(['jadwal_distribusi_id' => [$pesan]]);

        if (! in_array($jadwal->status, [StatusJadwalDistribusi::Siap, StatusJadwalDistribusi::Berangkat], true)) {
            throw $galat('Jadwal distribusi harus berstatus siap berangkat.');
        }

        if ($jadwal->titikLayanan === null) {
            throw $galat('Titik layanan tujuan jadwal distribusi tidak ditemukan.');
        }

        if ($jadwal->jadwalProduksi === null || ! $jadwal->jadwalProduksi->isLayakDistribusi()) {
            throw $galat('Hasil pemeriksaan mutu batch produksi belum Layak, keberangkatan ditolak.');
        }

        $adaAktif = NotifikasiPerjalanan::query()->where('jadwal_distribusi_id', $jadwal->id)->aktif()->exists();

        if ($adaAktif) {
            throw $galat('Sudah ada perjalanan aktif untuk jadwal distribusi ini.');
        }
    }

    private function selisihJadwal(JadwalDistribusi $jadwal): ?int
    {
        if (blank($jadwal->jam_berangkat) || blank($jadwal->jam_rencana_tiba)) {
            return null;
        }

        $mulai = Carbon::parse('1970-01-01 '.$jadwal->jam_berangkat);
        $tiba = Carbon::parse('1970-01-01 '.$jadwal->jam_rencana_tiba);
        $selisih = (int) $mulai->diffInMinutes($tiba, false);

        return $selisih > 0 ? $selisih : null;
    }

    private function susunPesan(JadwalDistribusi $jadwal, TitikLayanan $titik, CarbonInterface $berangkat, CarbonInterface $tiba, int $menit): string
    {
        $kendaraan = $jadwal->armada?->plat_nomor !== null ? 'Mobil '.$jadwal->armada->plat_nomor : 'Mobil SPPG';

        return sprintf(
            '%s berangkat menuju %s pukul %s WIB, estimasi tiba %s WIB (%d menit).',
            $kendaraan,
            $titik->nama,
            $berangkat->format('H:i'),
            $tiba->format('H:i'),
            $menit,
        );
    }
}

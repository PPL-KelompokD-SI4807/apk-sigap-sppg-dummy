<?php

namespace App\Services\Mutu;

use App\Enums\HasilMutu;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Events\MutuTidakLayak;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\PemeriksaanMutu;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Pemeriksaan mutu dan keamanan pangan (FR-07). Pemeriksaan TERBARU per jadwal produksi menjadi
 * gerbang distribusi (JadwalProduksi::isLayakDistribusi, ditegakkan DistributionScheduleService).
 */
class PemeriksaanMutuService
{
    private const KOLOM = [
        'waktu_pemeriksaan', 'suhu_makanan_c', 'kebersihan_dapur', 'kebersihan_petugas',
        'rasa_baik', 'aroma_baik', 'tampilan_baik', 'organoleptik_baik',
        'catatan_organoleptik', 'sampel_disimpan', 'hasil', 'tindakan_korektif',
    ];

    /** Butir checklist organoleptik; organoleptik_baik = turunan (semua true). */
    private const BUTIR_ORGANOLEPTIK = ['rasa_baik', 'aroma_baik', 'tampilan_baik'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function catat(User $pengguna, array $data): PemeriksaanMutu
    {
        $pemeriksaan = DB::transaction(function () use ($pengguna, $data): PemeriksaanMutu {
            $jadwal = JadwalProduksi::withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $pengguna->sppg_id)->whereKey($data['jadwal_produksi_id'])->lockForUpdate()->first();

            if ($jadwal === null) {
                throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi tidak ditemukan.']]);
            }

            if ($jadwal->status === StatusJadwalProduksi::Dibatalkan) {
                throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi sudah dibatalkan.']]);
            }

            if ($jadwal->tanggal_produksi->toDateString() > now()->toDateString()) {
                throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi belum dimulai (tanggal produksi di masa depan).']]);
            }

            $atribut = $this->normalisasiOrganoleptik(array_intersect_key($data, array_flip(self::KOLOM)), null);
            $atribut['waktu_pemeriksaan'] ??= now();
            $atribut['sampel_disimpan'] = (bool) ($atribut['sampel_disimpan'] ?? false);
            $this->pastikanKonsisten($atribut);

            $pemeriksaan = new PemeriksaanMutu;
            $pemeriksaan->fill($atribut);
            $pemeriksaan->forceFill([
                'sppg_id' => $jadwal->sppg_id,
                'jadwal_produksi_id' => $jadwal->id,
                'pemeriksa_id' => $pengguna->getKey(),
            ])->save();
            $pemeriksaan->setRelation('jadwalProduksi', $jadwal);

            return $pemeriksaan;
        });

        $this->umumkanBilaTidakLayak($pemeriksaan);

        return $pemeriksaan;
    }

    /**
     * Ubah/koreksi. Bila pemeriksaan sudah memengaruhi distribusi, `catatan_koreksi` wajib.
     *
     * @param  array<string, mixed>  $data
     */
    public function perbarui(PemeriksaanMutu $pemeriksaan, User $pengguna, array $data): PemeriksaanMutu
    {
        $hasilLama = $pemeriksaan->hasil;

        $diubah = DB::transaction(function () use ($pemeriksaan, $pengguna, $data): PemeriksaanMutu {
            $terkunci = PemeriksaanMutu::withoutGlobalScope(SppgScope::class)->whereKey($pemeriksaan->getKey())->lockForUpdate()->firstOrFail();
            $atribut = $this->normalisasiOrganoleptik(array_intersect_key($data, array_flip(self::KOLOM)), $terkunci);

            $final = array_merge($terkunci->only(self::KOLOM), $atribut);
            $final['hasil'] = $final['hasil'] instanceof HasilMutu ? $final['hasil']->value : $final['hasil'];
            $this->pastikanKonsisten($final);

            // Perubahan data wajib berupa koreksi; menambah lampiran saja (atribut kosong) tidak.
            $perluKoreksi = $atribut !== [] && $this->memengaruhiDistribusi($terkunci);
            $catatan = isset($data['catatan_koreksi']) ? trim((string) $data['catatan_koreksi']) : '';

            if ($perluKoreksi && $catatan === '') {
                throw ValidationException::withMessages([
                    'catatan_koreksi' => ['Pemeriksaan ini sudah memengaruhi distribusi. Perubahan harus berupa koreksi dengan catatan koreksi.'],
                ]);
            }

            $terkunci->fill($atribut);

            if ($catatan !== '') {
                $terkunci->fill(['catatan_koreksi' => $catatan, 'dikoreksi_pada' => now(), 'dikoreksi_oleh' => $pengguna->getKey()]);
            }

            $terkunci->save();

            return $terkunci;
        });

        if ($hasilLama !== HasilMutu::TidakLayak) {
            $this->umumkanBilaTidakLayak($diubah);
        }

        return $diubah;
    }

    public function hapus(PemeriksaanMutu $pemeriksaan): void
    {
        DB::transaction(function () use ($pemeriksaan): void {
            $terkunci = PemeriksaanMutu::withoutGlobalScope(SppgScope::class)->whereKey($pemeriksaan->getKey())->lockForUpdate()->firstOrFail();

            if ($this->memengaruhiDistribusi($terkunci)) {
                throw ValidationException::withMessages([
                    'pemeriksaan' => ['Pemeriksaan ini sudah memengaruhi distribusi dan tidak dapat dihapus. Lakukan koreksi dengan catatan koreksi.'],
                ]);
            }

            $terkunci->delete();
        });
    }

    /**
     * Sudah memengaruhi distribusi bila distribusi terkait sudah berangkat/diterima, atau pemeriksaan ini
     * adalah yang terbaru (gerbang berlaku) sementara ada distribusi menunggu/siap.
     */
    public function memengaruhiDistribusi(PemeriksaanMutu $pemeriksaan): bool
    {
        $aktif = JadwalDistribusi::withoutGlobalScope(SppgScope::class)
            ->where('jadwal_produksi_id', $pemeriksaan->jadwal_produksi_id)
            ->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value);

        if (! (clone $aktif)->exists()) {
            return false;
        }

        if ((clone $aktif)->whereIn('status', [StatusJadwalDistribusi::Berangkat->value, StatusJadwalDistribusi::Diterima->value])->exists()) {
            return true;
        }

        $terbaru = PemeriksaanMutu::withoutGlobalScope(SppgScope::class)
            ->where('jadwal_produksi_id', $pemeriksaan->jadwal_produksi_id)
            ->orderByDesc('waktu_pemeriksaan')->orderByDesc('id')->first();

        return $terbaru?->id === $pemeriksaan->id;
    }

    /**
     * Konsistensi checklist: Layak hanya bila semua butir baik dan suhu memenuhi minimum.
     *
     * @param  array<string, mixed>  $a
     */
    private function pastikanKonsisten(array $a): void
    {
        $hasil = $a['hasil'] ?? null;
        $hasil = $hasil instanceof HasilMutu ? $hasil->value : $hasil;
        $butir = [
            'kebersihan_dapur' => 'Kebersihan dapur',
            'kebersihan_petugas' => 'Kebersihan petugas',
            'rasa_baik' => 'Rasa',
            'aroma_baik' => 'Aroma',
            'tampilan_baik' => 'Tampilan',
        ];

        if ($hasil === HasilMutu::Layak->value) {
            $gagal = array_keys(array_filter($butir, fn ($label, $k) => ! filter_var($a[$k] ?? false, FILTER_VALIDATE_BOOLEAN), ARRAY_FILTER_USE_BOTH));

            if ($gagal !== []) {
                throw ValidationException::withMessages([
                    'hasil' => ['Tidak dapat dinyatakan Layak: '.implode(', ', array_map(fn ($k) => $butir[$k], $gagal)).' tidak memenuhi syarat.'],
                ]);
            }

            $min = (float) config('produksi.mutu.suhu_minimum_c', 60);

            if (isset($a['suhu_makanan_c']) && (float) $a['suhu_makanan_c'] < $min) {
                throw ValidationException::withMessages([
                    'hasil' => [sprintf('Tidak dapat dinyatakan Layak: suhu makanan %s C di bawah minimum %s C.', rtrim(rtrim(number_format((float) $a['suhu_makanan_c'], 1, '.', ''), '0'), '.'), rtrim(rtrim(number_format($min, 1, '.', ''), '0'), '.'))],
                ]);
            }
        }

        if ($hasil === HasilMutu::TidakLayak->value && trim((string) ($a['tindakan_korektif'] ?? '')) === '') {
            throw ValidationException::withMessages(['tindakan_korektif' => ['Tindakan korektif wajib diisi untuk hasil Tidak Layak.']]);
        }

        $organoleptikBaik = true;

        foreach (self::BUTIR_ORGANOLEPTIK as $k) {
            $organoleptikBaik = $organoleptikBaik && filter_var($a[$k] ?? true, FILTER_VALIDATE_BOOLEAN);
        }

        if (! $organoleptikBaik && trim((string) ($a['catatan_organoleptik'] ?? '')) === '') {
            throw ValidationException::withMessages(['catatan_organoleptik' => ['Catatan organoleptik wajib diisi bila rasa/aroma/tampilan tidak baik.']]);
        }
    }

    /**
     * Samakan tiga butir organoleptik dengan nilai turunan organoleptik_baik.
     *  - Butir yang dikirim dipakai apa adanya; butir yang tidak dikirim diisi dari organoleptik_baik
     *    kiriman klien lama, atau dari nilai tersimpan ($ada), atau true (data baru).
     *  - organoleptik_baik selalu dihitung ulang (true bila ketiganya true); kiriman klien diabaikan
     *    bila bertentangan dengan ketiga butir.
     *  - Tanpa butir maupun organoleptik_baik pada $atribut dan ada record lama -> tidak diubah.
     *
     * @param  array<string, mixed>  $atribut
     * @return array<string, mixed>
     */
    private function normalisasiOrganoleptik(array $atribut, ?PemeriksaanMutu $ada): array
    {
        $kirimButir = array_intersect_key($atribut, array_flip(self::BUTIR_ORGANOLEPTIK));

        if ($kirimButir === [] && ! array_key_exists('organoleptik_baik', $atribut)) {
            return $atribut;
        }

        $warisan = array_key_exists('organoleptik_baik', $atribut)
            ? filter_var($atribut['organoleptik_baik'], FILTER_VALIDATE_BOOLEAN)
            : null;

        $semua = true;

        foreach (self::BUTIR_ORGANOLEPTIK as $k) {
            $nilai = array_key_exists($k, $atribut)
                ? filter_var($atribut[$k], FILTER_VALIDATE_BOOLEAN)
                : ($warisan ?? ($ada?->{$k} ?? true));

            $atribut[$k] = $nilai;
            $semua = $semua && $nilai;
        }

        $atribut['organoleptik_baik'] = $semua;

        return $atribut;
    }

    private function umumkanBilaTidakLayak(PemeriksaanMutu $pemeriksaan): void
    {
        if ($pemeriksaan->hasil !== HasilMutu::TidakLayak) {
            return;
        }

        $jadwal = JadwalProduksi::withoutGlobalScope(SppgScope::class)->with('menu')->find($pemeriksaan->jadwal_produksi_id);
        $ditahan = JadwalDistribusi::withoutGlobalScope(SppgScope::class)
            ->where('jadwal_produksi_id', $pemeriksaan->jadwal_produksi_id)
            ->whereIn('status', [StatusJadwalDistribusi::Menunggu->value, StatusJadwalDistribusi::Siap->value])->count();

        Event::dispatch(new MutuTidakLayak(
            (int) $pemeriksaan->sppg_id,
            (int) $pemeriksaan->id,
            (int) $pemeriksaan->jadwal_produksi_id,
            $jadwal?->tanggal_produksi?->toDateString() ?? '',
            (string) ($jadwal?->menu?->nama_menu ?? ''),
            $pemeriksaan->pemeriksa_id === null ? null : (int) $pemeriksaan->pemeriksa_id,
            $pemeriksaan->tindakan_korektif,
            $ditahan,
        ));
    }
}

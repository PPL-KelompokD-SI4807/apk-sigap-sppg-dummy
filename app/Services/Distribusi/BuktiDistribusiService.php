<?php

namespace App\Services\Distribusi;

use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusPerjalanan;
use App\Events\BuktiDistribusiBelumMasuk;
use App\Events\BuktiDistribusiDicatat;
use App\Events\DistribusiTerlambat;
use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\NotifikasiPerjalanan;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Perjalanan\TripNotificationService;
use App\Services\Storage\GambarSiap;
use App\Services\Storage\SecureUploadService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Logika bisnis Bukti Distribusi (PBI 13, FR-08, UC14).
 *
 * - Satu bukti per jadwal distribusi (indeks unik). Bila bukti lama terhapus lunak, baris itu
 *   dipulihkan (restore) dan ditimpa data baru alih-alih membuat baris baru.
 * - Jadwal harus berstatus siap/berangkat (atau diterima tanpa bukti); jadwal dibatalkan, belum
 *   siap, atau ditahan mutu (pemeriksaan terbaru bukan Layak) ditolak 422.
 * - Relawan: hanya jadwal hari ini (WIB) yang ditugaskan kepadanya / belum punya petugas;
 *   waktu_tiba = waktu server (risiko 3 proposal). Kepala SPPG boleh mengisi waktu_tiba (koreksi).
 * - Keterlambatan: acuan = estimasi_tiba perjalanan terakhir (tidak dibatalkan), atau
 *   tanggal_distribusi + jam_rencana_tiba; terlambat bila selisih > toleransi (config).
 * - Setelah tersimpan: jadwal -> diterima, perjalanan aktif -> tiba, event BuktiDistribusiDicatat
 *   (+ DistribusiTerlambat bila terlambat), semua setelah commit.
 */
class BuktiDistribusiService
{
    public const MODUL = 'bukti-distribusi';

    public function __construct(
        private readonly SecureUploadService $unggahan,
        private readonly TripNotificationService $perjalanan,
    ) {}

    private function zona(): string
    {
        return (string) config('operasional.timezone', 'Asia/Jakarta');
    }

    /**
     * @param  array<string, mixed>  $data  hasil validasi StoreBuktiDistribusiRequest
     * @param  array<string, GambarSiap>  $gambar  'foto' (wajib) dan 'berita_acara' (opsional)
     */
    public function catat(User $pencatat, array $data, array $gambar): BuktiDistribusi
    {
        return DB::transaction(function () use ($pencatat, $data, $gambar) {
            $jadwal = JadwalDistribusi::query()->whereKey((int) $data['jadwal_distribusi_id'])->lockForUpdate()->first();

            if ($jadwal === null) {
                throw ValidationException::withMessages(['jadwal_distribusi_id' => ['Jadwal distribusi tidak ditemukan.']]);
            }

            $this->pastikanBolehDiberiBukti($pencatat, $jadwal);

            $lama = BuktiDistribusi::withTrashed()->withoutGlobalScope(SppgScope::class)
                ->where('jadwal_distribusi_id', $jadwal->id)->lockForUpdate()->first();

            if ($lama !== null && ! $lama->trashed()) {
                throw ValidationException::withMessages([
                    'jadwal_distribusi_id' => ['Jadwal distribusi ini sudah memiliki bukti. Ubah bukti yang ada.'],
                ]);
            }

            $sekarang = Carbon::now($this->zona());
            $waktuTiba = $this->waktuTiba($pencatat, $data, $sekarang);
            $perjalanan = $this->perjalananTerakhir($jadwal);
            [$acuan, $menit, $terlambat] = $this->hitungKeterlambatan($jadwal, $perjalanan, $waktuTiba);

            $bukti = $lama ?? new BuktiDistribusi;

            if ($lama !== null) {
                $lama->restore();
                $lama->foto_path = null;
                $lama->berita_acara_path = null;
                $lama->created_at = $sekarang;
            }

            $bukti->forceFill(['sppg_id' => $jadwal->sppg_id]);
            $bukti->fill([
                'jadwal_distribusi_id' => $jadwal->id,
                'dicatat_oleh' => $pencatat->getKey(),
                'waktu_berangkat' => $data['waktu_berangkat'] ?? $perjalanan?->waktu_berangkat,
                'waktu_tiba' => $waktuTiba,
                'acuan_tiba' => $acuan,
                'waktu_ambil_foto' => $data['waktu_ambil_foto'] ?? null,
                'porsi_diterima' => (int) $data['porsi_diterima'],
                'nama_pic' => $data['nama_pic'],
                'no_berita_acara' => $data['no_berita_acara'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'catatan_selisih' => $data['catatan_selisih'] ?? null,
                'catatan' => $data['catatan'] ?? null,
                'terlambat' => $terlambat,
                'menit_keterlambatan' => $menit,
            ]);
            $bukti->save();

            $this->lampirkan($bukti, $gambar, $pencatat);
            $this->tandaiDiterima($jadwal, $perjalanan);

            event(BuktiDistribusiDicatat::dari($bukti));

            if ($bukti->terlambat) {
                event(DistribusiTerlambat::dari($bukti));
            }

            return $bukti;
        });
    }

    /**
     * @param  array<string, mixed>  $data  hasil validasi UpdateBuktiDistribusiRequest (key yang dikirim saja)
     * @param  array<string, GambarSiap>  $gambar
     */
    public function perbarui(User $pengguna, BuktiDistribusi $bukti, array $data, array $gambar): BuktiDistribusi
    {
        return DB::transaction(function () use ($pengguna, $bukti, $data, $gambar) {
            $bukti = BuktiDistribusi::query()->whereKey($bukti->getKey())->lockForUpdate()->firstOrFail();
            $jadwal = JadwalDistribusi::withTrashed()->withoutGlobalScope(SppgScope::class)->findOrFail($bukti->jadwal_distribusi_id);

            foreach (['waktu_berangkat', 'waktu_ambil_foto', 'porsi_diterima', 'nama_pic', 'no_berita_acara', 'latitude', 'longitude', 'catatan_selisih', 'catatan'] as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $bukti->{$kolom} = $data[$kolom];
                }
            }

            if (array_key_exists('waktu_tiba', $data) && $pengguna->role !== Role::Relawan && $data['waktu_tiba'] !== null) {
                $bukti->waktu_tiba = Carbon::parse($data['waktu_tiba'], $this->zona());
                [$acuan, $menit, $terlambat] = $this->hitungKeterlambatan($jadwal, $this->perjalananTerakhir($jadwal), $bukti->waktu_tiba);
                $bukti->acuan_tiba = $acuan;
                $bukti->menit_keterlambatan = $menit;
                $bukti->terlambat = $terlambat;
            }

            $bukti->save();
            $this->lampirkan($bukti, $gambar, $pengguna);

            return $bukti;
        });
    }

    /**
     * Pemindai terjadwal (sigap:cek-bukti-distribusi): jadwal distribusi yang sudah berangkat
     * (status berangkat, atau siap dengan perjalanan aktif berangkat/dalam_perjalanan), belum punya bukti,
     * dan sudah melewati jam rencana tiba + operasional.bukti_distribusi.batas_belum_masuk_menit.
     * Memancarkan BuktiDistribusiBelumMasuk SEKALI per jadwal: penanda bukti_belum_masuk_pada di-set
     * lewat UPDATE atomik (WHERE penanda NULL) sehingga pemindai paralel tidak menggandakan event.
     *
     * @return int jumlah jadwal yang baru ditandai
     */
    public function deteksiBuktiBelumMasuk(?CarbonInterface $sekarang = null): int
    {
        $sekarang = $sekarang !== null ? Carbon::instance($sekarang)->setTimezone($this->zona()) : Carbon::now($this->zona());
        $batas = max(0, (int) config('operasional.bukti_distribusi.batas_belum_masuk_menit', 60));
        $berangkat = [StatusJadwalDistribusi::Siap->value, StatusJadwalDistribusi::Berangkat->value];

        $kandidat = JadwalDistribusi::query()->withoutGlobalScope(SppgScope::class)
            ->with(['titikLayanan' => fn ($q) => $q->withoutGlobalScope(SppgScope::class)->withTrashed()])
            ->whereNull('bukti_belum_masuk_pada')
            ->whereNotNull('jam_rencana_tiba')
            ->whereDate('tanggal_distribusi', '<=', $sekarang->toDateString())
            ->where(fn (Builder $q) => $q
                ->where('status', StatusJadwalDistribusi::Berangkat->value)
                ->orWhere(fn (Builder $s) => $s->where('status', StatusJadwalDistribusi::Siap->value)
                    ->whereHas('notifikasiPerjalanans', fn (Builder $n) => $n->withoutGlobalScope(SppgScope::class)->aktif())))
            ->whereDoesntHave('buktiDistribusi', fn (Builder $b) => $b->withoutGlobalScope(SppgScope::class))
            ->orderBy('id')
            ->get();

        $jumlah = 0;

        foreach ($kandidat as $jadwal) {
            $rencana = Carbon::parse($jadwal->tanggal_distribusi->toDateString().' '.substr((string) $jadwal->jam_rencana_tiba, 0, 8), $this->zona());

            if ($sekarang->lessThanOrEqualTo($rencana->copy()->addMinutes($batas))) {
                continue;
            }

            $ditandai = JadwalDistribusi::query()->withoutGlobalScope(SppgScope::class)
                ->whereKey($jadwal->id)
                ->whereNull('bukti_belum_masuk_pada')
                ->whereIn('status', $berangkat)
                ->update(['bukti_belum_masuk_pada' => $sekarang]);

            if ($ditandai !== 1) {
                continue;
            }

            event(BuktiDistribusiBelumMasuk::dari($jadwal, intdiv($sekarang->getTimestamp() - $rencana->getTimestamp(), 60)));
            $jumlah++;
        }

        return $jumlah;
    }

    /** Hapus lunak; jadwal yang sudah "diterima" dikembalikan ke "berangkat" (bukti dapat diunggah ulang). */
    public function hapus(BuktiDistribusi $bukti): void
    {
        DB::transaction(function () use ($bukti) {
            $bukti = BuktiDistribusi::query()->whereKey($bukti->getKey())->lockForUpdate()->firstOrFail();
            $jadwal = JadwalDistribusi::withoutGlobalScope(SppgScope::class)->whereKey($bukti->jadwal_distribusi_id)->lockForUpdate()->first();

            $bukti->delete();

            if ($jadwal !== null && $jadwal->status === StatusJadwalDistribusi::Diterima) {
                $jadwal->status = StatusJadwalDistribusi::Berangkat;
                $jadwal->save();
            }
        });
    }

    /**
     * Acuan, menit keterlambatan (>0 atau null), dan status terlambat.
     *
     * @return array{0: ?Carbon, 1: ?int, 2: bool}
     */
    public function hitungKeterlambatan(JadwalDistribusi $jadwal, ?NotifikasiPerjalanan $perjalanan, CarbonInterface $waktuTiba): array
    {
        $acuan = null;

        if ($perjalanan?->estimasi_tiba !== null) {
            $acuan = Carbon::instance($perjalanan->estimasi_tiba)->setTimezone($this->zona());
        } elseif ($jadwal->jam_rencana_tiba !== null && $jadwal->tanggal_distribusi !== null) {
            $acuan = Carbon::parse($jadwal->tanggal_distribusi->toDateString().' '.substr((string) $jadwal->jam_rencana_tiba, 0, 8), $this->zona());
        }

        if ($acuan === null) {
            return [null, null, false];
        }

        $detik = $waktuTiba->getTimestamp() - $acuan->getTimestamp();
        $menit = $detik > 0 ? intdiv($detik, 60) : 0;
        $toleransi = max(0, (int) config('operasional.bukti_distribusi.toleransi_terlambat_menit', 15));

        return [$acuan, $menit > 0 ? $menit : null, $menit > $toleransi];
    }

    /** @throws ValidationException|AccessDeniedHttpException */
    public function pastikanBolehDiberiBukti(User $pencatat, JadwalDistribusi $jadwal): void
    {
        $galat = fn (string $pesan) => ValidationException::withMessages(['jadwal_distribusi_id' => [$pesan]]);

        if ($pencatat->role === Role::Relawan) {
            if ($jadwal->petugas_id !== null && (int) $jadwal->petugas_id !== (int) $pencatat->getKey()) {
                throw new AccessDeniedHttpException('Jadwal distribusi ini ditugaskan kepada petugas lain.');
            }

            if ($jadwal->tanggal_distribusi?->toDateString() !== Carbon::now($this->zona())->toDateString()) {
                throw $galat('Relawan hanya dapat mencatat bukti untuk distribusi hari ini.');
            }
        }

        match ($jadwal->status) {
            StatusJadwalDistribusi::Dibatalkan => throw $galat('Jadwal distribusi sudah dibatalkan; bukti tidak dapat dicatat.'),
            StatusJadwalDistribusi::Menunggu => throw $galat('Jadwal distribusi belum siap berangkat; bukti belum dapat dicatat.'),
            default => null,
        };

        $produksi = JadwalProduksi::withoutGlobalScope(SppgScope::class)->withTrashed()->find($jadwal->jadwal_produksi_id);

        if ($produksi === null || ! $produksi->isLayakDistribusi()) {
            throw $galat('Distribusi ditahan: hasil pemeriksaan mutu batch produksi belum/tidak Layak.');
        }
    }

    /** @param array<string, mixed> $data */
    private function waktuTiba(User $pencatat, array $data, Carbon $sekarang): Carbon
    {
        if ($pencatat->role !== Role::Relawan && ! empty($data['waktu_tiba'])) {
            return Carbon::parse($data['waktu_tiba'], $this->zona());
        }

        return $sekarang;
    }

    private function perjalananTerakhir(JadwalDistribusi $jadwal): ?NotifikasiPerjalanan
    {
        return NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)
            ->where('jadwal_distribusi_id', $jadwal->id)
            ->where('status_perjalanan', '!=', StatusPerjalanan::Dibatalkan->value)
            ->orderByDesc('id')
            ->first();
    }

    /** @param array<string, GambarSiap> $gambar */
    private function lampirkan(BuktiDistribusi $bukti, array $gambar, User $pengunggah): void
    {
        $kolom = ['foto' => 'foto_path', 'berita_acara' => 'berita_acara_path'];
        $berubah = false;

        foreach ($kolom as $field => $col) {
            if (isset($gambar[$field])) {
                $lampiran = $this->unggahan->simpan($gambar[$field], $bukti, self::MODUL, $pengunggah, $field);
                $bukti->{$col} = $lampiran->path;
                $berubah = true;
            }
        }

        if ($berubah) {
            $bukti->save();
        }
    }

    private function tandaiDiterima(JadwalDistribusi $jadwal, ?NotifikasiPerjalanan $perjalanan): void
    {
        if ($jadwal->status !== StatusJadwalDistribusi::Diterima) {
            $jadwal->status = StatusJadwalDistribusi::Diterima;
            $jadwal->save();
        }

        // Bukti serah terima = mobil sudah tiba: tutup perjalanan aktif lewat transisi resminya
        // (berangkat -> dalam_perjalanan -> tiba) agar pemindai keterlambatan perjalanan berhenti.
        if ($perjalanan !== null && $perjalanan->status_perjalanan?->aktif()) {
            if ($perjalanan->status_perjalanan->bolehKe(StatusPerjalanan::DalamPerjalanan)
                && ! $perjalanan->status_perjalanan->bolehKe(StatusPerjalanan::Tiba)) {
                $perjalanan = $this->perjalanan->ubahStatus($perjalanan, StatusPerjalanan::DalamPerjalanan);
            }

            if ($perjalanan->status_perjalanan->bolehKe(StatusPerjalanan::Tiba)) {
                $this->perjalanan->ubahStatus($perjalanan, StatusPerjalanan::Tiba);
            }
        }
    }
}

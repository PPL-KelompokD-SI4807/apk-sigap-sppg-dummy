<?php

namespace App\Services\Distribusi;

use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * SATU sumber aturan "boleh mencatat bukti distribusi untuk jadwal ini". Dipakai oleh
 * BuktiDistribusiService::pastikanBolehDiberiBukti (penegakan POST /bukti-distribusi) dan oleh
 * JadwalDistribusiResource (`izin.catat_bukti`), sehingga cermin klien tidak dapat menyimpang dari server.
 *
 * Urutan pemeriksaan = urutan penegakan: hak (Policy create + SPPG sama) -> petugas (relawan) -> hari ini (relawan)
 * -> status -> gerbang mutu. Gerbang mutu butuh data relasi, jadi dievaluasi lewat closure yang disediakan pemanggil
 * (service: query; resource: relasi yang sudah di-eager-load, tanpa query per baris). Tidak ada aturan lain di sini.
 */
final class AturanCatatBukti
{
    public const PETUGAS = 'petugas';

    public const HARI = 'hari';

    public const STATUS = 'status';

    public const MUTU = 'mutu';

    public static function zona(): string
    {
        return (string) config('operasional.timezone', 'Asia/Jakarta');
    }

    /** Hak membuat bukti (Policy) pada SPPG jadwal; sama dengan authorize() + aturan exists StoreBuktiDistribusiRequest. */
    public static function punyaHak(User $pencatat, JadwalDistribusi $jadwal): bool
    {
        return $pencatat->sppg_id !== null
            && (int) $pencatat->sppg_id === (int) $jadwal->sppg_id
            && Gate::forUser($pencatat)->allows('create', BuktiDistribusi::class);
    }

    /**
     * Pelanggaran pertama atau null bila boleh. PETUGAS = penolakan penugasan (403); HARI/STATUS/MUTU = 422.
     * Hak Policy diperiksa terpisah (punyaHak).
     *
     * @param  Closure(): bool  $layakMutu  true bila batch produksi layak distribusi (wajib; gagal tertutup di pemanggil)
     * @return array{0: string, 1: string}|null [jenis, pesan]
     */
    public static function pelanggaran(User $pencatat, JadwalDistribusi $jadwal, Closure $layakMutu): ?array
    {
        if ($pencatat->role === Role::Relawan) {
            if ($jadwal->petugas_id !== null && (int) $jadwal->petugas_id !== (int) $pencatat->getKey()) {
                return [self::PETUGAS, 'Jadwal distribusi ini ditugaskan kepada petugas lain.'];
            }

            if ($jadwal->tanggal_distribusi?->toDateString() !== Carbon::now(self::zona())->toDateString()) {
                return [self::HARI, 'Relawan hanya dapat mencatat bukti untuk distribusi hari ini.'];
            }
        }

        $pesanStatus = match ($jadwal->status) {
            StatusJadwalDistribusi::Dibatalkan => 'Jadwal distribusi sudah dibatalkan; bukti tidak dapat dicatat.',
            StatusJadwalDistribusi::Menunggu => 'Jadwal distribusi belum siap berangkat; bukti belum dapat dicatat.',
            default => null,
        };

        if ($pesanStatus !== null) {
            return [self::STATUS, $pesanStatus];
        }

        if (! $layakMutu()) {
            return [self::MUTU, 'Distribusi ditahan: hasil pemeriksaan mutu batch produksi belum/tidak Layak.'];
        }

        return null;
    }

    /** Hak DAN keadaan; `true` = POST bukti tidak akan 403 (bukan jaminan sukses: bukti ganda dsb. tetap 422). */
    public static function boleh(User $pencatat, JadwalDistribusi $jadwal, Closure $layakMutu): bool
    {
        return self::punyaHak($pencatat, $jadwal) && self::pelanggaran($pencatat, $jadwal, $layakMutu) === null;
    }
}

<?php

namespace App\Services\Dashboard\Widget;

use App\Services\Dashboard\Cakupan;
use Illuminate\Support\Carbon;

/** Status rekap harian operasional (batas kirim 17.00 WIB). */
final class RekapWidgets
{
    use Format;

    public function __construct(private readonly Cakupan $c) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $r = $this->c->pada($this->c->tabel('rekap_harians', false), 'rekap_harians.tanggal')
            ->first(['rekap_harians.status_kirim', 'rekap_harians.dikirim_pada', 'rekap_harians.disusun_pada']);

        $jam = (string) config('operasional.rekap.jam_kirim', '17:00');
        $batas = Carbon::parse($this->c->hari().' '.$jam, (string) config('operasional.timezone', 'Asia/Jakarta'));

        return $this->widget($this->c->periodeHariIni(), [
            'jam_kirim' => $jam,
            'sudah_disusun' => $r !== null,
            'status_kirim' => $r?->status_kirim,
            'disusun_pada' => $r?->disusun_pada,
            'dikirim_pada' => $r?->dikirim_pada,
            'sudah_lewat_jam_kirim' => now()->greaterThanOrEqualTo($batas),
        ]);
    }
}

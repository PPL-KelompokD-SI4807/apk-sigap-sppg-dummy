<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\PenerimaManfaat;
use App\Models\User;
use App\Services\PenerimaManfaat\AgregatPenerimaService;
use App\Services\Telegram\Bot\BotLayar;
use Illuminate\Support\Facades\Gate;

/**
 * Penerima Manfaat, AGREGAT saja (layar `pn`; data anak, UU PDP): jumlah aktif per kelompok sasaran lewat
 * AgregatPenerimaService::hitung dengan penyamaran sel kecil SELALU aktif untuk semua peran di chat (keputusan 4:
 * ambang k dari config rbac.agregat_ambang.k, termasuk Ahli Gizi yang di Mini App melihat penuh). Tanpa nama,
 * sekolah, kelas, alergi, atau rincian per titik layanan. Sel tersamar tampil sebagai "<k".
 */
final class LayarAgregatPenerima extends LayarModulDasar
{
    public function __construct(private readonly AgregatPenerimaService $agregat) {}

    public function id(): string
    {
        return 'pn';
    }

    public function modul(): ?string
    {
        return 'penerima-manfaat';
    }

    public function hak(): string
    {
        return self::HAK_AGREGAT;
    }

    protected function judul(): string
    {
        return 'Penerima Manfaat (agregat)';
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return Gate::forUser($user)->allows('viewAgregat', PenerimaManfaat::class);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $d = $this->agregat->hitung((int) $sppgId, true);
        $penanda = (string) ($d['penyamaran']['penanda'] ?? '<5');
        $nilai = fn (mixed $jumlah, ?string $tanda): string => $jumlah === null ? ($tanda ?? $penanda) : FormatLayar::angka($jumlah);

        $isi = [
            '- '.self::e('Penerima aktif: '.$nilai($d['aktif'], $d['aktif_penanda'])),
            '- '.self::e('Total terdaftar: '.$nilai($d['total'], $d['total_penanda'])),
            "\n<b>Per kelompok sasaran (aktif)</b>",
        ];

        foreach ($d['per_kelompok_sasaran'] as $sel) {
            $isi[] = '- '.self::e($sel['label'].': '.$nilai($sel['jumlah'], $sel['penanda']));
        }

        $isi[] = '<i>'.self::e("Jumlah kurang dari {$d['penyamaran']['ambang_k']} disamarkan ({$penanda}) untuk melindungi privasi anak.").'</i>';

        return $this->susun($user, $sppgId, $konteks, $isi);
    }
}

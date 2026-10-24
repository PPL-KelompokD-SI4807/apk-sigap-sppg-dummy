<?php

namespace App\Services\Notification;

use App\Enums\KategoriKeluhan;
use App\Enums\Role;
use App\Models\NotifikasiPerjalanan;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pemetaan event domain -> NotificationMessage (penerima berbasis peran + isi pesan aman).
 * Sumber kebenaran tabel penerima: docs/api/notifikasi.md.
 *
 * Event dicocokkan berdasarkan NAMA KELAS PENDEK (App\Events\<Nama>) dan dibaca dari properti
 * publiknya, sehingga event grup lain yang belum ada cukup mengikuti nama + properti yang
 * didokumentasikan. Isi pesan diminimalkan (tanpa nomor telepon/chat id/NIK).
 */
class DomainEventNotifications
{
    /** Event yang didengarkan (didaftarkan hanya bila kelas App\Events\<nama> ada saat boot). */
    public const EVENTS = [
        'StokMenipis', 'BahanAkanKedaluwarsa', 'KekuranganBahan', 'MutuTidakLayak', 'KeluhanKritis',
        'PerjalananDimulai', 'PerjalananTiba', 'PerjalananTerlambat', 'RekapHarianDibuat',
        'BuktiDistribusiDicatat', 'DistribusiTerlambat',
        'AnggaranMencapai80', 'AnggaranMencapai100', 'AnggaranMelampaui',
    ];

    /** Peran penerima per event (PIC titik layanan untuk perjalanan ditangani khusus). */
    public const RECIPIENTS = [
        'StokMenipis' => [Role::StafGudang, Role::KepalaSppg],
        'BahanAkanKedaluwarsa' => [Role::StafGudang, Role::KepalaSppg],
        'KekuranganBahan' => [Role::StafGudang, Role::KepalaSppg],
        'MutuTidakLayak' => [Role::KepalaSppg, Role::AhliGizi],
        'KeluhanKritis' => [Role::KepalaSppg, Role::AhliGizi],
        'PerjalananDimulai' => [Role::KepalaSppg],
        'PerjalananTiba' => [Role::KepalaSppg],
        'PerjalananTerlambat' => [Role::KepalaSppg],
        'RekapHarianDibuat' => [Role::KepalaSppg, Role::AhliGizi, Role::Akuntan],
        'BuktiDistribusiDicatat' => [Role::KepalaSppg],
        'DistribusiTerlambat' => [Role::KepalaSppg],
        'AnggaranMencapai80' => [Role::Akuntan, Role::KepalaSppg],
        'AnggaranMencapai100' => [Role::Akuntan, Role::KepalaSppg],
        'AnggaranMelampaui' => [Role::Akuntan, Role::KepalaSppg],
    ];

    /** Event kritis: bila tidak ada penerima peran, kirim ke Admin (Risiko 10 proposal). */
    public const FALLBACK_ADMIN = ['MutuTidakLayak', 'KeluhanKritis'];

    /** @return list<class-string> */
    public static function registeredEventClasses(): array
    {
        return array_values(array_filter(
            array_map(fn (string $name) => 'App\\Events\\'.$name, self::EVENTS),
            fn (string $class) => class_exists($class),
        ));
    }

    public function build(object $event): ?NotificationMessage
    {
        $name = class_basename($event);

        if (! in_array($name, self::EVENTS, true)) {
            return null;
        }

        $p = get_object_vars($event);
        $sppgId = $this->int($p, 'sppgId', 'sppg_id');

        if ($sppgId === null) {
            return null;
        }

        return match ($name) {
            'StokMenipis' => $this->stokMenipis($name, $sppgId, $p),
            'BahanAkanKedaluwarsa' => $this->kedaluwarsa($name, $sppgId, $p),
            'KekuranganBahan' => $this->kekuranganBahan($name, $sppgId, $p),
            'MutuTidakLayak' => $this->mutuTidakLayak($name, $sppgId, $p),
            'KeluhanKritis' => $this->keluhanKritis($name, $sppgId, $p),
            'PerjalananDimulai', 'PerjalananTiba', 'PerjalananTerlambat' => $this->perjalanan($name, $sppgId, $p),
            'RekapHarianDibuat' => $this->rekap($name, $sppgId, $p),
            'BuktiDistribusiDicatat' => $this->buktiDistribusi($name, $sppgId, $p),
            'DistribusiTerlambat' => $this->distribusiTerlambat($name, $sppgId, $p),
            default => $this->anggaran($name, $sppgId, $p),
        };
    }

    // ---------------------------------------------------------------- per event

    /** @param array<string, mixed> $p */
    private function stokMenipis(string $name, int $sppgId, array $p): NotificationMessage
    {
        $satuan = $this->str($p, 'satuan');
        $html = sprintf(
            "<b>Stok menipis</b>\n%s: sisa %s %s (minimum %s %s). Segera lakukan pengadaan.",
            $this->e($this->str($p, 'nama')),
            $this->e($this->num($p, 'stok')),
            $this->e($satuan),
            $this->e($this->num($p, 'stokMinimum', 'stok_minimum')),
            $this->e($satuan),
        );

        return $this->message($name, $sppgId, "stok:{$sppgId}:".$this->int($p, 'bahanBakuId', 'bahan_baku_id').':'.$this->today(), $html, 'Buka Bahan Baku', '/bahan-baku');
    }

    /** @param array<string, mixed> $p */
    private function kedaluwarsa(string $name, int $sppgId, array $p): NotificationMessage
    {
        $sisa = $this->int($p, 'sisaHari', 'sisa_hari') ?? 0;
        $tanggal = $this->str($p, 'tanggalKedaluwarsa', 'tanggal_kedaluwarsa');
        $keterangan = match (true) {
            $sisa < 0 => "sudah kedaluwarsa sejak {$tanggal}",
            $sisa === 0 => "kedaluwarsa hari ini ({$tanggal})",
            default => "kedaluwarsa dalam {$sisa} hari ({$tanggal})",
        };

        $html = sprintf(
            "<b>Bahan akan kedaluwarsa</b>\n%s (stok %s %s) %s.",
            $this->e($this->str($p, 'nama')),
            $this->e($this->num($p, 'stok')),
            $this->e($this->str($p, 'satuan')),
            $this->e($keterangan),
        );

        return $this->message($name, $sppgId, "kedaluwarsa:{$sppgId}:".$this->int($p, 'bahanBakuId', 'bahan_baku_id').":{$tanggal}:".$this->today(), $html, 'Buka Bahan Baku', '/bahan-baku');
    }

    /** @param array<string, mixed> $p */
    private function kekuranganBahan(string $name, int $sppgId, array $p): NotificationMessage
    {
        $items = is_array($p['kekurangan'] ?? null) ? $p['kekurangan'] : [];
        $lines = [];

        foreach (array_slice($items, 0, 8) as $item) {
            if (is_array($item)) {
                $lines[] = sprintf('- %s: kurang %s %s', $this->e((string) ($item['nama'] ?? '-')), $this->e($this->fmt($item['kekurangan'] ?? 0)), $this->e((string) ($item['satuan'] ?? '')));
            }
        }

        if (count($items) > 8) {
            $lines[] = '- dan '.(count($items) - 8).' bahan lain';
        }

        $html = sprintf(
            "<b>Kekurangan bahan</b> untuk produksi %s (%s, %s porsi):\n%s",
            $this->e($this->str($p, 'namaMenu', 'nama_menu')),
            $this->e($this->str($p, 'tanggalProduksi', 'tanggal_produksi')),
            $this->e($this->num($p, 'targetPorsi', 'target_porsi')),
            implode("\n", $lines),
        );

        $key = "kekurangan:{$sppgId}:".$this->int($p, 'jadwalProduksiId', 'jadwal_produksi_id').':'.md5((string) json_encode($items));

        return $this->message($name, $sppgId, $key, $html, 'Buka Jadwal', '/jadwal');
    }

    /** @param array<string, mixed> $p */
    private function mutuTidakLayak(string $name, int $sppgId, array $p): NotificationMessage
    {
        $tindakan = $this->str($p, 'tindakanKorektif', 'tindakan_korektif');
        $ditahan = $this->int($p, 'jumlahDistribusiDitahan', 'jumlah_distribusi_ditahan') ?? 0;

        $html = sprintf(
            "<b>Hasil pemeriksaan mutu TIDAK LAYAK</b>\nBatch %s (%s).%s%s",
            $this->e($this->str($p, 'namaMenu', 'nama_menu')),
            $this->e($this->str($p, 'tanggalProduksi', 'tanggal_produksi')),
            $ditahan > 0 ? "\n{$ditahan} jadwal distribusi ditahan." : '',
            $tindakan !== '' ? "\nTindakan korektif: ".$this->e(mb_substr($tindakan, 0, 200)) : '',
        );

        $id = $this->int($p, 'pemeriksaanMutuId', 'pemeriksaan_mutu_id', 'id');

        return $this->message($name, $sppgId, "mutu:{$sppgId}:{$id}", $html, 'Buka Pemeriksaan Mutu', '/mutu');
    }

    /** @param array<string, mixed> $p */
    private function keluhanKritis(string $name, int $sppgId, array $p): NotificationMessage
    {
        $kategori = $this->str($p, 'kategori');
        $label = KategoriKeluhan::tryFrom($kategori)?->label() ?? $kategori;
        $titik = $this->str($p, 'titikLayananNama', 'titik_layanan_nama');

        $html = sprintf(
            "<b>Keluhan KRITIS</b>: %s%s\n%s\nSegera tindak lanjuti.",
            $this->e($label),
            $titik !== '' ? ' di '.$this->e($titik) : '',
            $this->e(mb_substr($this->str($p, 'deskripsi'), 0, 150)),
        );

        return $this->message($name, $sppgId, "keluhan:{$sppgId}:".$this->int($p, 'id'), $html, 'Buka Keluhan', '/keluhan');
    }

    /** @param array<string, mixed> $p */
    private function perjalanan(string $name, int $sppgId, array $p): NotificationMessage
    {
        $id = (int) $this->int($p, 'id');
        $titik = $this->e($this->str($p, 'titikLayananNama', 'titik_layanan_nama'));
        $picChat = $this->int($p, 'picTelegramChatId', 'pic_telegram_chat_id');
        $dialihkan = (bool) ($p['dialihkanKeKepala'] ?? $p['dialihkan_ke_kepala'] ?? $picChat === null);
        $toPic = $picChat !== null && ! $dialihkan;
        $eta = $this->time($this->str($p, 'estimasiTiba', 'estimasi_tiba'));

        if ($name === 'PerjalananDimulai') {
            $pesan = (string) (NotifikasiPerjalanan::query()->withoutGlobalScopes()->whereKey($id)->value('pesan') ?? '');
            $isi = $pesan !== '' ? $this->e($pesan) : "Mobil SPPG berangkat menuju {$titik}, estimasi tiba {$eta} WIB.";
            $html = $toPic
                ? "<b>SiGAP SPPG</b>\n{$isi}"
                : "<b>Perjalanan dimulai</b>\n{$isi}\nPIC titik layanan belum menghubungkan Telegram, notifikasi dialihkan ke Anda.";
        } elseif ($name === 'PerjalananTiba') {
            $html = "<b>SiGAP SPPG</b>\nMobil SPPG tercatat tiba di {$titik}.";
        } else {
            $html = "<b>Perjalanan terlambat</b>\nMobil SPPG menuju {$titik} belum tiba; estimasi {$eta} WIB terlewati.";
        }

        return new NotificationMessage(
            type: $name,
            dedupKey: "{$name}:{$sppgId}:{$id}",
            sppgId: $sppgId,
            html: $html,
            // PIC terhubung -> PIC; selain itu Kepala SPPG. Keterlambatan selalu juga ke Kepala SPPG.
            roles: $toPic && $name !== 'PerjalananTerlambat' ? [] : self::RECIPIENTS[$name],
            chatIds: $toPic ? [$picChat] : [],
            buttonLabel: 'Buka Notifikasi Perjalanan',
            buttonPath: '/notifikasi-perjalanan',
            track: $name === 'PerjalananDimulai' && $toPic ? ['type' => DeliveryTracker::PERJALANAN, 'id' => $id] : null,
        );
    }

    /** @param array<string, mixed> $p */
    private function rekap(string $name, int $sppgId, array $p): NotificationMessage
    {
        $r = is_array($p['ringkasan'] ?? null) ? $p['ringkasan'] : [];
        $g = fn (string $path) => $this->e($this->fmt(data_get($r, $path, 0)));

        $html = implode("\n", [
            '<b>Rekap Harian '.$this->e($this->str($p, 'tanggal')).'</b>',
            "Porsi: {$g('porsi.realisasi')}/{$g('porsi.target')} ({$g('porsi.capaian_persen')}%)",
            "Mutu: {$g('mutu.layak')} layak, {$g('mutu.tidak_layak')} tidak layak, {$g('mutu.belum_diperiksa')} belum diperiksa",
            "Distribusi: {$g('distribusi.tiba')}/{$g('distribusi.total')} tiba, {$g('distribusi.terlambat')} terlambat",
            "Keluhan: {$g('keluhan.baru')} baru, {$g('keluhan.kritis')} kritis, {$g('keluhan.terbuka')} terbuka",
            "Stok: {$g('stok.menipis')} menipis, {$g('stok.akan_kedaluwarsa')} akan kedaluwarsa",
            "Anggaran: serapan {$g('keuangan.serapan_persen')}%, sisa Rp {$g('keuangan.sisa_anggaran')}",
            "Sisa makanan: {$g('sisa_makanan_kg')} kg",
        ]);

        $id = (int) $this->int($p, 'id');

        return new NotificationMessage(
            type: $name,
            // Kirim ulang disengaja (menit berbeda) tetap terkirim; event ganda di menit yang sama tidak.
            dedupKey: "rekap:{$sppgId}:{$id}:".now()->format('YmdHi'),
            sppgId: $sppgId,
            html: $html,
            roles: self::RECIPIENTS[$name],
            buttonLabel: 'Buka Rekap Harian',
            buttonPath: '/rekap-harian',
            track: ['type' => DeliveryTracker::REKAP, 'id' => $id],
        );
    }

    /** @param array<string, mixed> $p */
    private function buktiDistribusi(string $name, int $sppgId, array $p): NotificationMessage
    {
        $diterima = $this->num($p, 'porsiDiterima', 'porsi_diterima');
        $dikirim = $this->num($p, 'porsiDikirim', 'porsi_dikirim', 'jumlahPorsi', 'jumlah_porsi');
        $terlambat = (bool) ($p['terlambat'] ?? false);
        $menit = $this->int($p, 'menitKeterlambatan', 'menit_keterlambatan');

        $html = sprintf(
            "<b>Bukti distribusi tercatat</b>\n%s: %s porsi diterima%s.%s",
            $this->e($this->str($p, 'titikLayananNama', 'titik_layanan_nama')),
            $this->e($diterima),
            $dikirim !== '' ? ' dari '.$this->e($dikirim).' dikirim' : '',
            $terlambat ? ' Terlambat'.($menit ? " {$menit} menit" : '').'.' : '',
        );

        $key = "bukti:{$sppgId}:".($this->int($p, 'id', 'buktiDistribusiId') ?? $this->int($p, 'jadwalDistribusiId', 'jadwal_distribusi_id'));

        return $this->message($name, $sppgId, $key, $html, 'Buka Bukti Distribusi', '/bukti-distribusi');
    }

    /** @param array<string, mixed> $p */
    private function distribusiTerlambat(string $name, int $sppgId, array $p): NotificationMessage
    {
        $menit = $this->int($p, 'menitKeterlambatan', 'menit_keterlambatan');
        $html = sprintf(
            "<b>Bukti distribusi terlambat</b>\nJadwal distribusi ke %s belum memiliki bukti serah terima%s.",
            $this->e($this->str($p, 'titikLayananNama', 'titik_layanan_nama')),
            $menit ? " ({$menit} menit melewati rencana)" : '',
        );

        $key = "distribusi_terlambat:{$sppgId}:".$this->int($p, 'jadwalDistribusiId', 'jadwal_distribusi_id', 'id');

        return $this->message($name, $sppgId, $key, $html, 'Buka Bukti Distribusi', '/bukti-distribusi');
    }

    /** @param array<string, mixed> $p */
    private function anggaran(string $name, int $sppgId, array $p): NotificationMessage
    {
        $periode = $this->str($p, 'periode');
        $persen = $this->num($p, 'persentase', 'serapanPersen', 'serapan_persen');
        $sisa = $this->num($p, 'sisaAnggaran', 'sisa_anggaran', 'sisa');
        $judul = match ($name) {
            'AnggaranMencapai80' => 'Serapan anggaran mencapai 80%',
            'AnggaranMencapai100' => 'Serapan anggaran mencapai 100%',
            default => 'Pengeluaran MELAMPAUI dana pencairan',
        };

        $html = sprintf(
            "<b>%s</b>\nPeriode %s: serapan %s%%%s.",
            $judul,
            $this->e($periode),
            $this->e($persen !== '' ? $persen : '-'),
            $sisa !== '' ? ', sisa anggaran Rp '.$this->e($sisa) : '',
        );

        return $this->message($name, $sppgId, "{$name}:{$sppgId}:{$periode}", $html, 'Buka Keuangan', '/keuangan');
    }

    // ---------------------------------------------------------------- helper

    private function message(string $name, int $sppgId, string $dedupKey, string $html, string $buttonLabel, string $buttonPath): NotificationMessage
    {
        return new NotificationMessage(
            type: $name,
            dedupKey: $dedupKey,
            sppgId: $sppgId,
            html: $html,
            roles: self::RECIPIENTS[$name],
            buttonLabel: $buttonLabel,
            buttonPath: $buttonPath,
            fallbackToAdmin: in_array($name, self::FALLBACK_ADMIN, true),
        );
    }

    private function e(string $text): string
    {
        return TelegramBotService::escapeHtml($text);
    }

    /** @param array<string, mixed> $p */
    private function str(array $p, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($p[$key]) && (is_string($p[$key]) || is_numeric($p[$key]))) {
                return mb_substr((string) $p[$key], 0, 500);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $p */
    private function int(array $p, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($p[$key]) && is_numeric($p[$key])) {
                return (int) $p[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $p */
    private function num(array $p, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($p[$key]) && is_numeric($p[$key])) {
                return $this->fmt($p[$key]);
            }
        }

        return '';
    }

    /** Angka gaya Indonesia: 43750000.00 -> 43.750.000; 12.5 -> 12,5. */
    private function fmt(mixed $value): string
    {
        if (! is_numeric($value)) {
            return is_scalar($value) ? (string) $value : '-';
        }

        $f = (float) $value;

        if (floor($f) === $f) {
            return number_format($f, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($f, 2, ',', '.'), '0'), ',');
    }

    private function time(string $iso): string
    {
        try {
            return $iso === '' ? '-' : Carbon::parse($iso)->setTimezone((string) config('operasional.timezone', 'Asia/Jakarta'))->format('H:i');
        } catch (Throwable) {
            return '-';
        }
    }

    private function today(): string
    {
        return Carbon::now((string) config('operasional.timezone', 'Asia/Jakarta'))->toDateString();
    }
}

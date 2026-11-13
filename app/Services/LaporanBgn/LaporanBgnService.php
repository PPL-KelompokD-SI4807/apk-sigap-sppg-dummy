<?php

namespace App\Services\LaporanBgn;

use App\Enums\BarisSasaranBgn;
use App\Enums\JenisLaporanBgn;
use App\Enums\KelompokPorsiBgn;
use App\Enums\Role;
use App\Enums\SubKategoriPengeluaran;
use App\Models\AuditLog;
use App\Models\LaporanBgn;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use App\Models\User;
use App\Services\PenerimaManfaat\AgregatPenerimaService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

/**
 * Layanan agregasi laporan BGN Lampiran 30a-30d (R07-2). Hanya DATA terstruktur (JSON); render PDF ada di R07-3.
 *
 * Aturan lintas laporan:
 *  - Otorisasi dilakukan pemanggil lewat LaporanBgnPolicy; service ini selalu memakai sppg_id eksplisit.
 *  - Investor (`$pengguna->isInvestor()`): data tersamar (30a: ambang k=5 ADR 2026-10-26; 30d-B: perihal memakai
 *    label baku, bukan teks bebas). 30b dan 30d-C tidak pernah sampai ke sini untuk investor (Policy 403).
 *  - Estimasi porsi per kelompok (Q-3) dan penerima belum terklasifikasi tidak ditebak (K2).
 *  - Tiap pemanggilan tercatat di audit_logs (siapa, jenis, periode, SPPG; tanpa isi data).
 */
class LaporanBgnService
{
    public const MAKS_HARI_PERIODE = 16;

    private const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    private const DIGABUNG = 'digabung';

    public function __construct(
        private readonly RingkasanKeuanganService $keuangan,
        private readonly KlasifikasiPenerimaService $penerima,
        private readonly PorsiProduksiService $porsi,
        private readonly PenomoranLaporanService $penomoran,
    ) {}

    /**
     * @param  array{akuntan_id?: int|null, kepala_sppg_id?: int|null}  $opsi  pilihan penandatangan (Q-7)
     * @return array<string, mixed>
     */
    public function susun(JenisLaporanBgn $jenis, Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir, User $pengguna, array $opsi = []): array
    {
        $terbitkan = $pengguna->can('terbitkanNomor', LaporanBgn::class);
        $catatan = $this->penomoran->catat($sppg, $jenis, $awal, $akhir, (int) $pengguna->getKey(), $terbitkan);
        $this->catatAudit($pengguna, $sppg, $jenis, $catatan);

        $peringatan = [];
        $samarkan = $pengguna->isInvestor();

        $isi = match ($jenis) {
            JenisLaporanBgn::Porsi => $this->porsiBgn($sppg, $awal, $akhir, $samarkan, $peringatan),
            JenisLaporanBgn::Harian => $this->harian($sppg, $awal, $peringatan),
            JenisLaporanBgn::DuaMingguan, JenisLaporanBgn::BulananRingkasan => $this->ringkasan($sppg, $awal, $akhir, $peringatan),
            JenisLaporanBgn::BulananAnggaran => $this->anggaran($sppg, $awal, $akhir, $samarkan, $peringatan),
            JenisLaporanBgn::BulananPenerima => $this->penerimaBulanan($sppg, $awal, $akhir, $peringatan),
        };

        foreach ($this->identitasKurang($sppg, $jenis) as $kolom) {
            $peringatan[] = ['kode' => 'identitas_sppg_belum_lengkap', 'pesan' => "Identitas SPPG belum lengkap: {$kolom}."];
        }

        $penandatangan = $this->penandatangan($sppg, $akhir, $opsi, $peringatan);

        return [
            'jenis' => $jenis->value,
            'lampiran' => $jenis->lampiran(),
            'judul' => $jenis->judul(),
            'periode' => ['awal' => $awal->toDateString(), 'akhir' => $akhir->toDateString(), 'tahun' => (int) $akhir->format('Y')],
            'nomor' => $jenis->bernomor() ? $catatan->nomor : null,
            'sppg' => $this->identitas($sppg),
            'penandatangan' => $penandatangan,
            'peringatan' => self::unik($peringatan),
        ] + $isi;
    }

    // ------------------------------------------------------------------ 30a

    /**
     * @param  list<array<string, string>>  $peringatan
     * @return array<string, mixed>
     */
    private function porsiBgn(Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir, bool $samarkan, array &$peringatan): array
    {
        $sppgId = (int) $sppg->getKey();
        $pengaturan = $sppg->pengaturanAnggaran();
        $perTanggal = $this->porsi->perTanggal($sppgId, $awal, $akhir);
        $jumlah = $this->penerima->per15Kelompok($sppgId);

        $kolom = [];

        foreach ($this->rentang($awal, $akhir) as $t) {
            $d = $perTanggal[$t->toDateString()] ?? null;
            $ada = $d !== null && ($d['porsi'] + $d['food_sample'] + $d['uji_organoleptik']) > 0;

            if ($t->dayOfWeekIso <= 5 || $ada) {
                $kolom[$t->startOfWeek()->toDateString()][] = $t;
            }
        }

        $kunciKelompok = array_map(fn (KelompokPorsiBgn $k) => $k->value, KelompokPorsiBgn::cases());
        $kunciKelompok[] = KlasifikasiPenerimaService::BELUM;
        $anggaran = fn (string $kunci): int => KelompokPorsiBgn::tryFrom($kunci)?->kecil() === true
            ? $pengaturan['acuan_bahan_baku_kecil'] : $pengaturan['acuan_bahan_baku_lainnya'];

        $tabel = [];
        $totalPeriode = ['porsi' => 0, 'biaya' => 0];

        foreach ($kolom as $pekan => $hari) {
            $baris = array_fill_keys($kunciKelompok, []);

            foreach ($hari as $t) {
                $d = $perTanggal[$t->toDateString()] ?? ['porsi' => 0, 'food_sample' => 0, 'uji_organoleptik' => 0];
                $alokasi = $this->bagiPorsi((int) $d['porsi'], $jumlah);
                $alokasi[KelompokPorsiBgn::FoodSample->value] = (int) $d['food_sample'];
                $alokasi[KelompokPorsiBgn::UjiOrganoleptik->value] = (int) $d['uji_organoleptik'];

                foreach ($kunciKelompok as $kunci) {
                    $p = $alokasi[$kunci] ?? 0;
                    $baris[$kunci][$t->toDateString()] = ['tanggal' => $t->toDateString(), 'porsi' => $p, 'biaya' => $p * $anggaran($kunci)];
                }
            }

            $tabel[] = ['pekan_mulai' => $pekan, 'hari' => $hari, 'baris' => $baris];
        }

        // Baris yang ditampilkan: 15 kelompok baku + "belum terklasifikasi" bila ada isinya.
        $hasil = [];

        foreach ($tabel as $t) {
            $rows = [];

            foreach ($kunciKelompok as $kunci) {
                $perHari = array_values($t['baris'][$kunci]);
                $totalPorsi = array_sum(array_column($perHari, 'porsi'));

                if ($kunci === KlasifikasiPenerimaService::BELUM && $totalPorsi === 0 && ($jumlah[$kunci] ?? 0) === 0) {
                    continue;
                }

                $kelompok = KelompokPorsiBgn::tryFrom($kunci);
                $rows[] = [
                    'kelompok' => $kunci,
                    'label' => $kelompok?->label() ?? 'Belum terklasifikasi',
                    'anggaran_per_porsi' => $anggaran($kunci),
                    'estimasi' => $kelompok === null || ! $kelompok->porsiLangsung(),
                    'per_hari' => $perHari,
                    'total_porsi' => $totalPorsi,
                    'total_biaya' => array_sum(array_column($perHari, 'biaya')),
                    'penanda' => null,
                ];
            }

            $hasil[] = ['pekan_mulai' => $t['pekan_mulai'], 'hari' => array_map(fn (CarbonImmutable $h) => ['tanggal' => $h->toDateString(), 'hari' => self::HARI[$h->dayOfWeek]], $t['hari']), 'baris' => $rows];
        }

        $penyamaran = ['diterapkan' => false, 'ambang_k' => null, 'penanda' => null];

        if ($samarkan) {
            $penyamaran = $this->samarkanPorsi($hasil, $jumlah);
        }

        foreach ($hasil as &$t) {
            $t['total'] = $this->totalPekan($t['baris'], $t['hari']);
            $totalPeriode['porsi'] += $t['total']['total_porsi'];
            $totalPeriode['biaya'] += $t['total']['total_biaya'];
        }
        unset($t);

        if (($jumlah[KlasifikasiPenerimaService::BELUM] ?? 0) > 0 && ! $samarkan) {
            $peringatan[] = ['kode' => 'penerima_belum_terklasifikasi', 'pesan' => $jumlah[KlasifikasiPenerimaService::BELUM].' penerima aktif belum terklasifikasi (jenjang/kelompok belum lengkap); porsinya dicatat terpisah, tidak ditebak.'];
        }

        return [
            'catatan_estimasi' => 'Porsi per kelompok adalah estimasi (proporsi penerima aktif per kelompok x realisasi porsi harian). Food Sample dan Uji Organoleptik adalah porsi tercatat.',
            'anggaran' => ['acuan_bahan_baku_kecil' => $pengaturan['acuan_bahan_baku_kecil'], 'acuan_bahan_baku_lainnya' => $pengaturan['acuan_bahan_baku_lainnya']],
            'tabel' => $hasil,
            'total_periode' => ['porsi' => $totalPeriode['porsi'], 'biaya' => $totalPeriode['biaya']],
            'penyamaran' => $penyamaran,
        ];
    }

    /**
     * Penyamaran investor (ADR k=5): kelompok dengan 1..k-1 penerima aktif (dan sel pelengkap) digabung ke satu baris
     * "digabung". Bila hasil gabungan masih dapat dihitung dari total (tak ada sel pelengkap), baris gabungan dan
     * total dikosongkan (null + penanda).
     *
     * @param  list<array<string, mixed>>  $tabel
     * @param  array<string, int>  $jumlah
     * @return array{diterapkan: bool, ambang_k: int, penanda: string}
     */
    private function samarkanPorsi(array &$tabel, array $jumlah): array
    {
        $k = AgregatPenerimaService::ambang();
        $penanda = '<'.$k;
        $aktif = array_sum($jumlah);
        $kunci = array_keys($jumlah);
        $kecilSemua = $aktif > 0 && $aktif < $k;

        [$topeng, $kosongkanTotal] = AgregatPenerimaService::topengTabel(array_values($jumlah), $k, true);
        $tersamar = [];

        foreach ($kunci as $i => $nama) {
            if ($kecilSemua || $topeng[$i]) {
                $tersamar[$nama] = true;
            }
        }

        if ($tersamar === []) {
            return ['diterapkan' => true, 'ambang_k' => $k, 'penanda' => $penanda];
        }

        foreach ($tabel as &$t) {
            $terlihat = [];
            $gabung = null;

            foreach ($t['baris'] as $baris) {
                if (! isset($tersamar[$baris['kelompok']])) {
                    $terlihat[] = $baris;

                    continue;
                }

                $gabung ??= ['kelompok' => self::DIGABUNG, 'label' => "Kelompok lain (digabung, penerima < {$k})", 'anggaran_per_porsi' => null, 'estimasi' => true, 'per_hari' => [], 'total_porsi' => 0, 'total_biaya' => 0, 'penanda' => $penanda];

                foreach ($baris['per_hari'] as $i => $h) {
                    $gabung['per_hari'][$i] ??= ['tanggal' => $h['tanggal'], 'porsi' => 0, 'biaya' => 0];
                    $gabung['per_hari'][$i]['porsi'] += $h['porsi'];
                    $gabung['per_hari'][$i]['biaya'] += $h['biaya'];
                }

                $gabung['total_porsi'] += $baris['total_porsi'];
                $gabung['total_biaya'] += $baris['total_biaya'];
            }

            if ($gabung !== null) {
                if ($kosongkanTotal) {
                    $gabung['per_hari'] = array_map(fn (array $h) => ['tanggal' => $h['tanggal'], 'porsi' => null, 'biaya' => null], $gabung['per_hari']);
                    $gabung['total_porsi'] = null;
                    $gabung['total_biaya'] = null;
                }

                $terlihat[] = $gabung;
            }

            $t['baris'] = $terlihat;
        }
        unset($t);

        return ['diterapkan' => true, 'ambang_k' => $k, 'penanda' => $penanda];
    }

    /**
     * @param  list<array<string, mixed>>  $baris
     * @param  list<array{tanggal: string, hari: string}>  $hari
     * @return array<string, mixed>
     */
    private function totalPekan(array $baris, array $hari): array
    {
        $disembunyikan = false;
        $perHari = array_map(fn (array $h) => ['tanggal' => $h['tanggal'], 'porsi' => 0, 'biaya' => 0], $hari);

        foreach ($baris as $b) {
            if ($b['total_porsi'] === null) {
                $disembunyikan = true;
            }
        }

        // Total disembunyikan bila baris gabungan dikosongkan (nilai gabungan dapat dihitung dari total).
        if ($disembunyikan) {
            return ['per_hari' => array_map(fn (array $h) => ['tanggal' => $h['tanggal'], 'porsi' => null, 'biaya' => null], $perHari), 'total_porsi' => null, 'total_biaya' => null, 'penanda' => '<'.AgregatPenerimaService::ambang()];
        }

        foreach ($baris as $b) {
            foreach ($b['per_hari'] as $i => $h) {
                $perHari[$i]['porsi'] += $h['porsi'];
                $perHari[$i]['biaya'] += $h['biaya'];
            }
        }

        return ['per_hari' => $perHari, 'total_porsi' => array_sum(array_column($perHari, 'porsi')), 'total_biaya' => array_sum(array_column($perHari, 'biaya')), 'penanda' => null];
    }

    // ------------------------------------------------------------------ 30b

    /**
     * @param  list<array<string, string>>  $peringatan
     * @return array<string, mixed>
     */
    private function harian(Sppg $sppg, CarbonImmutable $tanggal, array &$peringatan): array
    {
        $sppgId = (int) $sppg->getKey();
        $pengaturan = $sppg->pengaturanAnggaran();
        $pengeluaran = $this->keuangan->pengeluaran($sppgId, $tanggal, $tanggal);
        $realisasi = $this->keuangan->realisasi($pengeluaran);
        $bb = $this->keuangan->rincianBahanBaku($pengeluaran);
        $perTanggal = $this->porsi->perTanggal($sppgId, $tanggal, $tanggal);
        $operasional = $this->hariOperasional($perTanggal, $pengeluaran)[$tanggal->toDateString()] ?? false;

        $rincian = $this->penerima->rincianPerTitik($sppgId);
        $blok = ['peserta_didik' => [], 'non_peserta_didik' => []];

        foreach (BarisSasaranBgn::cases() as $baris) {
            $titik = array_values($rincian['rincian'][$baris->value]);
            $laki = array_sum(array_column($titik, 'laki_laki'));
            $perempuan = array_sum(array_column($titik, 'perempuan'));
            $tanpa = array_sum(array_column($titik, 'tanpa_jenis_kelamin'));

            $blok[$baris->pesertaDidik() ? 'peserta_didik' : 'non_peserta_didik'][] = [
                'baris' => $baris->value,
                'label' => $baris->label(),
                'rincian' => array_map(fn (array $t) => $t + ['jumlah' => $t['laki_laki'] + $t['perempuan'] + $t['tanpa_jenis_kelamin']], $titik),
                'laki_laki' => $laki,
                'perempuan' => $perempuan,
                'tanpa_jenis_kelamin' => $tanpa,
                'jumlah' => $laki + $perempuan + $tanpa,
            ];
        }

        $total = fn (array $baris) => [
            'laki_laki' => array_sum(array_column($baris, 'laki_laki')),
            'perempuan' => array_sum(array_column($baris, 'perempuan')),
            'tanpa_jenis_kelamin' => array_sum(array_column($baris, 'tanpa_jenis_kelamin')),
            'jumlah' => array_sum(array_column($baris, 'jumlah')),
        ];

        if ($rincian['belum_terklasifikasi'] > 0) {
            $peringatan[] = ['kode' => 'penerima_belum_terklasifikasi', 'pesan' => $rincian['belum_terklasifikasi'].' penerima aktif belum terklasifikasi (jenjang/kelompok belum lengkap) dan tidak masuk baris mana pun.'];
        }

        $baris = [];

        foreach (SubKategoriPengeluaran::cases() as $sub) {
            $baris[] = [
                'sub_kategori' => $sub->value,
                'label' => $sub->label(),
                'biaya_per_hari' => $realisasi['per_sub'][$sub->value]['jumlah'],
                'keterangan' => $realisasi['per_sub'][$sub->value]['keterangan'] === [] ? null : 'Kategori lama: '.implode(', ', $realisasi['per_sub'][$sub->value]['keterangan']),
            ];
        }

        return [
            'tanggal' => $tanggal->toDateString(),
            'sasaran_penerima_manfaat' => [
                'peserta_didik' => ['baris' => $blok['peserta_didik'], 'total' => $total($blok['peserta_didik'])],
                'non_peserta_didik' => ['baris' => $blok['non_peserta_didik'], 'total' => $total($blok['non_peserta_didik'])],
                'belum_terklasifikasi' => $rincian['belum_terklasifikasi'],
            ],
            'rincian_pembelanjaan' => [
                'bahan_baku_pangan' => $bb,
                'biaya_operasional' => ['baris' => $baris, 'total' => $realisasi['operasional']],
                'insentif_fasilitas' => [
                    'uraian' => 'Biaya Insentif Fasilitas SPPG',
                    'jumlah' => $realisasi['insentif_fasilitas'],
                    'acuan' => $operasional ? $pengaturan['insentif_fasilitas_per_hari'] : 0,
                    'keterangan' => $operasional ? 'Hari operasional' : 'Bukan hari operasional',
                ],
                'total_belanja' => $realisasi['total'],
            ],
        ];
    }

    // ------------------------------------------------------------------ 30c, 30d-A

    /**
     * @param  list<array<string, string>>  $peringatan
     * @return array<string, mixed>
     */
    private function ringkasan(Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir, array &$peringatan): array
    {
        $keuangan = $this->rincianKeuangan($sppg, $awal, $akhir);

        if ($keuangan['peringatan'] !== null) {
            $peringatan[] = $this->pesanSaldo($keuangan['peringatan']);
        }

        $r = $keuangan['realisasi'];
        $rp = fn (int $n): string => 'Rp'.number_format($n, 0, ',', '.');
        $ops = [];

        foreach (SubKategoriPengeluaran::cases() as $sub) {
            if ($r['per_sub'][$sub->value]['jumlah'] > 0) {
                $ops[] = $sub->label().' '.$rp($r['per_sub'][$sub->value]['jumlah']);
            }
        }

        $insentif = $keuangan['insentif_fasilitas'];

        return [
            'pelapor' => [
                'nama' => $sppg->nama_ketua_yayasan,
                'jabatan' => 'Ketua/Mewakili',
                'yayasan' => $sppg->mitra_yayasan,
                'sppg' => $sppg->nama,
                'id_sppg' => $sppg->id_sppg_bgn,
                'kecamatan' => $sppg->kecamatan,
                'kab_kota' => $sppg->kab_kota,
                'provinsi' => $sppg->provinsi,
            ],
            'rincian_keuangan' => [
                'saldo_awal' => $keuangan['saldo_awal'],
                'dana_pemasukan_total' => $keuangan['dana_pemasukan_total'],
                'realisasi_anggaran' => [
                    'bahan_baku' => $r['bahan_baku'],
                    'operasional' => $r['operasional'],
                    'insentif_fasilitas' => $r['insentif_fasilitas'],
                    'total' => $r['total'],
                ],
                'sisa_anggaran_total' => $keuangan['sisa_anggaran'],
            ],
            'keterangan' => [
                'kalimat_pembuka' => 'Dana yang telah digunakan sesuai dengan kebutuhan kegiatan yang telah direncanakan dengan rincian sebagai berikut:',
                'bahan_baku' => $r['jumlah_transaksi']['bahan_baku'] === 0
                    ? 'Tidak ada realisasi belanja bahan baku.'
                    : "Belanja bahan baku pangan ({$r['jumlah_transaksi']['bahan_baku']} transaksi) sebesar ".$rp($r['bahan_baku']).'.',
                'operasional' => $ops === [] ? 'Tidak ada realisasi biaya operasional.' : implode('; ', $ops).'.',
                'insentif_fasilitas' => 'Biaya insentif fasilitas SPPG tercatat '.$rp($r['insentif_fasilitas']).sprintf(
                    ' (acuan: %d hari operasional dihitung x %s per hari, maksimal %d hari per minggu = %s).',
                    $insentif['hari_dihitung'], $rp($insentif['per_hari']), $insentif['maks_hari_minggu'], $rp($insentif['nilai_acuan']),
                ),
                'no_rekening_va' => $sppg->no_va,
                'sisa_dana_dialihkan' => 'Sisa dana sebesar '.$rp($keuangan['sisa_anggaran']).' akan dialihkan ke periode selanjutnya. Pengalihan sisa dana ini bertujuan untuk mendukung kegiatan yang telah direncanakan pada periode selanjutnya.',
            ],
            'insentif_fasilitas_acuan' => $insentif,
        ];
    }

    // ------------------------------------------------------------------ 30d-B

    /**
     * @param  list<array<string, string>>  $peringatan
     * @return array<string, mixed>
     */
    private function anggaran(Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir, bool $samarkan, array &$peringatan): array
    {
        $sppgId = (int) $sppg->getKey();
        $saldoAwal = $this->keuangan->saldoAwalPeriode($sppg, $awal);

        if ($saldoAwal['peringatan'] !== null) {
            $peringatan[] = $this->pesanSaldo($saldoAwal['peringatan']);
        }

        $pencairan = $this->keuangan->pencairan($sppgId, $awal, $akhir);
        $pengeluaran = $this->keuangan->pengeluaran($sppgId, $awal, $akhir);

        $no = 0;
        $saldo = $saldoAwal['saldo'];
        $baris = [[
            'no' => ++$no, 'tipe' => 'saldo_awal', 'kelompok' => null, 'tanggal' => $awal->toDateString(), 'perihal' => 'Penerimaan Saldo Awal',
            'dana_masuk' => $saldoAwal['saldo'], 'dana_keluar' => null, 'saldo' => $saldo, 'keterangan' => null,
        ]];

        $masukTotal = 0;

        foreach ($pencairan as $c) {
            $nominal = RingkasanKeuanganService::rupiah($c->nominal);
            $saldo += $nominal;
            $masukTotal += $nominal;
            $baris[] = [
                'no' => ++$no, 'tipe' => 'penerimaan', 'kelompok' => null, 'tanggal' => $c->tanggal_cair->toDateString(), 'perihal' => 'Penerimaan Dana',
                'dana_masuk' => $nominal, 'dana_keluar' => null, 'saldo' => $saldo, 'keterangan' => $samarkan ? null : $c->sumber_dana,
            ];
        }

        $baris[] = ['no' => null, 'tipe' => 'judul', 'kelompok' => null, 'tanggal' => null, 'perihal' => 'Pembelanjaan:', 'dana_masuk' => null, 'dana_keluar' => null, 'saldo' => null, 'keterangan' => null];

        $judul = [
            RingkasanKeuanganService::BB => 'Bahan Baku Pangan',
            RingkasanKeuanganService::OPS => 'Biaya Operasional',
            RingkasanKeuanganService::INSENTIF => 'Insentif Fasilitas SPPG',
        ];
        $keluarTotal = 0;
        $subtotal = [];

        foreach ($judul as $kelompok => $teks) {
            $subtotal[$kelompok] = 0;
            $indeksJudul = count($baris);
            $baris[] = ['no' => null, 'tipe' => 'subkelompok', 'kelompok' => $kelompok, 'tanggal' => null, 'perihal' => $teks, 'dana_masuk' => null, 'dana_keluar' => null, 'saldo' => null, 'keterangan' => null, 'subtotal' => 0];

            foreach ($pengeluaran as $p) {
                if (RingkasanKeuanganService::kelompok($p) !== $kelompok) {
                    continue;
                }

                $nominal = RingkasanKeuanganService::rupiah($p->nominal);
                $saldo -= $nominal;
                $keluarTotal += $nominal;
                $subtotal[$kelompok] += $nominal;
                [$sub, $ket] = $kelompok === RingkasanKeuanganService::OPS ? RingkasanKeuanganService::subOperasional($p) : [$p->sub_kategori, null];
                $label = $kelompok === RingkasanKeuanganService::OPS ? $sub->label() : $teks;
                $baris[] = [
                    'no' => ++$no, 'tipe' => 'pengeluaran', 'kelompok' => $kelompok, 'tanggal' => $p->tanggal->toDateString(),
                    // Peran agregat tidak menerima teks bebas (dapat memuat nama orang): label baku saja.
                    'perihal' => $samarkan || trim((string) $p->uraian) === '' ? $label : (string) $p->uraian,
                    'dana_masuk' => null, 'dana_keluar' => $nominal, 'saldo' => $saldo,
                    'keterangan' => $kelompok === RingkasanKeuanganService::OPS ? trim($label.($ket !== null ? " (kategori lama: {$ket})" : '')) : null,
                ];
            }

            $baris[$indeksJudul]['subtotal'] = $subtotal[$kelompok];
        }

        return [
            'saldo_awal' => $saldoAwal['saldo'],
            'baris' => $baris,
            'total' => ['dana_masuk' => $masukTotal, 'dana_keluar' => $keluarTotal, 'saldo_akhir' => $saldo],
            'subtotal_pengeluaran' => ['bahan_baku' => $subtotal[RingkasanKeuanganService::BB], 'operasional' => $subtotal[RingkasanKeuanganService::OPS], 'insentif_fasilitas' => $subtotal[RingkasanKeuanganService::INSENTIF]],
            'catatan_kaki' => '*dibuat dari tanggal awal sampai dengan akhir bulan',
        ];
    }

    // ------------------------------------------------------------------ 30d-C

    /**
     * @param  list<array<string, string>>  $peringatan
     * @return array<string, mixed>
     */
    private function penerimaBulanan(Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir, array &$peringatan): array
    {
        $sppgId = (int) $sppg->getKey();
        $jumlah = $this->penerima->perBarisSasaran($sppgId);
        $perTanggal = $this->porsi->perTanggal($sppgId, $awal, $akhir);

        if ($jumlah[KlasifikasiPenerimaService::BELUM] > 0) {
            $peringatan[] = ['kode' => 'penerima_belum_terklasifikasi', 'pesan' => $jumlah[KlasifikasiPenerimaService::BELUM].' penerima aktif belum terklasifikasi (jenjang/kelompok belum lengkap); porsinya dicatat di kolom terpisah, tidak ditebak.'];
        }

        $kolomPeserta = array_values(array_filter(BarisSasaranBgn::cases(), fn (BarisSasaranBgn $b) => $b->pesertaDidik()));
        $kolomNon = array_values(array_filter(BarisSasaranBgn::cases(), fn (BarisSasaranBgn $b) => ! $b->pesertaDidik()));
        $total = array_fill_keys(BarisSasaranBgn::values(), 0) + [KlasifikasiPenerimaService::BELUM => 0, 'total' => 0];
        $hari = [];

        foreach ($this->rentang($awal, $akhir) as $t) {
            $d = $perTanggal[$t->toDateString()] ?? null;
            $porsi = $d['porsi'] ?? 0;
            $alokasi = $this->bagiPorsi($porsi, $jumlah);

            foreach ($alokasi as $kunci => $n) {
                $total[$kunci] += $n;
            }
            $total['total'] += $porsi;

            $hari[] = [
                'hari' => self::HARI[$t->dayOfWeek],
                'tanggal' => $t->toDateString(),
                'ket' => $d === null ? 'tidak_ada_produksi' : ($d['sumber'] === 'log_produksi' ? 'produksi' : 'rencana_distribusi'),
                'peserta_didik' => array_map(fn (BarisSasaranBgn $b) => ['baris' => $b->value, 'porsi' => $alokasi[$b->value]], $kolomPeserta),
                'non_peserta_didik' => array_map(fn (BarisSasaranBgn $b) => ['baris' => $b->value, 'porsi' => $alokasi[$b->value]], $kolomNon),
                'belum_terklasifikasi' => $alokasi[KlasifikasiPenerimaService::BELUM],
                'total' => $porsi,
            ];
        }

        return [
            'catatan_estimasi' => 'Porsi per kelompok adalah estimasi (proporsi penerima aktif per kelompok x realisasi porsi harian).',
            'kolom' => [
                'peserta_didik' => array_map(fn (BarisSasaranBgn $b) => ['baris' => $b->value, 'label' => $b->label()], $kolomPeserta),
                'non_peserta_didik' => array_map(fn (BarisSasaranBgn $b) => ['baris' => $b->value, 'label' => $b->label()], $kolomNon),
            ],
            'hari' => $hari,
            'total_bulan' => [
                'peserta_didik' => array_map(fn (BarisSasaranBgn $b) => ['baris' => $b->value, 'porsi' => $total[$b->value]], $kolomPeserta),
                'non_peserta_didik' => array_map(fn (BarisSasaranBgn $b) => ['baris' => $b->value, 'porsi' => $total[$b->value]], $kolomNon),
                'belum_terklasifikasi' => $total[KlasifikasiPenerimaService::BELUM],
                'total' => $total['total'],
            ],
        ];
    }

    // ------------------------------------------------------------------ bersama

    /**
     * Rincian keuangan satu periode (30c dan 30d-A memakai angka yang sama dengan 30d-B).
     *
     * @return array<string, mixed>
     */
    private function rincianKeuangan(Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir): array
    {
        $sppgId = (int) $sppg->getKey();
        $saldoAwal = $this->keuangan->saldoAwalPeriode($sppg, $awal);
        $pengeluaran = $this->keuangan->pengeluaran($sppgId, $awal, $akhir);
        $realisasi = $this->keuangan->realisasi($pengeluaran);
        $pemasukan = $this->keuangan->pencairan($sppgId, $awal, $akhir)->sum(fn ($c) => RingkasanKeuanganService::rupiah($c->nominal));
        $pengaturan = $sppg->pengaturanAnggaran();

        $operasional = $this->hariOperasional($this->porsi->perTanggal($sppgId, $awal, $akhir), $pengeluaran);
        $perPekan = [];

        foreach (array_keys(array_filter($operasional)) as $tanggal) {
            $pekan = CarbonImmutable::parse($tanggal)->startOfWeek()->toDateString();
            $perPekan[$pekan] = ($perPekan[$pekan] ?? 0) + 1;
        }

        $dihitung = array_sum(array_map(fn (int $n) => min($n, $pengaturan['insentif_fasilitas_maks_hari_minggu']), $perPekan));

        return [
            'saldo_awal' => $saldoAwal['saldo'],
            'peringatan' => $saldoAwal['peringatan'],
            'dana_pemasukan_total' => $pemasukan,
            'realisasi' => $realisasi,
            'sisa_anggaran' => $saldoAwal['saldo'] + $pemasukan - $realisasi['total'],
            'insentif_fasilitas' => [
                'hari_operasional' => count(array_filter($operasional)),
                'hari_dihitung' => $dihitung,
                'per_hari' => $pengaturan['insentif_fasilitas_per_hari'],
                'maks_hari_minggu' => $pengaturan['insentif_fasilitas_maks_hari_minggu'],
                'nilai_acuan' => $dihitung * $pengaturan['insentif_fasilitas_per_hari'],
            ],
        ];
    }

    /**
     * Hari operasional = ada produksi (porsi > 0) atau ada pengeluaran disetujui selain insentif fasilitas.
     *
     * @param  array<string, array{porsi: int, food_sample: int, uji_organoleptik: int, sumber: string}>  $perTanggal
     * @param  Collection<int, Pengeluaran>  $pengeluaran
     * @return array<string, bool> kunci = tanggal
     */
    private function hariOperasional(array $perTanggal, Collection $pengeluaran): array
    {
        $hasil = [];

        foreach ($perTanggal as $tanggal => $d) {
            if ($d['sumber'] === 'log_produksi' && $d['porsi'] > 0) {
                $hasil[$tanggal] = true;
            }
        }

        foreach ($pengeluaran as $p) {
            if (RingkasanKeuanganService::kelompok($p) !== RingkasanKeuanganService::INSENTIF) {
                $hasil[$p->tanggal->toDateString()] = true;
            }
        }

        return $hasil;
    }

    /**
     * Bagi porsi harian menurut proporsi penerima. Bila tidak ada penerima aktif sama sekali, seluruh porsi masuk
     * `belum_terklasifikasi` agar jumlah tetap sama dengan realisasi.
     *
     * @param  array<string, int>  $jumlah
     * @return array<string, int>
     */
    private function bagiPorsi(int $porsi, array $jumlah): array
    {
        if (array_sum($jumlah) === 0) {
            return array_fill_keys(array_keys($jumlah), 0) + [KlasifikasiPenerimaService::BELUM => 0];
        }

        return EstimasiPorsi::bagi($porsi, $jumlah);
    }

    /** @return list<CarbonImmutable> */
    private function rentang(CarbonImmutable $awal, CarbonImmutable $akhir): array
    {
        $hasil = [];

        for ($t = $awal; $t <= $akhir; $t = $t->addDay()) {
            $hasil[] = $t;
        }

        return $hasil;
    }

    /** @return array<string, string|null> */
    private function identitas(Sppg $sppg): array
    {
        return [
            'id' => (int) $sppg->getKey(),
            'nama' => $sppg->nama,
            'kode' => $sppg->kode,
            'id_sppg_bgn' => $sppg->id_sppg_bgn,
            'kecamatan' => $sppg->kecamatan,
            'kab_kota' => $sppg->kab_kota,
            'provinsi' => $sppg->provinsi,
            'no_va' => $sppg->no_va,
            'yayasan' => $sppg->mitra_yayasan,
        ];
    }

    /** @return list<string> kolom identitas yang belum diisi dan dipakai jenis laporan ini */
    private function identitasKurang(Sppg $sppg, JenisLaporanBgn $jenis): array
    {
        $perlu = match ($jenis) {
            JenisLaporanBgn::Porsi, JenisLaporanBgn::BulananPenerima => ['kecamatan', 'kab_kota', 'provinsi'],
            JenisLaporanBgn::Harian => ['kecamatan', 'kab_kota', 'provinsi', 'nama_ketua_yayasan'],
            default => ['id_sppg_bgn', 'kecamatan', 'kab_kota', 'provinsi', 'no_va', 'nama_ketua_yayasan'],
        };

        return array_values(array_filter($perlu, fn (string $kolom) => trim((string) $sppg->getAttribute($kolom)) === ''));
    }

    /**
     * Blok tanda tangan (Q-7): Pihak Pertama = ketua yayasan; Pihak Kedua = akuntan SPPG; Mengetahui = kepala SPPG.
     * Nama kosong bila tidak ada atau lebih dari satu pengguna (pilih lewat akuntan_id/kepala_sppg_id).
     *
     * @param  array{akuntan_id?: int|null, kepala_sppg_id?: int|null}  $opsi
     * @param  list<array<string, string>>  $peringatan
     * @return array<string, mixed>
     */
    private function penandatangan(Sppg $sppg, CarbonInterface $tanggal, array $opsi, array &$peringatan): array
    {
        $akuntan = $this->calon($sppg, Role::Akuntan, $opsi['akuntan_id'] ?? null, 'akuntan_id', $peringatan);
        $kepala = $this->calon($sppg, Role::KepalaSppg, $opsi['kepala_sppg_id'] ?? null, 'kepala_sppg_id', $peringatan);

        return [
            'kota' => $sppg->kab_kota,
            'tanggal' => $tanggal->toDateString(),
            'pihak_pertama' => ['jabatan' => 'Ketua/Mewakili', 'yayasan' => $sppg->mitra_yayasan, 'nama' => $sppg->nama_ketua_yayasan],
            'pihak_kedua' => ['jabatan' => 'Staf Akuntansi SPPG', 'nama' => $akuntan['nama'], 'kandidat' => $akuntan['kandidat']],
            'mengetahui' => ['jabatan' => 'Kepala SPPG', 'nama' => $kepala['nama'], 'kandidat' => $kepala['kandidat']],
        ];
    }

    /**
     * @param  list<array<string, string>>  $peringatan
     * @return array{nama: string|null, kandidat: list<array{id: int, nama: string}>}
     */
    private function calon(Sppg $sppg, Role $peran, ?int $pilihan, string $parameter, array &$peringatan): array
    {
        $kandidat = User::query()
            ->where('sppg_id', $sppg->getKey())->where('role', $peran->value)->where('is_active', true)
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->getKey(), 'nama' => (string) $u->name])->all();

        if ($pilihan !== null) {
            $dipilih = collect($kandidat)->firstWhere('id', $pilihan);

            if ($dipilih === null) {
                throw ValidationException::withMessages([$parameter => ['Pengguna yang dipilih bukan '.strtolower($peran->label()).' aktif SPPG ini.']]);
            }

            return ['nama' => $dipilih['nama'], 'kandidat' => $kandidat];
        }

        if (count($kandidat) === 1) {
            return ['nama' => $kandidat[0]['nama'], 'kandidat' => $kandidat];
        }

        $peringatan[] = ['kode' => 'penandatangan_'.$peran->value.(count($kandidat) === 0 ? '_kosong' : '_lebih_dari_satu'), 'pesan' => count($kandidat) === 0
            ? 'Belum ada '.$peran->label().' aktif untuk blok tanda tangan.'
            : 'Ada lebih dari satu '.$peran->label().' aktif; pilih penandatangan lewat '.$parameter.'.'];

        return ['nama' => null, 'kandidat' => $kandidat];
    }

    /** @return array{kode: string, pesan: string} */
    private function pesanSaldo(string $kode): array
    {
        return ['kode' => $kode, 'pesan' => match ($kode) {
            'saldo_awal_belum_diisi' => 'Saldo awal belum diisi Akuntan; dipakai 0 ditambah mutasi sebelum periode.',
            default => 'Periode dimulai sebelum tanggal saldo awal; saldo awal tidak dipakai (0).',
        }];
    }

    /**
     * @param  list<array<string, string>>  $peringatan
     * @return list<array<string, string>>
     */
    private static function unik(array $peringatan): array
    {
        $lihat = [];

        return array_values(array_filter($peringatan, function (array $p) use (&$lihat): bool {
            $kunci = $p['kode'].'|'.$p['pesan'];

            return ! isset($lihat[$kunci]) && ($lihat[$kunci] = true);
        }));
    }

    private function catatAudit(User $pengguna, Sppg $sppg, JenisLaporanBgn $jenis, LaporanBgn $catatan): void
    {
        $request = App::runningInConsole() && ! App::runningUnitTests() ? null : request();
        $log = new AuditLog;
        $log->forceFill([
            'sppg_id' => $sppg->getKey(),
            'user_id' => $pengguna->getKey(),
            'event' => 'laporan_bgn_diakses',
            'auditable_type' => $catatan->getMorphClass(),
            'auditable_id' => $catatan->getKey(),
            'old_values' => null,
            'new_values' => [
                'jenis' => $jenis->value,
                'lampiran' => $jenis->lampiran(),
                'periode_awal' => $catatan->periode_awal->toDateString(),
                'periode_akhir' => $catatan->periode_akhir->toDateString(),
                'nomor' => $jenis->bernomor() ? $catatan->nomor : null,
            ],
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'sumber' => AuditLog::currentSource() ?? ($request ? 'web' : 'sistem'),
        ]);
        $log->save();
    }
}

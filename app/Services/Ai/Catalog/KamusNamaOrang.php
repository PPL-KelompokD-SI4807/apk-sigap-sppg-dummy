<?php

namespace App\Services\Ai\Catalog;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kamus kata nama orang yang TERDAFTAR di kolom data orang (issue #175): nama penerima manfaat, pengemudi, PIC
 * titik layanan, PIC bukti distribusi, dan nama pengguna. Dipakai RecordPresenter::redactPersonal() agar nama
 * orang yang dikenal tetap menjadi inisial ketika muncul di teks bebas (mis. deskripsi keluhan), tanpa
 * menyingkat setiap kata berhuruf kapital ("Kemasan Uji Bocor" tetap utuh).
 *
 * Sengaja lintas SPPG (tanpa SppgScope, termasuk data terhapus lunak): kamus hanya dipakai untuk MENYAMARKAN dan
 * tidak pernah dikirim ke LLM atau klien, jadi cakupan lebih luas = lebih banyak yang disamarkan (aman).
 * Dimuat malas sekali per request/job (binding scoped); bila pemuatan gagal, tersedia() = false dan pemanggil
 * wajib fail-closed (kembali ke penyamaran setiap kata berhuruf kapital).
 */
final class KamusNamaOrang
{
    /** Tabel => kolom yang berisi nama orang. */
    public const SUMBER = [
        'penerima_manfaats' => 'nama',
        'pengemudis' => 'nama',
        'titik_layanans' => 'pic_nama',
        'bukti_distribusis' => 'nama_pic',
        'users' => 'name',
    ];

    /** Label peran/jabatan/unit, kata data uji/demo, dan angka berupa kata yang lazim pada nama akun/PIC uji. */
    private const BUKAN_NAMA = [
        'uji', 'tes', 'test', 'testing', 'coba', 'demo', 'contoh', 'dummy', 'sampel', 'fiktif', 'staging', 'dev',
        'pic', 'admin', 'administrator', 'operator', 'pusat', 'cabang', 'kepala', 'wakil', 'ketua', 'sekretaris',
        'bendahara', 'koordinator', 'kordinator', 'manajer', 'staf', 'staff', 'pegawai', 'karyawan', 'gudang', 'relawan',
        'dapur', 'distribusi', 'produksi', 'logistik', 'keuangan', 'ahli', 'gizi', 'akuntan', 'investor', 'pengemudi',
        'sopir', 'supir', 'driver', 'petugas', 'pengawas', 'mitra', 'yayasan', 'unit', 'tim', 'bagian', 'divisi',
        'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
        'pertama', 'kedua', 'ketiga', 'utama', 'cadangan', 'baru', 'lama', 'sppg', 'mbg', 'bgn', 'penerima', 'manfaat',
    ];

    /** @var array<string, true>|null */
    private ?array $token = null;

    private bool $gagal = false;

    /** @param  Closure(): iterable<string|null>  $pemuat */
    private function __construct(private readonly Closure $pemuat) {}

    /** Kamus dari daftar nama tetap (tes, atau pemanggil yang sudah memegang daftar nama). */
    public static function dari(iterable $nama): self
    {
        return new self(fn () => $nama);
    }

    /** Kamus dari basis data (dipakai lewat container; lihat AppServiceProvider). */
    public static function dariBasisData(): self
    {
        return new self(function (): iterable {
            foreach (self::SUMBER as $tabel => $kolom) {
                foreach (DB::table($tabel)->whereNotNull($kolom)->distinct()->orderBy($kolom)->cursor() as $baris) {
                    yield $baris->{$kolom};
                }
            }
        });
    }

    /** False bila kamus gagal dimuat (pemanggil wajib fail-closed). */
    public function tersedia(): bool
    {
        $this->muat();

        return ! $this->gagal;
    }

    /** Apakah kata (tanpa membedakan huruf besar/kecil) adalah bagian dari nama orang terdaftar. */
    public function memuat(string $kata): bool
    {
        $this->muat();

        return isset($this->token[self::normal($kata)]);
    }

    public static function normal(string $kata): string
    {
        return mb_strtolower(str_replace(['\'', '’'], '', $kata));
    }

    /**
     * Token yang tidak dihitung sebagai nama: satu huruf (inisial), bukan huruf (angka), kata umum RecordPresenter,
     * serta label peran/jabatan/data uji pada nama akun (BUKAN_NAMA). Tanpa saringan ini nama akun seperti
     * "Relawan Dapur 1 (Demo)" atau PIC "PIC Satu (Uji)" membuat kata biasa ("Uji", "Dapur") ikut disingkat.
     */
    public static function bukanNama(string $kata): bool
    {
        $kata = self::normal($kata);

        return mb_strlen($kata) < 2
            || preg_match('/^\p{L}+$/u', $kata) !== 1
            || in_array($kata, self::BUKAN_NAMA, true)
            || RecordPresenter::kataUmum($kata);
    }

    private function muat(): void
    {
        if ($this->token !== null || $this->gagal) {
            return;
        }

        try {
            $token = [];
            foreach (($this->pemuat)() as $nama) {
                // Keterangan dalam kurung ("PIC Satu (Uji)", "Admin Pusat (Demo)") bukan bagian nama orang.
                $nama = preg_replace('/[(\[{][^)\]}]*[)\]}]?/u', ' ', (string) $nama) ?? '';
                foreach (preg_split('/[^\p{L}\p{N}\'’]+/u', $nama, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $kata) {
                    $kata = self::normal($kata);
                    if (self::bukanNama($kata)) {
                        continue;
                    }
                    $token[$kata] = true;
                }
            }
            $this->token = $token;
        } catch (Throwable $e) {
            $this->gagal = true;

            try {
                report($e);
            } catch (Throwable) {
                // Pelaporan gagal (mis. di luar aplikasi) tidak boleh menggagalkan penyamaran fail-closed.
            }
        }
    }
}

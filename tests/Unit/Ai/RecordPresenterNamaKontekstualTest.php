<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Catalog\KamusNamaOrang;
use App\Services\Ai\Catalog\RecordPresenter;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Issue #175 (temuan kasus 61): penyamaran nama ke inisial hanya untuk kolom data orang, nama orang terdaftar, dan
 * nama sesudah penanda orang; kata berhuruf kapital lain pada teks bebas tetap utuh. NIK/HP selalu tersamar.
 * Semua data contoh fiktif.
 */
class RecordPresenterNamaKontekstualTest extends TestCase
{
    private function kamus(string ...$nama): KamusNamaOrang
    {
        return KamusNamaOrang::dari($nama);
    }

    public function test_regresi_kasus_61_kata_kapital_utuh_dan_hp_tersamar(): void
    {
        $hasil = RecordPresenter::redactPersonal('Kemasan Uji Bocor, hubungi 081234567890', $this->kamus());

        $this->assertSame('Kemasan Uji Bocor, hubungi '.RecordPresenter::REDACTED, $hasil);
        $this->assertStringNotContainsString('0812', $hasil);
    }

    public function test_nik_hp_email_url_tetap_tersamar_di_teks_bebas(): void
    {
        $hasil = RecordPresenter::redactPersonal('Wadah Tumpah, NIK 3201012345678901, hp +62 812 3456 7890, ortu@contoh.id, https://contoh.id/x', $this->kamus());

        $this->assertSame('Wadah Tumpah, NIK [disamarkan], hp [disamarkan], [disamarkan], [disamarkan]', $hasil);
    }

    public function test_nama_menu_bahan_dan_lembaga_tidak_disingkat(): void
    {
        foreach (['Soto Ayam Lamongan kurang Garam', 'Kemasan Uji Bocor di SDN Fiktif 2', 'Tim Dapur Sentral sudah Cek Ulang'] as $teks) {
            $this->assertSame($teks, RecordPresenter::redactPersonal($teks, $this->kamus('Budi Santoso')), $teks);
        }
    }

    public function test_nama_sesudah_penanda_orang_menjadi_inisial(): void
    {
        $kamus = $this->kamus();

        $this->assertSame('Sudah dihubungi Bu S. D.', RecordPresenter::redactPersonal('Sudah dihubungi Bu Sartika Dewi', $kamus));
        $this->assertSame('hubungi Pak B. S. segera', RecordPresenter::redactPersonal('hubungi Pak Budi Santoso segera', $kamus));
        $this->assertSame('a.n. R. W., kontak B. S. [disamarkan]', RecordPresenter::redactPersonal('a.n. Rina Wati, kontak Budi Santoso 0812 3456 7890', $kamus));
        $this->assertSame('A. R. P. muntah', RecordPresenter::redactPersonal('Ananda Rizky Pratama muntah', $kamus));
        // Kata umum sesudah penanda menghentikan penyamaran.
        $this->assertSame('Ibu Kepala Sekolah melapor', RecordPresenter::redactPersonal('Ibu Kepala Sekolah melapor', $kamus));
    }

    public function test_nama_orang_terdaftar_menjadi_inisial_tanpa_penanda(): void
    {
        $kamus = $this->kamus('Siti Aminah', 'Budi Santoso');

        $this->assertSame('S. A. alergi telur, Kemasan Uji Bocor', RecordPresenter::redactPersonal('Siti Aminah alergi telur, Kemasan Uji Bocor', $kamus));
        $this->assertSame('B. tidak mau makan', RecordPresenter::redactPersonal('BUDI tidak mau makan', $kamus));
    }

    public function test_kamus_mengabaikan_kata_umum_label_peran_dan_isi_kurung(): void
    {
        $kamus = $this->kamus('PIC Satu (Uji)', 'PIC Dua (Uji)', 'Relawan Dapur 1 (Demo)', 'Staf Gudang 1 (Demo)', 'Admin Pusat (Demo)', 'Siti Rahma (Wali Kelas)');

        foreach (['uji', 'Demo', 'PIC', 'satu', 'dua', 'relawan', 'dapur', 'staf', 'gudang', 'admin', 'pusat', 'wali', 'kelas'] as $kata) {
            $this->assertFalse($kamus->memuat($kata), $kata);
        }
        $this->assertTrue($kamus->memuat('Siti'));
        $this->assertTrue($kamus->memuat('RAHMA'));
        $this->assertSame('Kemasan Uji Bocor, hubungi [disamarkan], S. R. pusing', RecordPresenter::redactPersonal('Kemasan Uji Bocor, hubungi 081234567890, Siti Rahma pusing', $kamus));
    }

    public function test_fail_closed_tanpa_kamus_atau_kamus_gagal_dimuat(): void
    {
        $gagal = KamusNamaOrang::dari((function () {
            throw new RuntimeException('basis data tidak tersedia');
            yield 'tidak pernah';
        })());

        $this->assertFalse($gagal->tersedia());
        // Di luar aplikasi (tanpa binding container) atau kamus gagal: setiap kata kapital non-umum menjadi inisial.
        $this->assertSame('K. U. B., hubungi [disamarkan]', RecordPresenter::redactPersonal('Kemasan Uji Bocor, hubungi 081234567890', $gagal));
        $this->assertSame('K. U. B., hubungi [disamarkan]', RecordPresenter::redactPersonal('Kemasan Uji Bocor, hubungi 081234567890'));
    }

    public function test_present_kolom_orang_inisial_dan_teks_bebas_hanya_kontak_disamarkan(): void
    {
        $model = new class extends Model {};
        $model->setRawAttributes([
            'nama' => 'Soto Ayam Lamongan',
            'pic_nama' => 'Budi Santoso',
            'nama_pic' => 'Rina Wati',
            'catatan' => 'Kemasan Uji Bocor, hubungi 081234567890',
        ]);

        // pic_nama/nama_pic tidak dideklarasikan di $initials: tetap inisial (fail-closed untuk kolom orang dikenal).
        $hasil = RecordPresenter::present($model, ['nama', 'pic_nama', 'nama_pic', 'catatan']);

        $this->assertSame('Soto Ayam Lamongan', $hasil['nama']);
        $this->assertSame('B.S.', $hasil['pic_nama']);
        $this->assertSame('R.W.', $hasil['nama_pic']);
        $this->assertSame('Kemasan Uji Bocor, hubungi '.RecordPresenter::REDACTED, $hasil['catatan']);

        // Kolom yang dideklarasikan sebagai nama orang tetap inisial.
        $this->assertSame('S.T.', RecordPresenter::present($model->setRawAttributes(['nama' => 'Soto Tegal']), ['nama'], initials: ['nama'])['nama']);
    }
}

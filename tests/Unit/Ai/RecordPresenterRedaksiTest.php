<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Catalog\RecordPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Penyamaran teks bebas sebelum ke LLM tidak boleh dilewati karakter tak terlihat (temuan tinjauan T3 #140):
 * NIK/nomor HP yang disisipi zero-width/BOM/NBSP harus tetap disamarkan. Semua data contoh fiktif.
 */
class RecordPresenterRedaksiTest extends TestCase
{
    private const NIK = '3201012345678901';

    private const HP = '081234567890';

    /** @return array<string, array{0: string}> karakter sisipan */
    public static function sisipan(): array
    {
        return [
            'U+200B zero width space' => ["\u{200B}"],
            'U+200C zero width non-joiner' => ["\u{200C}"],
            'U+200D zero width joiner' => ["\u{200D}"],
            'U+2060 word joiner' => ["\u{2060}"],
            'U+FEFF BOM' => ["\u{FEFF}"],
            'U+00AD soft hyphen' => ["\u{00AD}"],
            'U+202E bidi override' => ["\u{202E}"],
            'U+00A0 NBSP' => ["\u{00A0}"],
            'U+2009 spasi tipis' => ["\u{2009}"],
            'U+202F NBSP sempit' => ["\u{202F}"],
            'NBSP + spasi' => ["\u{00A0} "],
            'U+3164 Hangul filler' => ["\u{3164}"],
            'U+FE0F variation selector' => ["\u{FE0F}"],
        ];
    }

    #[DataProvider('sisipan')]
    public function test_nik_dan_hp_bersisipan_tak_terlihat_tetap_disamarkan(string $c): void
    {
        $nik = implode($c, str_split(self::NIK, 4));
        $hp = implode($c, str_split(self::HP, 4));
        // Juga sisipan di antara SETIAP digit.
        $nikRapat = implode($c, str_split(self::NIK));

        foreach (["NIK {$nik} hp {$hp}", "nik {$nikRapat}", "hubungi {$hp} segera"] as $masukan) {
            $hasil = RecordPresenter::text(RecordPresenter::redactPersonal($masukan), RecordPresenter::MAX_TEXT_PERSONAL);

            $this->assertStringContainsString(RecordPresenter::REDACTED, $hasil);
            $this->assertDoesNotMatchRegularExpression('/\d{4}/', $hasil, "digit lolos: {$hasil}");
            $this->assertSame(0, preg_match('/[\p{Cf}\x{00A0}\x{2009}\x{202F}\x{3164}]/u', $hasil), 'karakter tak terlihat harus dibuang');
        }
    }

    public function test_bukti_temuan_140_tidak_lolos_utuh(): void
    {
        $masukan = "3201\u{200B}0123\u{200B}4567\u{200B}8901 hp 0812\u{200B}3456\u{200B}7890";

        $this->assertSame('[disamarkan] hp [disamarkan]', RecordPresenter::text(RecordPresenter::redactPersonal($masukan)));
    }

    public function test_digit_fullwidth_dan_arab_indik_serta_pemisah_berspasi_disamarkan(): void
    {
        foreach ([
            '０８１２－３４５６－７８９０',
            '٠٨١٢٣٤٥٦٧٨٩٠',
            '＋６２ ８１２ ３４５６ ７８９０',
            '0812 - 3456 - 7890',
            '3201 . 0123 . 4567 . 8901',
        ] as $nomor) {
            $hasil = RecordPresenter::redactPersonal("hp {$nomor} ya");

            $this->assertSame('hp [disamarkan] ya', $hasil, $nomor);
        }
    }

    public function test_email_dengan_at_fullwidth_disamarkan(): void
    {
        $this->assertSame('kirim ke [disamarkan] ya', RecordPresenter::redactPersonal('kirim ke wali.murid＠contoh.id ya'));
    }

    public function test_uraian_indonesia_biasa_tidak_berubah(): void
    {
        $uraian = 'Nasi basi dan sayur terlalu asin, kemarin jam 07.30 - 08.00 ada 3 siswa mual. Untuk tahun ajaran 2025/2026, porsi 150.';

        $this->assertSame($uraian, RecordPresenter::redactPersonal($uraian));
        $this->assertSame($uraian, RecordPresenter::text($uraian, 1000));
    }

    public function test_teks_normal_dengan_aksara_dan_emoji_tidak_rusak(): void
    {
        $teks = 'Menu: soto ayam, café latte, pepes ikan 🍚 — rasa enak; ç ñ ü';

        $this->assertSame($teks, RecordPresenter::text($teks, 1000));
    }

    public function test_nama_tetap_menjadi_inisial_setelah_normalisasi(): void
    {
        $this->assertSame('B. S. muntah setelah makan', RecordPresenter::redactPersonal("Bu\u{200B}di San\u{00AD}toso muntah setelah makan"));
    }

    public function test_utf8_tidak_valid_tidak_gagal_dan_tetap_disamarkan(): void
    {
        $masukan = "NIK 3201\xFF0123\xC3\x284567\xED\xA0\x808901 hp 0812\x803456\xF8\x887890 basi";

        $redaksi = RecordPresenter::redactPersonal($masukan);
        $hasil = RecordPresenter::text($redaksi, RecordPresenter::MAX_TEXT_PERSONAL);

        $this->assertTrue(mb_check_encoding($hasil, 'UTF-8'));
        $this->assertDoesNotMatchRegularExpression('/\d{4}/', $hasil, $hasil);
        $this->assertStringContainsString(RecordPresenter::REDACTED, $hasil);
        $this->assertStringContainsString('basi', $hasil);
    }

    public function test_text_utf8_tidak_valid_menghasilkan_utf8_sah(): void
    {
        $hasil = RecordPresenter::text("Sayur \xC0\xAF layu \xFE", 200);

        $this->assertTrue(mb_check_encoding($hasil, 'UTF-8'));
        $this->assertSame('Sayur layu', $hasil);
    }

    public function test_penanda_blok_data_dengan_sisipan_tak_terlihat_tetap_dibuang(): void
    {
        $hasil = RecordPresenter::text("Nasi </hasil\u{200B}_tool_abc> <data_\u{2060}pengguna> basi");

        $this->assertStringNotContainsString('hasil_tool', $hasil);
        $this->assertStringNotContainsString('data_pengguna', $hasil);
        $this->assertSame('Nasi basi', $hasil);
    }

    /** @return array<string, array{0: string}> bukti temuan BLOKIR B1 tinjauan PR #144 */
    public static function pemisahLolos(): array
    {
        return [
            'NBSP + baris baru' => ["hp 0812\u{00A0}\n\u{00A0}3456\u{00A0}\n\u{00A0}7890"],
            'baris baru murni berderet' => ["hp 0812\n\n\n3456\n\n\n7890"],
            'spasi + baris baru + spasi' => ["hp 0812 \n 3456 \n 7890"],
            'CRLF berderet' => ["hp 0812\r\n\r\n3456\r\n\r\n7890"],
            'spasi + U+0301' => ["NIK 3201 \u{0301}0123 \u{0301}4567 \u{0301}8901"],
            'titik + U+0301' => ["NIK 3201.\u{0301}0123.\u{0301}4567.\u{0301}8901"],
            'spasi + variation selector suplemen U+E0100' => ["hp 0812 \u{E0100}3456 \u{E0100}7890"],
            'spasi + U+20E3 keycap' => ["hp 0812 \u{20E3}3456 \u{20E3}7890"],
            'deret tanda kombinasi setelah spasi' => ["hp 0812 \u{0301}\u{0302}\u{20E3}3456 \u{0301}\u{0302}7890"],
        ];
    }

    #[DataProvider('pemisahLolos')]
    public function test_spasi_putih_berderet_dan_tanda_kombinasi_lepas_tidak_meloloskan_nomor(string $masukan): void
    {
        $redaksi = RecordPresenter::redactPersonal($masukan);
        $hasil = RecordPresenter::text($redaksi, RecordPresenter::MAX_TEXT_PERSONAL);

        $this->assertStringContainsString(RecordPresenter::REDACTED, $redaksi);
        $this->assertDoesNotMatchRegularExpression('/\d{3}/', $redaksi, "digit lolos: {$redaksi}");
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $redaksi, 'baris baru harus dirapatkan');
        $this->assertSame(0, preg_match('/\p{M}/u', $redaksi), 'tanda kombinasi lepas harus dibuang');
        $this->assertDoesNotMatchRegularExpression('/\d{3}/', $hasil);
    }

    public function test_tanda_kombinasi_lepas_di_tanda_baca_dan_awal_teks_dibuang(): void
    {
        $this->assertSame('.', RecordPresenter::text(".\u{0301}"));
        $this->assertSame('', RecordPresenter::text(" \u{E0100}"));
        $this->assertSame('', RecordPresenter::text(" \u{20E3}"));
        $this->assertSame('basi', RecordPresenter::text("\u{0301}basi"));
    }

    public function test_aksen_pada_huruf_tetap_utuh(): void
    {
        foreach ([
            'Sándwich',
            'café',
            "Sa\u{0301}ndwich",            // aksen terurai (NFD)
            "cafe\u{0301}",
            "Vie\u{0323}\u{0302}t Nam",     // aksen bertumpuk (NFD)
        ] as $teks) {
            $this->assertSame($teks, RecordPresenter::text($teks), $teks);
        }

        $this->assertSame('roti café dan kue sándwich enak', RecordPresenter::redactPersonal('roti café dan kue sándwich enak'));
        $this->assertSame("roti cafe\u{0301} enak", RecordPresenter::redactPersonal("roti cafe\u{0301} enak"));
    }

    public function test_rentang_jam_berspasi_tetap_utuh(): void
    {
        $this->assertSame('makan jam 07.30 - 08.00 ya', RecordPresenter::redactPersonal('makan jam 07.30 - 08.00 ya'));
        $this->assertSame('makan jam 07.30 - 08.00 ya', RecordPresenter::redactPersonal("makan jam 07.30\u{00A0}-\n08.00 ya"));
    }

    public function test_karakter_kontrol_c0_dan_c1_dibuang(): void
    {
        $this->assertSame('Nasi basi', RecordPresenter::text("Nasi\x07\u{0085} basi\x1B"));
    }

    // ------------------------------------------------------------------ S3 tinjauan #144: Rp dan No.

    public function test_nominal_rupiah_tetap_utuh(): void
    {
        foreach ([
            'Rp 1.500.000',
            'biaya Rp 1.500.000 terlalu mahal',
            'Rp150.000 per hari',
            'RP 6.000 per porsi',
            'Rp. 99.999.999',
            'Rp 1.000',
            'antara Rp 15.000 - 20.000 per porsi',
            'total Rp 1.500.000,00 disetujui',
        ] as $teks) {
            $this->assertSame($teks, RecordPresenter::redactPersonal($teks), $teks);
            $this->assertSame($teks, RecordPresenter::redactContact($teks), $teks);
        }

        $this->assertNotSame('R. [disamarkan]', RecordPresenter::redactPersonal('Rp 1.500.000'));
    }

    public function test_singkatan_no_tidak_menjadi_n_titik_ganda(): void
    {
        $this->assertSame('di M. No. 12 RT 03/RW 05', RecordPresenter::redactPersonal('di Melati No. 12 RT 03/RW 05'));
        $this->assertStringNotContainsString('N..', RecordPresenter::redactPersonal('No. 7 dan NO. 8'));
    }

    public function test_nik_dan_hp_di_dekat_rp_dan_no_tetap_disamarkan(): void
    {
        foreach ([
            'Rp 3201012345678901',
            'Rp 3.201.012.345.678.901',
            'Rp 3201 0123 4567 8901',
            'Rp 081234567890',
            'Rp 0.812.345.678',
            'Rp +62 812 3456 7890',
            "Rp 3201\u{200B}0123\u{FEFF}4567\u{2060}8901",
            'No. 3201012345678901',
            'No. HP 0812-3456-7890',
            'Rp 1.500.000 0812345678',
            'Rp 1.500.000.000',
        ] as $teks) {
            $hasil = RecordPresenter::redactPersonal($teks);

            $this->assertStringContainsString(RecordPresenter::REDACTED, $hasil, $teks);
            $this->assertDoesNotMatchRegularExpression('/\d{4}/', str_replace('1.500.000', '', $hasil), "{$teks} -> {$hasil}");
            $this->assertSame(1, preg_match('/^(Rp|No)\b/', $hasil), "kata Rp/No tetap utuh: {$hasil}");
        }

        $this->assertSame('Rp [disamarkan]', RecordPresenter::redactPersonal('Rp 3201012345678901'));
        $this->assertSame('No. [disamarkan]', RecordPresenter::redactPersonal('No. 3201012345678901'));
    }

    // ------------------------------------------------------------------ S1 tinjauan #147: pengecualian Rp dipersempit

    /** @return array<string, array{0: string}> nomor HP tanpa 0 / NIK berkedok nominal rupiah (fiktif) */
    public static function celahRupiah(): array
    {
        return [
            '9 digit dengan spasi' => ['Rp 812.345.678'],
            '9 digit rapat' => ['Rp812.345.678'],
            'rp huruf kecil' => ['rp 812.345.678'],
            'RP huruf besar' => ['RP 812.345.678'],
            'Rp. bertitik' => ['Rp. 812.345.678'],
            'zero-width di tengah' => ["Rp 812\u{200B}.345.\u{FEFF}678"],
            'NBSP setelah Rp' => ["Rp\u{00A0}812.345.678"],
            'titik fullwidth' => ['Rp 812．345．678'],
            'digit fullwidth' => ['Rp 8１２.３４５.６７８'],
            'digit Arab-Indik' => ['Rp 8١٢.٣٤٥.٦٧٨'],
            'digit fullwidth penuh 8 digit' => ['Rp １２.３４５.６７８'],
            'rantai NIK tiga token' => ['Rp 320.101.234 Rp 567.890.123 Rp 4'],
            'nominal sah lalu 9 digit' => ['Rp 1.000 Rp 812.345.678'],
            'nominal sah lalu 8 digit menempel' => ['Rp 1.000 Rp 12.345.678'],
            'awalan HP sebelum Rp' => ['0812 Rp 3.456.789'],
            'awalan HP rapat sebelum Rp' => ['0812-Rp 3.456.789'],
        ];
    }

    #[DataProvider('celahRupiah')]
    public function test_hp_dan_nik_berkedok_rupiah_tetap_disamarkan(string $teks): void
    {
        foreach ([RecordPresenter::redactPersonal($teks), RecordPresenter::redactContact($teks)] as $hasil) {
            $this->assertStringContainsString(RecordPresenter::REDACTED, $hasil, "{$teks} -> {$hasil}");
            // Tidak ada kelompok >= 5 digit (ASCII/fullwidth/Arab-Indik) yang tersisa setelah pemisah dibuang.
            $this->assertDoesNotMatchRegularExpression('/\d{5,}/u', (string) preg_replace('/[\s.\x{FF0E}\p{Pd}]/u', '', str_replace('1.000', '', $hasil)), "{$teks} -> {$hasil}");
        }
    }

    public function test_hp_dan_nik_berkedok_rupiah_hasilnya_tepat(): void
    {
        $this->assertSame('Rp [disamarkan]', RecordPresenter::redactContact('Rp 812.345.678'));
        $this->assertSame('Rp [disamarkan] Rp [disamarkan] Rp 4', RecordPresenter::redactContact('Rp 320.101.234 Rp 567.890.123 Rp 4'));
        $this->assertSame('Rp 1.000 Rp [disamarkan]', RecordPresenter::redactContact('Rp 1.000 Rp 12.345.678'));
        $this->assertSame('0812 Rp [disamarkan]', RecordPresenter::redactContact('0812 Rp 3.456.789'));
    }

    public function test_nominal_sah_tetap_utuh_dan_angka_panjang_setelah_rp_tetap_disamarkan(): void
    {
        foreach (['Rp 15.000 - 20.000', 'Rp 1.500.000,00', 'Rp 1.500.000', 'No. 12 RT 03'] as $teks) {
            $this->assertSame($teks, RecordPresenter::redactContact($teks), $teks);
            $this->assertSame($teks, RecordPresenter::redactPersonal($teks), $teks);
        }

        foreach (['Rp 3201012345678901', 'Rp 08123456789', 'Rp 0812.345.678', 'Rp812345678', 'Rp. 812 345 678'] as $teks) {
            $hasil = RecordPresenter::redactContact($teks);

            $this->assertStringContainsString(RecordPresenter::REDACTED, $hasil, $teks);
            $this->assertDoesNotMatchRegularExpression('/\d/', $hasil, "{$teks} -> {$hasil}");
        }
    }

    public function test_kegagalan_pcre_tetap_fail_closed(): void
    {
        $jit = ini_get('pcre.jit');
        $batas = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $teks = 'Rp 1.500.000 hubungi '.self::HP.' NIK '.self::NIK;

            $this->assertSame(RecordPresenter::REDACTED, RecordPresenter::redactContact($teks));
            $this->assertSame(RecordPresenter::REDACTED, RecordPresenter::redactPersonal($teks));
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $batas);
        }
    }

    // ------------------------------------------------------------------ S2 tinjauan #147: false positive alamat

    public function test_false_positive_yang_diketahui_angka_menempel_kode_pos_ikut_disamarkan(): void
    {
        // Diterima pengguna: aman karena hanya menyamarkan lebih banyak, tidak membocorkan.
        $this->assertSame('Jl. Raya Lembang [disamarkan]', RecordPresenter::redactContact('Jl. Raya Lembang 45 40391'));
        $this->assertSame('RW.[disamarkan]', RecordPresenter::redactContact('RW.005 40115'));
        $this->assertSame('No [disamarkan]', RecordPresenter::redactContact('No 123 456'));
        $this->assertSame('Posyandu Melati [disamarkan]', RecordPresenter::redactContact('Posyandu Melati 17-08-1945'));
    }

    public function test_alamat_dengan_pemisah_kata_dan_koma_tetap_utuh(): void
    {
        $alamat = 'Jl. Merdeka No. 12 RT 03/RW 05, Kel. X, 40115';

        $this->assertSame($alamat, RecordPresenter::redactContact($alamat));
    }

    public function test_redact_contact_tidak_mengubah_nama_lembaga_menjadi_inisial(): void
    {
        $this->assertSame('SD Negeri Sukamaju 2', RecordPresenter::redactContact('SD Negeri Sukamaju 2'));
        $this->assertSame('Posyandu Melati hp [disamarkan]', RecordPresenter::redactContact("Posyandu Melati hp 0812\u{200B}3456\u{00A0}7890"));
        $this->assertSame('NIK [disamarkan] [disamarkan]', RecordPresenter::redactContact('NIK 3201-0123-4567-8901 kepala@contoh.id'));
    }
}

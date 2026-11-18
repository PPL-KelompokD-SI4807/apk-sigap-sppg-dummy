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
}

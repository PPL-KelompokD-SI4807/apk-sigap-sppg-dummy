<?php

namespace Tests\Unit\Telegram;

use App\Services\Telegram\Bot\BotKodeCodec;
use App\Services\Telegram\Bot\BotNavCodec;
use LogicException;
use Tests\TestCase;

/** Token navigasi baca `n1` (R02-A1): HMAC terikat telegram_id, TTL, < 64 byte, gagal seragam (null). */
class BotNavCodecTest extends TestCase
{
    private const UID = 700123456;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'telegram.nav_ttl_menit' => 30]);
    }

    public function test_bolak_balik_dan_dapat_dipakai_ulang(): void
    {
        $data = BotNavCodec::data('ku', 42, self::UID);

        for ($i = 0; $i < 3; $i++) {
            $t = BotNavCodec::urai($data, self::UID);
            $this->assertNotNull($t);
            $this->assertSame('ku', $t->layar);
            $this->assertSame(42, $t->sppgId);
            $this->assertFalse($t->kedaluwarsa);
        }

        $tanpa = BotNavCodec::urai(BotNavCodec::data('h', null, self::UID), self::UID);
        $this->assertNull($tanpa->sppgId);
        $this->assertTrue(BotNavCodec::cocokPrefiks($data));
        $this->assertFalse(BotNavCodec::cocokPrefiks('b1.abc.def'));
    }

    public function test_panjang_maksimum_di_bawah_64_byte(): void
    {
        $data = BotNavCodec::data('abcdefgh', 99_999_999_999_999, PHP_INT_MAX);
        $this->assertLessThanOrEqual(64, strlen($data));
        $this->assertNotNull(BotNavCodec::urai($data, PHP_INT_MAX));
        $this->assertLessThanOrEqual(30, strlen(BotNavCodec::data('jd', 12, self::UID)));
    }

    public function test_terikat_telegram_id(): void
    {
        $data = BotNavCodec::data('ku', 42, self::UID);

        $this->assertNull(BotNavCodec::urai($data, self::UID + 1));
    }

    public function test_setiap_karakter_diubah_ditolak(): void
    {
        $data = BotNavCodec::data('ku', 42, self::UID);

        for ($i = 3; $i < strlen($data); $i++) {
            if ($data[$i] === '.') {
                continue;
            }

            foreach (['a', '1', 'z', 'Z', '_'] as $ganti) {
                if ($ganti === $data[$i]) {
                    continue;
                }

                $palsu = substr_replace($data, $ganti, $i, 1);
                $this->assertNull(BotNavCodec::urai($palsu, self::UID), "posisi {$i} -> {$ganti}");
            }
        }
    }

    public function test_sppg_diganti_dengan_mac_lama_ditolak(): void
    {
        [$p, $layar, , $exp, $mac] = explode('.', BotNavCodec::data('ku', 42, self::UID));

        foreach (['0', '1', '17', 'zz'] as $sppg) {
            $this->assertNull(BotNavCodec::urai("{$p}.{$layar}.{$sppg}.{$exp}.{$mac}", self::UID));
        }

        $this->assertNull(BotNavCodec::urai("{$p}.jd.16.{$exp}.{$mac}", self::UID), 'layar lain dengan MAC lama');
    }

    public function test_kedaluwarsa_ditandai_dan_exp_terlalu_jauh_ditolak(): void
    {
        $lalu = time() - 31 * 60;
        $t = BotNavCodec::urai(BotNavCodec::data('ku', 1, self::UID, $lalu), self::UID);
        $this->assertNotNull($t);
        $this->assertTrue($t->kedaluwarsa);

        // Diterbitkan "di masa depan" melebihi TTL + toleransi: ditolak (tidak sah, bukan sekadar kedaluwarsa).
        $this->assertNull(BotNavCodec::urai(BotNavCodec::data('ku', 1, self::UID, time() + 3600), self::UID));
    }

    public function test_format_tidak_sah_ditolak(): void
    {
        $sah = BotNavCodec::data('ku', 42, self::UID);
        [, $layar, $sppg, $exp, $mac] = explode('.', $sah);

        foreach ([
            null, 123, [], '', 'n1', 'n1....', $sah.'.x', 'N1'.substr($sah, 2), "n1.{$layar}.0{$sppg}.{$exp}.{$mac}",
            "n1.KU.{$sppg}.{$exp}.{$mac}", "n1.{$layar}.{$sppg}.{$exp}.{$mac}=", str_pad($sah, 80, 'a'), "n1.{$layar}.{$sppg}.{$exp}.{$mac}\n",
        ] as $data) {
            $this->assertNull(BotNavCodec::urai($data, self::UID), var_export($data, true));
        }
    }

    public function test_kunci_berbeda_dari_token_aksi_b1(): void
    {
        $data = BotNavCodec::data('ku', 42, self::UID);

        $this->assertNull(BotKodeCodec::urai($data, self::UID));
        $this->assertNull(BotNavCodec::urai(BotKodeCodec::data(BotKodeCodec::kodeBaru(), self::UID), self::UID));

        // Mengganti app.key membatalkan semua token navigasi.
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $this->assertNull(BotNavCodec::urai($data, self::UID));
    }

    public function test_id_layar_atau_sppg_tidak_sah_tidak_dapat_dibentuk(): void
    {
        foreach (['', 'Ku', '1a', 'abcdefghi', 'k.u'] as $layar) {
            try {
                BotNavCodec::data($layar, 1, self::UID);
                $this->fail("Layar {$layar} seharusnya ditolak");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        BotNavCodec::data('ku', 0, self::UID);
    }
}

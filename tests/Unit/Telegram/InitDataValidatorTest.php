<?php

namespace Tests\Unit\Telegram;

use App\Services\Telegram\InitDataValidator;
use App\Services\Telegram\InvalidInitDataException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsTelegramInitData;
use Tests\TestCase;

class InitDataValidatorTest extends TestCase
{
    use BuildsTelegramInitData;

    private function validator(?string $token = null, int $maxAge = 86400): InitDataValidator
    {
        return new InitDataValidator($token ?? (string) config('telegram.bot_token'), $maxAge, 60);
    }

    public function test_initdata_sah_diterima_dan_mengembalikan_user(): void
    {
        $initData = $this->makeInitData(['id' => 7012345678, 'first_name' => 'Budi Ñandú']);

        $result = $this->validator()->validate($initData);

        $this->assertSame(7012345678, $result['user']['id']);
        $this->assertSame('Budi Ñandú', $result['user']['first_name']);
    }

    public function test_data_check_string_sesuai_spesifikasi_telegram(): void
    {
        // Perhitungan independen: secret = HMAC_SHA256(key="WebAppData", msg=bot_token).
        $token = '123:ABC';
        $fields = ['user' => '{"id":1}', 'auth_date' => '1700000000', 'query_id' => 'Q'];
        $expected = hash_hmac(
            'sha256',
            "auth_date=1700000000\nquery_id=Q\nuser={\"id\":1}",
            hash_hmac('sha256', $token, 'WebAppData', true),
        );

        $this->assertSame($expected, InitDataValidator::sign($fields, $token));
    }

    public function test_nilai_dengan_plus_sebagai_spasi_dan_percent_encoding_diterima(): void
    {
        $fields = $this->initDataFields(['id' => 42, 'first_name' => 'Ani Lestari']);
        $initData = str_replace('%20', '+', $this->encodeInitData($fields));

        $this->assertSame('Ani Lestari', $this->validator()->validate($initData)['user']['first_name']);
    }

    public function test_mengubah_satu_field_membuat_hash_tidak_cocok(): void
    {
        $fields = $this->initDataFields(['id' => 42]);
        $fields['user'] = str_replace('"id":42', '"id":43', $fields['user']);

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($this->encodeInitData($fields));
    }

    public function test_menambah_field_baru_ditolak(): void
    {
        $fields = $this->initDataFields(['id' => 42]);
        $fields['start_param'] = 'admin';

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($this->encodeInitData($fields));
    }

    #[DataProvider('hashPalsu')]
    public function test_hash_palsu_ditolak(string $hash): void
    {
        $fields = $this->initDataFields(['id' => 42]);
        $fields['hash'] = $hash;

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($this->encodeInitData($fields));
    }

    /** @return array<string, array{string}> */
    public static function hashPalsu(): array
    {
        return [
            'nol' => [str_repeat('0', 64)],
            'kosong' => [''],
            'huruf besar' => [str_repeat('A', 64)],
            'terlalu pendek' => ['abc123'],
        ];
    }

    public function test_hash_dengan_token_bot_lain_ditolak(): void
    {
        $fields = $this->initDataFields(['id' => 42], null, [], '999:TOKEN-BOT-LAIN');

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($this->encodeInitData($fields));
    }

    public function test_tanpa_hash_ditolak(): void
    {
        $fields = $this->initDataFields(['id' => 42]);
        unset($fields['hash']);

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($this->encodeInitData($fields));
    }

    public function test_initdata_kedaluwarsa_ditolak(): void
    {
        $initData = $this->makeInitData(['id' => 42], time() - 3601);

        $this->expectExceptionMessage('kedaluwarsa');
        $this->validator(maxAge: 3600)->validate($initData);
    }

    public function test_initdata_tepat_di_batas_umur_diterima(): void
    {
        $now = 1_800_000_000;
        $initData = $this->makeInitData(['id' => 42], $now - 3600);

        $this->assertSame(42, $this->validator(maxAge: 3600)->validate($initData, $now)['user']['id']);
    }

    public function test_auth_date_masa_depan_lebih_dari_60_detik_ditolak(): void
    {
        $initData = $this->makeInitData(['id' => 42], time() + 120);

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($initData);
    }

    public function test_auth_date_masa_depan_dalam_toleransi_diterima(): void
    {
        $now = 1_800_000_000;
        $initData = $this->makeInitData(['id' => 42], $now + 30);

        $this->assertSame(42, $this->validator()->validate($initData, $now)['user']['id']);
    }

    public function test_auth_date_bukan_angka_ditolak(): void
    {
        $fields = $this->initDataFields(['id' => 42], null, ['auth_date' => '1e9']);

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($this->encodeInitData($fields));
    }

    #[DataProvider('userTidakValid')]
    public function test_user_json_tidak_valid_ditolak(string $userJson): void
    {
        $fields = $this->initDataFields(['id' => 1], null, ['user' => $userJson]);

        $this->expectExceptionMessage('Data pengguna Telegram tidak valid.');
        $this->validator()->validate($this->encodeInitData($fields));
    }

    /** @return array<string, array{string}> */
    public static function userTidakValid(): array
    {
        return [
            'bukan json' => ['{bukan-json'],
            'tanpa id' => ['{"first_name":"A"}'],
            'id string' => ['{"id":"42"}'],
            'id negatif' => ['{"id":-5}'],
            'array kosong' => ['[]'],
        ];
    }

    public function test_tanpa_field_user_ditolak(): void
    {
        $fields = ['auth_date' => (string) time(), 'query_id' => 'Q'];
        $fields['hash'] = InitDataValidator::sign($fields, (string) config('telegram.bot_token'));

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($this->encodeInitData($fields));
    }

    public function test_key_ganda_ditolak(): void
    {
        $initData = $this->makeInitData(['id' => 42]).'&user='.rawurlencode('{"id":1}');

        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate($initData);
    }

    public function test_token_bot_kosong_selalu_menolak_meski_hash_dihitung_dengan_kunci_kosong(): void
    {
        $fields = $this->initDataFields(['id' => 42], null, [], '');

        $this->expectExceptionMessage('belum dikonfigurasi');
        $this->validator('')->validate($this->encodeInitData($fields));
    }

    public function test_initdata_terlalu_panjang_ditolak(): void
    {
        $this->expectException(InvalidInitDataException::class);
        $this->validator()->validate(str_repeat('a', InitDataValidator::MAX_LENGTH + 1));
    }
}

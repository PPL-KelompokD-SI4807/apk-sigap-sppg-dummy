<?php

namespace App\Services\Users;

use RuntimeException;

/**
 * Undangan tidak dapat dipakai. `reason` stabil untuk logika bot; pesan aman ditampilkan ke pengguna.
 */
class InvalidInvitationException extends RuntimeException
{
    public const TIDAK_VALID = 'tidak_valid';

    public const DICABUT = 'dicabut';

    public const TERPAKAI = 'terpakai';

    public const KEDALUWARSA = 'kedaluwarsa';

    public const TELEGRAM_SUDAH_TERDAFTAR = 'telegram_sudah_terdaftar';

    public const AKUN_SUDAH_TERHUBUNG = 'akun_sudah_terhubung';

    public const TIPE_TIDAK_SESUAI = 'tipe_tidak_sesuai';

    public const DATA_TELEGRAM_TIDAK_VALID = 'data_telegram_tidak_valid';

    private const MESSAGES = [
        self::TIDAK_VALID => 'Tautan undangan tidak valid.',
        self::DICABUT => 'Tautan undangan telah dicabut. Minta tautan baru kepada Admin.',
        self::TERPAKAI => 'Tautan undangan sudah pernah dipakai.',
        self::KEDALUWARSA => 'Tautan undangan sudah kedaluwarsa. Minta tautan baru kepada Admin.',
        self::TELEGRAM_SUDAH_TERDAFTAR => 'Akun Telegram ini sudah terdaftar di SiGAP SPPG.',
        self::AKUN_SUDAH_TERHUBUNG => 'Akun SiGAP SPPG ini sudah terhubung dengan Telegram.',
        self::TIPE_TIDAK_SESUAI => 'Tautan undangan ini bukan untuk jenis pendaftaran tersebut.',
        self::DATA_TELEGRAM_TIDAK_VALID => 'Data akun Telegram tidak valid.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES[self::TIDAK_VALID]);
    }
}

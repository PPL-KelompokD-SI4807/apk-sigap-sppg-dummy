<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Status satu tombol aksi Bot Telegram (R-02). Makna aksi (jenis, subjek, SPPG, versi yang dilihat
 * pengguna) HANYA ada di baris ini; callback_data hanya membawa kode acak ber-HMAC (BotKodeCodec).
 * Dibuat/diubah hanya lewat BotAksiService ($fillable kosong). Tanpa data pribadi atau isi catatan.
 *
 * @property int $id
 * @property string $kode
 * @property int $user_id
 * @property int $telegram_id
 * @property int $chat_id
 * @property int|null $sppg_id
 * @property string $jenis
 * @property string|null $subjek_tipe
 * @property int|null $subjek_id
 * @property int|null $subjek_versi
 * @property string $status
 */
class BotAksi extends Model
{
    public const JENIS_PILIH_SPPG = 'pilih_sppg';

    public const JENIS_DAFTAR = 'daftar_persetujuan';

    public const JENIS_TINJAU = 'tinjau';

    public const JENIS_SETUJU = 'setuju';

    public const JENIS_TOLAK = 'tolak';

    public const SUBJEK_MENU = 'pengajuan_menu';

    public const SUBJEK_PENGELUARAN = 'pengeluaran';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_DIPAKAI = 'dipakai';

    public const STATUS_MENUNGGU_CATATAN = 'menunggu_catatan';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_KEDALUWARSA = 'kedaluwarsa';

    protected $table = 'bot_aksi';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'telegram_id' => 'integer',
            'chat_id' => 'integer',
            'sppg_id' => 'integer',
            'subjek_id' => 'integer',
            'subjek_versi' => 'integer',
            'prompt_message_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'catatan_expires_at' => 'datetime',
        ];
    }
}

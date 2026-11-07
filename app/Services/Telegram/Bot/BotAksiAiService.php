<?php

namespace App\Services\Telegram\Bot;

use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\BotAksi;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Eksekusi\AiDraftExecutor;
use App\Services\Ai\Eksekusi\HasilEksekusi;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Referensi\HasilPilihan;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use App\Services\Ai\Referensi\ResolverReferensi;
use App\Services\Ai\Tools\WriteDraftTool;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramUpdateHandler;
use Carbon\Carbon;
use Throwable;

/**
 * Layar konfirmasi draf Agen AI di chat Bot (Tahap 1) untuk SEMUA peran: Lanjutkan / Batal / Ubah, dan "Ya, yakin" sebagai
 * langkah kedua aksi berbahaya. Tombol `b1` sekali pakai (BotAksiService: MAC ke telegram_id, satu grup per layar,
 * konsumsi atomik), berlaku sampai draf kedaluwarsa (ai.drafts.ttl_minutes). Makna tombol hanya ada di baris bot_aksi
 * (subjek = ai_draft id); hak, sesi, status, dan isi draf SELALU dihitung ulang server (AiDraftExecutor).
 *
 * - Ringkasan DIBANGUN SERVER dari payload draf tersimpan + nama target dari basis data (WriteDraftTool::ringkasan),
 *   di-escape HTML; jawaban teks LLM tidak pernah menjadi isi layar ini. Transkrip suara ("Yang saya dengar") hanya
 *   ditampilkan (escape + dipotong), tidak disimpan.
 * - Lanjutkan hanya tampil bila draf dapat dijalankan dari chat (rute REST, tanpa berkas, isian lolos Form Request dan
 *   tidak berkeyakinan rendah); selain itu hanya Ubah (Mini App ?draft=) + Batal.
 * - Balasan teks/suara "ya/oke/lanjut" tidak pernah menjalankan apa pun: hanya callback tombol sah.
 * - Rujukan nama ambigu (mis. dua bahan "Ayam"): layar PILIHAN `ai_pilih` lebih dulu; memilih hanya mengubah payload
 *   draf (PilihanReferensiDraf), lalu layar konfirmasi/pratinjau biasa.
 */
class BotAksiAiService
{
    public const LABEL_LANJUT = 'Lanjutkan';

    public const LABEL_YAKIN = 'Ya, yakin';

    public const LABEL_BATAL = 'Batal';

    public const LABEL_UBAH = 'Ubah';

    public const PESAN_BERUBAH = 'Data berubah sejak draf dibuat. Tidak ada perubahan yang dilakukan. Silakan ulangi permintaan Anda.';

    public const PESAN_TIDAK_BERLAKU = 'Draf ini sudah tidak berlaku (kedaluwarsa, sudah dijalankan, atau dibatalkan). Tidak ada perubahan data. Kirim ulang permintaan Anda bila perlu.';

    public const PESAN_DITOLAK = 'Anda tidak berhak melakukan aksi ini. Tidak ada perubahan data.';

    public const MAKS_TRANSKRIP = 300;

    public const LABEL_PERIKSA = 'Periksa & Simpan';

    public function __construct(
        private readonly AiDraftExecutor $executor,
        private readonly BotAksiService $aksi,
        private readonly TelegramBotService $bot,
        private readonly PilihanReferensiDraf $pilihan,
    ) {}

    public static function milik(?string $jenis): bool
    {
        return in_array($jenis, [BotAksi::JENIS_AI_LANJUT, BotAksi::JENIS_AI_YAKIN, BotAksi::JENIS_AI_BATAL, BotAksi::JENIS_AI_PILIH], true);
    }

    /**
     * Layar konfirmasi untuk draf yang tool-nya punya eksekusi(); null = draf memakai jalur lama (tombol web_app
     * "Periksa & Simpan"). Dipanggil tepat setelah Agen AI membuat draf, di chat pribadi pemilik.
     * Draf dengan rujukan nama ambigu -> layar PILIHAN (tombol per kandidat) lebih dulu; draf tool berujukan tanpa
     * eksekusi chat (mis. menu) -> layar PRATINJAU (ringkasan server + Periksa & Simpan + Batal).
     */
    public function layarKonfirmasi(User $user, int $chatId, AiDraft $draft, ?string $transkrip = null, ?string $catatan = null): ?BotLayar
    {
        if (PilihanReferensiDraf::tertunda($draft) !== []) {
            return $this->layarPilihan($user, $chatId, $draft, $catatan);
        }

        $tool = $this->executor->toolUntuk($draft);

        if ($tool === null) {
            return $this->layarPratinjau($user, $chatId, $draft, $catatan);
        }

        if ((int) $draft->user_id !== (int) $user->getKey() || $user->telegram_id === null) {
            return null;
        }

        $ringkasan = ActingUser::run($user, fn () => $tool->ringkasan($user, $draft));
        $hambatan = $this->executor->hambatan($user, $draft, $tool);
        $galat = [];

        if ($hambatan === null) {
            $cek = ActingUser::run($user, fn () => $tool->revalidate($user, $draft));

            if ($cek !== null && ! $cek->authorized) {
                $hambatan = HasilEksekusi::DITOLAK;
            } elseif ($cek !== null && $cek->errors !== []) {
                $hambatan = HasilEksekusi::HANYA_MINIAPP;
                $galat = array_slice(array_values(array_filter(collect($cek->errors)->flatten()->all(), 'is_string')), 0, 3);
            }
        }

        if (in_array($hambatan, [HasilEksekusi::DITOLAK, HasilEksekusi::TIDAK_BERLAKU], true)) {
            return null;
        }

        $berbahaya = $tool->berbahaya($draft);
        $bisaLanjut = $hambatan === null;
        $isi = $this->isiRingkasan($ringkasan, $berbahaya ? 'Konfirmasi (aksi penting)' : 'Konfirmasi');

        if ($transkrip !== null && trim($transkrip) !== '') {
            $dengar = WriteDraftTool::satuBaris($transkrip, self::MAKS_TRANSKRIP);
            $isi[] = '';
            $isi[] = 'Yang saya dengar: "'.self::e($dengar).'"';
        }

        $perluDicek = $this->executor->perluDicek($draft);

        if (! $bisaLanjut) {
            $isi[] = '';
            $isi[] = match (true) {
                $galat !== [] => 'Perlu dilengkapi: '.self::e(implode(' ', $galat)),
                $perluDicek !== [] => 'Isian berikut kurang jelas dan perlu dicek: '.self::e(implode(', ', $perluDicek)).'. Periksa di Mini App atau ulangi permintaan dengan lebih jelas.',
                default => 'Aksi ini perlu diselesaikan di Mini App (mis. melampirkan foto/nota).',
            };
        }

        if ($catatan !== null) {
            $isi[] = '';
            $isi[] = self::e($catatan);
        }

        $isi[] = '';
        $isi[] = ($bisaLanjut
            ? 'Data BELUM berubah. Tekan <b>'.self::LABEL_LANJUT.'</b> untuk menjalankan'.($berbahaya ? ' (akan diminta konfirmasi kedua)' : '').', atau <b>Batal</b>.'
            : 'Data BELUM berubah. Tekan <b>Batal</b> untuk membuang draf.')
            .' Balasan teks seperti "ya" tidak menjalankan apa pun. Tombol berlaku sampai '.self::jam($draft->expires_at).' WIB.';

        $grup = BotAksiService::grupBaru();
        $opsi = $this->opsi($draft);
        $baris = [];

        if ($bisaLanjut) {
            $baris[] = $this->aksi->tombol(self::LABEL_LANJUT, $this->aksi->terbit($user, $chatId, BotAksi::JENIS_AI_LANJUT, $opsi, $grup));
        }

        $baris[] = $this->aksi->tombol(self::LABEL_BATAL, $this->aksi->terbit($user, $chatId, BotAksi::JENIS_AI_BATAL, $opsi, $grup));
        $keyboard = [$baris];

        if ($tool->eksekusi()?->bisaUbah ?? false) {
            $ubah = TelegramBotService::webAppButton(self::LABEL_UBAH.' di Mini App', $this->bot->miniAppUrl(['draft' => $draft->uuid]));

            if ($ubah !== null) {
                $keyboard[] = [$ubah];
            }
        }

        return new BotLayar(implode("\n", $isi), ['inline_keyboard' => $keyboard], true, true);
    }

    /**
     * Klik tombol AI (dipanggil BotCallbackHandler SETELAH rate limit, MAC + baris + ikatan from.id/chat.id, pengguna
     * dimuat ulang, sesi penuh, dan konsumsi token atomik).
     *
     * @return array{hasil: string, layar: BotLayar, rahasia: ?BotLayar}
     */
    public function tangani(User $user, BotAksi $token, int $chatId): array
    {
        $draftId = (int) $token->subjek_id;

        if ($token->jenis === BotAksi::JENIS_AI_BATAL) {
            $this->executor->batalkan($user, $draftId);

            return ['hasil' => 'batal', 'layar' => new BotLayar('Dibatalkan. Tidak ada perubahan data.', null, true, true), 'rahasia' => null];
        }

        if ($token->jenis === BotAksi::JENIS_AI_PILIH) {
            return $this->tanganiPilihan($user, $draftId, (int) $token->subjek_versi, $chatId);
        }

        // Judul/target untuk layar hasil diambil SEBELUM eksekusi (target hapus tidak ada lagi sesudahnya).
        $draft = AiDraft::query()->withoutGlobalScopes()->find($draftId);
        $tool = $draft === null ? null : $this->executor->toolUntuk($draft);
        $ringkasan = null;

        if ($draft !== null && $tool !== null && (int) $draft->user_id === (int) $user->getKey()) {
            try {
                $ringkasan = ActingUser::run($user, fn () => $tool->ringkasan($user, $draft));
            } catch (Throwable $e) {
                BotGalat::catat($e, 'ai_ringkasan');
            }
        }

        $hasil = $this->executor->langkah($user, $draftId, $token->jenis === BotAksi::JENIS_AI_YAKIN ? AiDraftExecutor::YAKIN : AiDraftExecutor::LANJUT);

        return ['hasil' => $hasil->kode, 'layar' => $this->layarHasil($user, $chatId, $hasil, $ringkasan, $tool), 'rahasia' => $this->layarRahasia($hasil)];
    }

    /**
     * Pesan rahasia hasil (tautan undangan) gagal dikirim setelah aksi dijalankan: rahasia tidak disimpan sehingga tidak
     * dapat ditampilkan ulang -> hasilnya dicabut otomatis (tool::cabutHasil) dan pengguna diminta mengulang.
     *
     * @return array{hasil: string, layar: BotLayar}
     */
    public function rahasiaGagalTerkirim(User $user, BotAksi $token): array
    {
        $draft = AiDraft::query()->withoutGlobalScopes()->find((int) $token->subjek_id);
        $dicabut = $draft !== null && $this->executor->cabutHasilRahasia($user, $draft);

        return [
            'hasil' => $dicabut ? 'rahasia_gagal_dicabut' : 'rahasia_gagal',
            'layar' => $this->pesan($dicabut
                ? 'Undangan sempat dibuat, tetapi tautannya gagal dikirim ke chat ini. Demi keamanan undangan tersebut sudah DICABUT otomatis. Ulangi permintaan Anda untuk membuat tautan baru.'
                : 'Aksi sudah dijalankan, tetapi tautan gagal dikirim dan tidak dapat dicabut otomatis. Cabut undangan tersebut di Mini App (Pengguna > Undangan), lalu buat ulang.'),
        ];
    }

    /**
     * Layar PILIHAN rujukan ambigu (slot pertama): satu tombol `ai_pilih` per kandidat (nama + satuan + ID dari basis data
     * SPPG pengguna, escape HTML), + Batal + Ubah di Mini App. Semua tombol satu grup (sekali pakai per layar); makna
     * tombol (draf + slot + posisi kandidat) hanya di baris bot_aksi dan diverifikasi ulang saat ditekan (PilihanReferensiDraf).
     */
    public function layarPilihan(User $user, int $chatId, AiDraft $draft, ?string $catatan = null): ?BotLayar
    {
        if ((int) $draft->user_id !== (int) $user->getKey() || $user->telegram_id === null || ! $draft->bisaDikonfirmasi()
            || ! ($this->pilihan->tool($draft)?->isAvailableFor($user) ?? false)) {
            return null;
        }

        $tampil = $this->pilihan->berikut($user, $draft);

        if ($tampil === null) {
            return null;
        }

        $jenis = $tampil['jenis'];
        $slot = $tampil['slot'];
        $sisa = count(PilihanReferensiDraf::tertunda($draft)) - 1;
        $isi = ['<b>'.self::e(WriteDraftTool::satuBaris('Pilih '.$jenis->label.' untuk "'.$slot['dicari'].'"', 120)).'</b>'];
        $isi[] = self::e(WriteDraftTool::satuBaris('Takaran: '.$tampil['keterangan'].'.', 120));
        $isi[] = $tampil['kandidat'] === []
            ? self::e('Data yang cocok sudah tidak tersedia. Lengkapi di Mini App atau ulangi permintaan.')
            : self::e('Ada beberapa '.$jenis->label.' dengan nama mirip di SPPG Anda. Pilih yang dimaksud (sistem tidak menebak):');

        if ($slot['lebih_banyak']) {
            $isi[] = self::e('Ada lebih dari '.ResolverReferensi::MAKS_PILIHAN.' yang cocok. Bila tidak ada di daftar, tekan Batal lalu ulangi dengan nama yang lebih spesifik, atau pilih di Mini App.');
        }

        if ($sisa > 0) {
            $isi[] = self::e('Setelah ini masih ada '.$sisa.' nama lain yang perlu dipilih.');
        }

        if ($catatan !== null) {
            $isi[] = '';
            $isi[] = self::e(WriteDraftTool::satuBaris($catatan, 300));
        }

        $isi[] = '';
        $isi[] = 'Data BELUM berubah. Tombol berlaku sampai '.self::jam($draft->expires_at).' WIB.';

        $grup = BotAksiService::grupBaru();
        $opsi = $this->opsi($draft);
        $keyboard = [];

        foreach ($tampil['kandidat'] as $k) {
            // subjek_versi = kode slot + posisi kandidat (PilihanReferensiDraf::kode): token tidak berlaku untuk slot lain.
            $kode = PilihanReferensiDraf::kode($slot, $k->id);

            if ($kode === null) {
                continue;
            }

            $label = mb_substr($k->label(), 0, 50).' #'.$k->id;
            $keyboard[] = [$this->aksi->tombol($label, $this->aksi->terbit($user, $chatId, BotAksi::JENIS_AI_PILIH, $opsi + ['subjek_versi' => $kode], $grup))];
        }

        $bawah = [$this->aksi->tombol(self::LABEL_BATAL, $this->aksi->terbit($user, $chatId, BotAksi::JENIS_AI_BATAL, $opsi, $grup))];
        $ubah = TelegramBotService::webAppButton(self::LABEL_UBAH.' di Mini App', $this->bot->miniAppUrl(['draft' => $draft->uuid]));

        if ($ubah !== null) {
            $bawah[] = $ubah;
        }

        $keyboard[] = $bawah;

        return new BotLayar(implode("\n", $isi), ['inline_keyboard' => $keyboard], true, true);
    }

    /**
     * PRATINJAU draf tool berujukan yang hanya disimpan lewat Mini App (mis. menu): ringkasan server (nama + satuan bahan
     * terpilih dari basis data) + "Periksa & Simpan" (web_app ?draft=) + Batal. Tidak ada tombol yang menyimpan data.
     */
    private function layarPratinjau(User $user, int $chatId, AiDraft $draft, ?string $catatan = null): ?BotLayar
    {
        $tool = $this->pilihan->tool($draft);

        if ($tool === null || $tool->eksekusi() !== null || (int) $draft->user_id !== (int) $user->getKey() || $user->telegram_id === null
            || ! $draft->bisaDikonfirmasi() || ! $tool->isAvailableFor($user)) {
            return null;
        }

        $cek = ActingUser::run($user, fn () => $tool->revalidate($user, $draft));

        if ($cek !== null && ! $cek->authorized) {
            return null;
        }

        $isi = $this->isiRingkasan(ActingUser::run($user, fn () => $tool->ringkasan($user, $draft)), 'Pratinjau draf');
        $galat = $cek === null ? [] : array_slice(array_values(array_filter(collect($cek->errors)->flatten()->all(), 'is_string')), 0, 3);

        if ($galat !== []) {
            $isi[] = '';
            $isi[] = 'Perlu dilengkapi: '.self::e(WriteDraftTool::satuBaris(implode(' ', $galat), 300));
        }

        if ($catatan !== null) {
            $isi[] = '';
            $isi[] = self::e(WriteDraftTool::satuBaris($catatan, 300));
        }

        $isi[] = '';
        $isi[] = 'Data BELUM disimpan. Tekan <b>'.self::e(self::LABEL_PERIKSA).'</b> untuk memeriksa dan menyimpan di Mini App, atau <b>Batal</b>. Tombol berlaku sampai '.self::jam($draft->expires_at).' WIB.';

        $keyboard = [];
        $periksa = TelegramBotService::webAppButton(self::LABEL_PERIKSA, $this->bot->miniAppUrl(['draft' => $draft->uuid]));

        if ($periksa !== null) {
            $keyboard[] = [$periksa];
        }

        $keyboard[] = [$this->aksi->tombol(self::LABEL_BATAL, $this->aksi->terbit($user, $chatId, BotAksi::JENIS_AI_BATAL, $this->opsi($draft), BotAksiService::grupBaru()))];

        return new BotLayar(implode("\n", $isi), ['inline_keyboard' => $keyboard], true, true);
    }

    /** @return array{hasil: string, layar: BotLayar, rahasia: null} */
    private function tanganiPilihan(User $user, int $draftId, int $kode, int $chatId): array
    {
        $hasil = $this->pilihan->pilih($user, $draftId, $kode);
        $draft = $hasil->draft;

        $layar = match ($hasil->kode) {
            HasilPilihan::DIPILIH => $draft === null
                ? $this->pesan(self::PESAN_TIDAK_BERLAKU)
                : ($this->layarKonfirmasi($user, $chatId, $draft, null, 'Pilihan disimpan pada draf.') ?? $this->pesan('Pilihan disimpan pada draf. Periksa dan simpan draf di Mini App.')),
            HasilPilihan::TIDAK_ADA, HasilPilihan::TIDAK_COCOK => $draft === null
                ? $this->pesan(self::PESAN_TIDAK_BERLAKU)
                : ($this->layarPilihan($user, $chatId, $draft, $hasil->pesan) ?? $this->pesan(($hasil->pesan ?? 'Pilihan tidak dapat dipakai.').' Lengkapi di Mini App.')),
            HasilPilihan::DITOLAK => $this->pesan(self::PESAN_DITOLAK),
            default => $this->pesan(self::PESAN_TIDAK_BERLAKU),
        };

        return ['hasil' => $hasil->kode, 'layar' => $layar, 'rahasia' => null];
    }

    private function layarHasil(User $user, int $chatId, HasilEksekusi $hasil, ?RingkasanDraf $ringkasan, ?WriteDraftTool $tool): BotLayar
    {
        $draft = $hasil->draft;

        return match ($hasil->kode) {
            HasilEksekusi::KONFIRMASI_ULANG => $draft === null ? $this->pesan(self::PESAN_TIDAK_BERLAKU) : $this->layarYakin($user, $chatId, $draft, $ringkasan),
            HasilEksekusi::SUKSES => new BotLayar(implode("\n", array_filter([
                '<b>Berhasil: '.self::e($ringkasan?->judul ?? 'aksi Agen AI').'</b>',
                $ringkasan?->target === null ? null : 'Target: '.self::e(WriteDraftTool::satuBaris($ringkasan->target, 120)),
                'Dijalankan atas nama Anda dan tercatat di log audit (sumber Agen AI).',
                $hasil->rahasia === null ? null : 'Tautan dikirim di pesan terpisah. Bagikan hanya kepada orang yang dituju.',
            ])), null, true, true),
            HasilEksekusi::BERUBAH => $this->pesan(self::PESAN_BERUBAH),
            HasilEksekusi::DITOLAK => $this->pesan(self::PESAN_DITOLAK),
            HasilEksekusi::TIDAK_ADA => $this->pesan('Data yang dimaksud tidak ditemukan atau sudah dihapus. Tidak ada perubahan data.'),
            HasilEksekusi::VALIDASI => $this->pesan(($hasil->pesan ?? 'Data tidak lolos aturan sistem.').' Tidak ada perubahan data. Ulangi permintaan atau gunakan Mini App.'),
            HasilEksekusi::TANPA_SESI => new BotLayar(TelegramUpdateHandler::PERLU_MASUK, null, false),
            HasilEksekusi::HANYA_MINIAPP => $this->pesan('Draf ini perlu diperiksa atau dilengkapi di Mini App. Tidak ada perubahan data.'),
            HasilEksekusi::TERLALU_CEPAT => $draft !== null && $draft->status === StatusAiDraft::MenungguKonfirmasiUlang
                ? $this->layarYakin($user, $chatId, $draft, $ringkasan, 'Terlalu banyak permintaan. Tunggu sebentar lalu tekan lagi.')
                : ($draft === null ? $this->pesan(self::PESAN_TIDAK_BERLAKU)
                    : ($this->layarKonfirmasi($user, $chatId, $draft, null, 'Terlalu banyak permintaan. Tunggu sebentar lalu tekan lagi.') ?? $this->pesan(self::PESAN_TIDAK_BERLAKU))),
            HasilEksekusi::GAGAL => $this->pesan('Terjadi kesalahan saat menjalankan aksi. Periksa hasilnya di Mini App sebelum mencoba lagi.'),
            default => $this->pesan(self::PESAN_TIDAK_BERLAKU),
        };
    }

    /** Langkah 2 aksi berbahaya: peringatan tegas + nama target dari DB, tombol "Ya, yakin" / Batal (grup baru). */
    private function layarYakin(User $user, int $chatId, AiDraft $draft, ?RingkasanDraf $ringkasan, ?string $catatan = null): BotLayar
    {
        $ringkasan ??= new RingkasanDraf('aksi Agen AI');
        $isi = $this->isiRingkasan($ringkasan, 'KONFIRMASI KEDUA');
        $isi[] = '';
        $isi[] = '<b>Tindakan ini berdampak langsung dan tidak dapat dibatalkan dari chat.</b>';

        if ($catatan !== null) {
            $isi[] = self::e($catatan);
        }

        $isi[] = 'Tekan <b>'.self::LABEL_YAKIN.'</b> untuk menjalankan atau <b>Batal</b>. Tombol berlaku sampai '.self::jam($draft->expires_at).' WIB.';

        $grup = BotAksiService::grupBaru();
        $opsi = $this->opsi($draft);

        return new BotLayar(implode("\n", $isi), ['inline_keyboard' => [[
            $this->aksi->tombol(self::LABEL_YAKIN, $this->aksi->terbit($user, $chatId, BotAksi::JENIS_AI_YAKIN, $opsi, $grup)),
            $this->aksi->tombol(self::LABEL_BATAL, $this->aksi->terbit($user, $chatId, BotAksi::JENIS_AI_BATAL, $opsi, $grup)),
        ]]], true, true);
    }

    /**
     * Pesan TERPISAH berisi rahasia hasil (tautan undangan): teks polos, protect_content (tidak dapat diteruskan/disimpan),
     * tanpa pratinjau tautan (TelegramBotService), tidak di-log dan tidak disimpan.
     */
    private function layarRahasia(HasilEksekusi $hasil): ?BotLayar
    {
        if ($hasil->rahasia === null) {
            return null;
        }

        return new BotLayar(
            "Tautan undangan SiGAP SPPG (rahasia, sekali pakai, berlaku 72 jam):\n".$hasil->rahasia
            ."\n\nKirim hanya kepada orang yang dituju lewat chat pribadi. Jangan bagikan di grup. Bila bocor, cabut undangan di Mini App.",
            null, false, true,
        );
    }

    /** @return list<string> */
    private function isiRingkasan(RingkasanDraf $r, string $awalan): array
    {
        // Setiap bagian diratakan menjadi SATU baris (lapis kedua selain tool): nama/nilai berisi baris baru atau kendali
        // bidi tidak dapat menyisipkan baris palsu seperti "- Peran baru: ...".
        $satu = fn (string $t, int $maks = 200): string => self::e(WriteDraftTool::satuBaris($t, $maks));
        $isi = ['<b>'.$satu($awalan.': '.$r->judul).'</b>'];

        if ($r->target !== null) {
            $isi[] = 'Target: <b>'.$satu($r->target, 120).'</b>';
        }

        foreach ($r->baris as $baris) {
            $isi[] = '- '.$satu($baris);
        }

        if ($r->peringatan !== null) {
            $isi[] = '<b>'.$satu($r->peringatan).'</b>';
        }

        return $isi;
    }

    /** @return array{sppg_id: int|null, subjek_tipe: string, subjek_id: int, expires_at: \DateTimeInterface} */
    private function opsi(AiDraft $draft): array
    {
        return [
            'sppg_id' => $draft->sppg_id === null ? null : (int) $draft->sppg_id,
            'subjek_tipe' => BotAksi::SUBJEK_AI_DRAFT,
            'subjek_id' => (int) $draft->getKey(),
            'expires_at' => $draft->expires_at,
        ];
    }

    private function pesan(string $teks): BotLayar
    {
        return new BotLayar(self::e($teks), null, true, true);
    }

    private static function jam(?\DateTimeInterface $t): string
    {
        return $t === null ? '-' : Carbon::instance($t)->setTimezone((string) config('operasional.timezone', 'Asia/Jakarta'))->format('H:i');
    }

    private static function e(string $teks): string
    {
        return TelegramBotService::escapeHtml($teks);
    }
}

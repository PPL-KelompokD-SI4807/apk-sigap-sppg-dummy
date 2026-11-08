<?php

namespace App\Services\Telegram;

use App\Enums\JenisInputAi;
use App\Models\AiDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiReply;
use App\Services\Ai\Drafts\GalatDraf;
use App\Services\Ai\Foto\FotoChatDitolak;
use App\Services\Ai\Foto\PraprosesFotoChat;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Speech\SpeechToTextException;
use App\Services\Ai\Speech\SpeechToTextService;
use App\Services\Telegram\Bot\BotAksiAiService;
use App\Services\Telegram\Bot\BotGalat;
use App\Services\Telegram\Bot\BotLayar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Pipeline chat -> Agen AI (dijalankan job ProcessAiMessage):
 *   teks  -> AiInput teks;
 *   foto  -> album (media_group_id): hanya foto PERTAMA yang diterima diproses (Cache::add atomik per grup, TTL pendek);
 *            foto berikutnya diabaikan TANPA unduh/LLM/balasan; satu pemberitahuan per grup ditempel di balasan foto
 *            pertama. Penyedia tanpa dukungan gambar (LLM_VISION_MODEL kosong): balasan ramah + tombol formulir TANPA
 *            mengunduh. Batas laju AI dan pelindung token gambar (AI_IMAGE_TOKENS) dicek SEBELUM unduh. Unduh satu
 *            ukuran (<= 5 MB), lalu PraprosesFotoChat: MIME dari isi (JPEG/PNG/WebP), encode ulang JPEG (EXIF/GPS
 *            terbuang, sisi ~1280 px, turun bertahap bila > batas base64). Maks 1 gambar per pesan; caption = teks;
 *   suara -> batas laju AI dicek SEBELUM STT (biaya STT); durasi (metadata Telegram, SEBELUM unduh)/ukuran/kuota detik
 *            STT per jam dicek, lalu SpeechToTextService (Null = "belum didukung") -> transkrip = teks TIDAK tepercaya.
 * Privasi: pada foto/suara PERTAMA yang benar-benar dikirim ke penyedia (foto: AiReply::mediaTerkirim; suara: tepat
 * sebelum STT), satu kalimat pemberitahuan pihak ketiga ditempel di awal balasan (penanda Cache per pengguna; tidak
 * diulang; foto ditolak/gagal unduh/kena batas laju atau pelindung token TIDAK memasang penanda). Juga di /bantuan.
 * Log hanya angka/kode (ukuran, MIME, durasi, status); tidak pernah isi gambar, base64, transkrip, atau token.
 * Balasan: teks POLOS (tanpa parse_mode). Per draf: bila tool-nya dapat dijalankan dari chat, LAYAR KONFIRMASI terpisah
 * (BotAksiAiService: ringkasan server + Lanjutkan/Batal/Ubah; suara: "Yang saya dengar"); selain itu tombol web_app
 * "Periksa & Simpan" (Mini App). Teks/suara "ya/lanjut" tidak pernah menjalankan draf: hanya tombol callback sah.
 * Draf "perlu diperbaiki" (GalatDraf, masukan salah): satu pesan server + tombol web_app "Buka di Mini App" saja.
 */
class AiChatHandler
{
    public const MAX_DRAFT_BUTTONS = 3;

    public const LABEL_PERBAIKI = 'Buka di Mini App';

    /** Pemberitahuan privasi pihak ketiga (sekali per pengguna). */
    public const PESAN_PRIVASI = 'Foto dan pesan suara diproses penyedia AI pihak ketiga; jangan kirim data pribadi.';

    /** Pemberitahuan album (sekali per media_group_id). */
    public const PESAN_ALBUM = 'Anda mengirim beberapa foto sekaligus. Hanya foto pertama yang diproses; foto lainnya belum diproses. Kirim satu foto per pesan.';

    /** @var list<string> kalimat pemberitahuan yang ditempel di awal pesan balasan berikutnya (album, privasi) */
    private array $awalan = [];

    /** Foto lolos pra-pemrosesan dan diserahkan ke agen; privasi dicatat hanya bila agen benar-benar mengirimnya. */
    private bool $fotoSiapDikirim = false;

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly AiAgentService $agent,
        private readonly SpeechToTextService $stt,
        private readonly BotAksiAiService $konfirmasi,
        private readonly PraprosesFotoChat $foto,
    ) {}

    public function handle(TelegramUpdate $update, User $user): void
    {
        $message = (array) ($update->payload['message'] ?? []);
        $chatId = $message['chat']['id'] ?? null;
        $jenis = 'ai';
        $this->awalan = [];
        $this->fotoSiapDikirim = false;

        try {
            if (! is_int($chatId) || ! $user->canAuthenticate()) {
                return;
            }

            $foto = isset($message['photo']) && is_array($message['photo']);
            $suara = isset($message['voice']) && is_array($message['voice']);

            // Album: foto kedua dst. dalam media group yang sama tidak diunduh, tidak dikirim ke LLM, tidak dibalas.
            $album = $foto ? $this->kunciAlbum($message, $user) : null;

            if ($album === false) {
                $jenis = 'album_dilewati';
                Log::info('ai.foto_album_dilewati', ['user_id' => $user->getKey()]);

                return;
            }

            $this->typing($chatId);

            // Batas laju AI berlaku juga untuk biaya unduh foto/STT: dicek sebelum memproses media (tanpa menghitung).
            if (($suara || $foto) && $this->agent->batasLajuTercapai($user)) {
                $this->deliver($chatId, new AiReply('Batas penggunaan Agen AI tercapai. Coba lagi nanti atau isi formulir di Mini App.', fallback: true));

                return;
            }

            if ($album === true) {
                $this->awalan[] = self::PESAN_ALBUM;
            }

            // Pelindung token global: foto ~AI_IMAGE_TOKENS; sisa kuota menit/hari tidak cukup -> balas SEBELUM unduh.
            if ($foto && $this->agent->acceptsImages()) {
                try {
                    $this->agent->cekAnggaranGambar();
                } catch (LlmException $e) {
                    Log::info('ai.foto_ditolak', ['user_id' => $user->getKey(), 'alasan' => $e->category, 'detail' => $e->detail]);
                    $this->deliver($chatId, new AiReply($e->friendlyMessage(), fallback: true));

                    return;
                }
            }

            $input = $this->buildInput($message, (int) $user->getKey());

            if (is_string($input)) {
                $this->send($chatId, $input);

                return;
            }

            if ($input instanceof AiReply) {
                $this->deliver($chatId, $input);

                return;
            }

            $reply = $this->agent->handle($user, $input);

            // Privasi foto: penanda dipasang HANYA bila gambar benar-benar dikirim ke penyedia (bukan ditolak batas
            // laju / pelindung token / galat sebelum panggilan penyedia).
            if ($this->fotoSiapDikirim && $reply->mediaTerkirim) {
                $this->beriTahuPrivasi((int) $user->getKey());
            }

            $this->deliver($chatId, $reply, $user, $input->type === JenisInputAi::Suara ? $input->text : null);
        } finally {
            $this->awalan = [];
            $this->fotoSiapDikirim = false;
            TelegramUpdateHandler::finish($update, $jenis);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return AiInput|AiReply|string AiInput, atau balasan langsung (string = galat ramah; AiReply = dengan tombol formulir)
     */
    public function buildInput(array $message, ?int $userId = null): AiInput|AiReply|string
    {
        $caption = is_string($message['caption'] ?? null) ? $message['caption'] : '';

        if (isset($message['photo']) && is_array($message['photo'])) {
            return $this->photoInput($message['photo'], $caption, $userId);
        }

        if (isset($message['voice']) && is_array($message['voice'])) {
            return $this->voiceInput($message['voice'], $userId);
        }

        return AiInput::text(is_string($message['text'] ?? null) ? $message['text'] : '');
    }

    /**
     * True = foto pertama media group (penanda baru dibuat), false = foto berikutnya (lewati), null = bukan album.
     * Penanda per pengguna + id grup (di-hash), TTL pendek (ai.input.album_ttl_seconds).
     *
     * @param  array<string, mixed>  $message
     */
    private function kunciAlbum(array $message, User $user): ?bool
    {
        $grup = $message['media_group_id'] ?? null;

        if (! is_string($grup) && ! is_int($grup)) {
            return null;
        }

        $ttl = max(10, (int) config('ai.input.album_ttl_seconds', 120));

        return Cache::add('ai:album:'.$user->getKey().':'.hash('sha256', (string) $grup), 1, $ttl);
    }

    /**
     * Tempel pemberitahuan privasi pihak ketiga SEKALI per pengguna (penanda Cache). Dipanggil hanya setelah media
     * benar-benar dikirim/akan dikirim ke penyedia (foto: setelah agen mengirim gambar; suara: tepat sebelum STT).
     */
    private function beriTahuPrivasi(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $hari = max(1, (int) config('ai.input.privasi_ttl_days', 365));

        if (Cache::add('ai:privasi:'.$userId, 1, now()->addDays($hari))) {
            $this->awalan[] = self::PESAN_PRIVASI;
        }
    }

    /** @param list<array<string, mixed>> $sizes */
    private function photoInput(array $sizes, string $caption, ?int $userId = null): AiInput|AiReply|string
    {
        // D6: model vision belum dikonfigurasi -> balas ramah SEBELUM mengunduh foto.
        if (! $this->agent->acceptsImages()) {
            return new AiReply('Membaca foto belum didukung. Silakan ketik isi nota/surat jalan, atau isi lewat formulir di Mini App.', fallback: true);
        }

        $max = (int) config('ai.input.photo_max_bytes', 5 * 1024 * 1024);
        $candidates = array_values(array_filter($sizes, fn ($s) => is_array($s) && is_string($s['file_id'] ?? null)
            && (! isset($s['file_size']) || (int) $s['file_size'] <= $max)));

        if ($candidates === []) {
            Log::info('ai.foto_ditolak', ['user_id' => $userId, 'alasan' => FotoChatDitolak::TERLALU_BESAR, 'tahap' => 'metadata']);

            return new AiReply(FotoChatDitolak::terlaluBesar()->getMessage(), fallback: true);
        }

        $pilihan = $this->pilihUkuran($candidates);

        try {
            $binary = $this->bot->downloadFile($pilihan['file_id'], $max);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.unduh_foto_gagal', ['kode' => $e->errorCode]);

            return $e->errorCode === 413
                ? new AiReply(FotoChatDitolak::terlaluBesar()->getMessage(), fallback: true)
                : 'Foto tidak dapat diunduh. Coba kirim ulang.';
        }

        $ukuran = strlen($binary);

        try {
            $siap = $this->foto->proses($binary);
        } catch (FotoChatDitolak $e) {
            Log::info('ai.foto_ditolak', ['user_id' => $userId, 'alasan' => $e->alasan, 'ukuran' => $ukuran]);

            return new AiReply($e->getMessage(), fallback: true);
        } finally {
            // Foto asli tidak disimpan: hanya hasil encode ulang yang diteruskan (di memori job).
            unset($binary);
        }

        Log::info('ai.foto_chat', ['user_id' => $userId] + $siap->konteksLog());
        $this->fotoSiapDikirim = $userId !== null;

        return AiInput::photo([$siap->gambar()], $caption);
    }

    /**
     * Ukuran terkecil yang sisi terpanjangnya sudah >= photo_max_side_px (hemat unduhan; server tetap memperkecil ke
     * ~1280 px), selain itu yang terbesar (file_size, lalu dimensi).
     *
     * @param  non-empty-list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function pilihUkuran(array $candidates): array
    {
        $sisi = fn (array $s): int => max((int) ($s['width'] ?? 0), (int) ($s['height'] ?? 0));
        $target = (int) config('ai.input.photo_max_side_px', 1280);
        $cukup = array_values(array_filter($candidates, fn (array $s) => $sisi($s) >= $target));

        if ($cukup !== []) {
            usort($cukup, fn ($a, $b) => $sisi($a) <=> $sisi($b));

            return $cukup[0];
        }

        usort($candidates, fn ($a, $b) => [(int) ($b['file_size'] ?? 0), $sisi($b)] <=> [(int) ($a['file_size'] ?? 0), $sisi($a)]);

        return $candidates[0];
    }

    /** @param array<string, mixed> $voice */
    private function voiceInput(array $voice, ?int $userId = null): AiInput|string
    {
        if (! $this->stt->isAvailable()) {
            return 'Pesan suara belum didukung. Silakan ketik pesan atau kirim foto.';
        }

        // Durasi dari metadata Telegram: pesan > batas ditolak SEBELUM diunduh/ditranskripsi.
        $durasi = max(0, (int) ($voice['duration'] ?? 0));

        if ($durasi > (int) config('ai.input.voice_max_seconds', 120)) {
            Log::info('ai.suara_ditolak', ['user_id' => $userId, 'alasan' => 'terlalu_panjang', 'durasi' => $durasi]);

            return 'Pesan suara terlalu panjang (maksimal 2 menit). Kirim pesan yang lebih pendek atau ketik permintaan Anda.';
        }

        if ((int) ($voice['file_size'] ?? 0) > (int) config('ai.input.voice_max_bytes', 10 * 1024 * 1024)) {
            return 'Pesan suara terlalu besar. Kirim pesan yang lebih pendek atau ketik permintaan Anda.';
        }

        if (! is_string($voice['file_id'] ?? null)) {
            return 'Pesan suara tidak valid.';
        }

        // Biaya STT: kuota detik audio per pengguna per jam (selain AI_RATE_*), dihitung sebelum transkripsi.
        if ($userId !== null) {
            $kunci = 'ai:stt:'.$userId;
            $maks = max(1, (int) config('ai.stt.max_detik_per_jam', 600));

            if ((int) RateLimiter::attempts($kunci) + max(1, $durasi) > $maks) {
                return 'Kuota pesan suara Anda untuk satu jam ini sudah habis. Silakan ketik permintaan Anda.';
            }

            RateLimiter::increment($kunci, 3600, max(1, $durasi));
        }

        try {
            $audio = $this->bot->downloadFile($voice['file_id'], (int) config('ai.input.voice_max_bytes', 10 * 1024 * 1024));
            $mime = is_string($voice['mime_type'] ?? null) && preg_match('#^audio/[a-z0-9.+-]{1,30}$#', $voice['mime_type']) === 1 ? $voice['mime_type'] : 'audio/ogg';
            // Audio dikirim ke penyedia STT mulai baris berikut: privasi dicatat di sini (bukan sebelum unduh).
            $this->beriTahuPrivasi($userId);
            $transkrip = $this->stt->transcribe($audio, $mime);
            Log::info('ai.suara_chat', ['user_id' => $userId, 'durasi' => $durasi, 'ukuran' => strlen($audio), 'mime' => $mime, 'status' => 'ok']);

            return AiInput::voice($transkrip, $durasi);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.unduh_suara_gagal', ['kode' => $e->errorCode]);

            return 'Pesan suara tidak dapat diunduh. Coba kirim ulang.';
        } catch (SpeechToTextException $e) {
            Log::info('ai.suara_chat', ['user_id' => $userId, 'durasi' => $durasi, 'status' => 'gagal']);

            return $e->getMessage();
        }
    }

    /**
     * @param  ?string  $transkrip  transkrip suara (ditampilkan "Yang saya dengar" di layar konfirmasi; tidak disimpan)
     */
    public function deliver(int $chatId, AiReply $reply, ?User $user = null, ?string $transkrip = null): void
    {
        $buttons = [];
        $layarKonfirmasi = [];
        $lama = 0;

        foreach (array_slice($reply->drafts, 0, self::MAX_DRAFT_BUTTONS) as $draft) {
            // Draf "perlu diperbaiki" (masukan salah): hanya tombol web_app ke formulir draf, tanpa layar konfirmasi,
            // tanpa Lanjutkan dan tanpa tombol pilihan. Pesan teksnya sudah disusun server (AiAgentService).
            if (GalatDraf::ada($draft)) {
                $lama++;
                $buttons[] = TelegramBotService::webAppButton(self::LABEL_PERBAIKI, $this->bot->miniAppUrl(['draft' => $draft->uuid]));

                continue;
            }

            $layar = $this->layarKonfirmasi($chatId, $draft, $user, $transkrip);

            if ($layar !== null) {
                $layarKonfirmasi[] = $layar;

                continue;
            }

            $lama++;
            $label = (string) config("rbac.modules.{$draft->module}.label", $draft->module);
            $buttons[] = TelegramBotService::webAppButton(
                'Periksa & Simpan: '.mb_substr($label, 0, 40),
                $this->bot->miniAppUrl(['draft' => $draft->uuid]),
            );
        }

        if ($reply->fallback || ($lama > 0 && array_filter($buttons) === [])) {
            $buttons[] = TelegramBotService::webAppButton('Buka formulir SiGAP', $this->bot->miniAppUrl());
        }

        $this->send($chatId, $reply->text, TelegramBotService::inlineKeyboard($buttons));

        foreach ($layarKonfirmasi as $layar) {
            try {
                $this->bot->sendMessage($chatId, $layar->teks, array_filter([
                    'parse_mode' => $layar->html ? TelegramBotService::PARSE_HTML : null,
                    'reply_markup' => $layar->markup,
                    'protect_content' => $layar->lindungi ?: null,
                ]));
            } catch (TelegramApiException $e) {
                Log::warning('telegram.konfirmasi_ai_gagal', ['kode' => $e->errorCode]);
            }
        }
    }

    /** Layar konfirmasi chat hanya di chat pribadi pemilik draf; galat -> jalur lama (tombol Mini App). */
    private function layarKonfirmasi(int $chatId, AiDraft $draft, ?User $user, ?string $transkrip): ?BotLayar
    {
        if ($user === null || $user->telegram_id === null || (int) $user->telegram_id !== $chatId) {
            return null;
        }

        try {
            return $this->konfirmasi->layarKonfirmasi($user, $chatId, $draft, $transkrip);
        } catch (Throwable $e) {
            BotGalat::catat($e, 'ai_konfirmasi');

            return null;
        }
    }

    /**
     * Teks polos tanpa parse_mode: isi dari model/pengguna tidak pernah dirender sebagai markup. Pemberitahuan tertunda
     * (album, privasi) ditempel di awal pesan utama berikutnya lalu dikosongkan (satu pesan, tidak diulang).
     */
    private function send(int $chatId, string $text, ?array $markup = null): void
    {
        if ($this->awalan !== []) {
            $text = implode("\n", $this->awalan)."\n\n".$text;
            $this->awalan = [];
        }

        try {
            $this->bot->sendText($chatId, $text, $markup);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.balasan_ai_gagal', ['kode' => $e->errorCode, 'galat' => $e->getMessage()]);
        }
    }

    private function typing(int $chatId): void
    {
        try {
            $this->bot->sendChatAction($chatId);
        } catch (TelegramApiException) {
            // Indikator mengetik bersifat kosmetik.
        }
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\AiReply;
use App\Services\Ai\Speech\SpeechToTextException;
use App\Services\Ai\Speech\SpeechToTextService;
use finfo;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline chat -> Agen AI (dijalankan job ProcessAiMessage):
 *   teks  -> AiInput teks;
 *   foto  -> bila penyedia tidak mendukung gambar (LLM_VISION_MODEL kosong): balasan ramah + tombol
 *            formulir TANPA mengunduh; selain itu unduh ukuran terbesar <= batas, verifikasi MIME aktual
 *            (JPEG/PNG/WebP), kirim ke agen sebagai lampiran gambar (dibaca LLM/model vision); caption = teks;
 *   suara -> SpeechToTextService (Null = "belum didukung") -> transkrip sebagai teks.
 * Balasan: teks POLOS (tanpa parse_mode) + tombol web_app "Periksa & Simpan" per draf.
 */
class AiChatHandler
{
    public const MAX_DRAFT_BUTTONS = 3;

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly AiAgentService $agent,
        private readonly SpeechToTextService $stt,
    ) {}

    public function handle(TelegramUpdate $update, User $user): void
    {
        $message = (array) ($update->payload['message'] ?? []);
        $chatId = $message['chat']['id'] ?? null;

        try {
            if (! is_int($chatId) || ! $user->canAuthenticate()) {
                return;
            }

            $this->typing($chatId);
            $input = $this->buildInput($message);

            if (is_string($input)) {
                $this->send($chatId, $input);

                return;
            }

            if ($input instanceof AiReply) {
                $this->deliver($chatId, $input);

                return;
            }

            $this->deliver($chatId, $this->agent->handle($user, $input));
        } finally {
            TelegramUpdateHandler::finish($update, 'ai');
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return AiInput|AiReply|string AiInput, atau balasan langsung (string = galat ramah; AiReply = dengan tombol formulir)
     */
    public function buildInput(array $message): AiInput|AiReply|string
    {
        $caption = is_string($message['caption'] ?? null) ? $message['caption'] : '';

        if (isset($message['photo']) && is_array($message['photo'])) {
            return $this->photoInput($message['photo'], $caption);
        }

        if (isset($message['voice']) && is_array($message['voice'])) {
            return $this->voiceInput($message['voice']);
        }

        return AiInput::text(is_string($message['text'] ?? null) ? $message['text'] : '');
    }

    /** @param list<array<string, mixed>> $sizes */
    private function photoInput(array $sizes, string $caption): AiInput|AiReply|string
    {
        // D6: model vision belum dikonfigurasi -> balas ramah SEBELUM mengunduh foto.
        if (! $this->agent->acceptsImages()) {
            return new AiReply('Membaca foto belum didukung. Silakan ketik isi nota/surat jalan, atau isi lewat formulir di Mini App.', fallback: true);
        }

        $max = (int) config('ai.input.photo_max_bytes', 5 * 1024 * 1024);
        $candidates = array_filter($sizes, fn ($s) => is_array($s) && is_string($s['file_id'] ?? null)
            && (! isset($s['file_size']) || (int) $s['file_size'] <= $max));

        if ($candidates === []) {
            return 'Foto terlalu besar (maksimal 5 MB).';
        }

        usort($candidates, fn ($a, $b) => ((int) ($b['file_size'] ?? 0)) <=> ((int) ($a['file_size'] ?? 0)));

        try {
            $binary = $this->bot->downloadFile($candidates[0]['file_id'], $max);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.unduh_foto_gagal', ['kode' => $e->errorCode]);

            return 'Foto tidak dapat diunduh. Coba kirim ulang.';
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: '';

        if (! in_array($mime, (array) config('ai.input.photo_mimes', ['image/jpeg', 'image/png', 'image/webp']), true)) {
            return 'Format foto tidak didukung (hanya JPEG, PNG, atau WebP).';
        }

        return AiInput::photo([['media_type' => $mime, 'data' => base64_encode($binary)]], $caption);
    }

    /** @param array<string, mixed> $voice */
    private function voiceInput(array $voice): AiInput|string
    {
        if (! $this->stt->isAvailable()) {
            return 'Pesan suara belum didukung. Silakan ketik pesan atau kirim foto.';
        }

        if ((int) ($voice['duration'] ?? 0) > (int) config('ai.input.voice_max_seconds', 120)) {
            return 'Pesan suara terlalu panjang (maksimal 2 menit).';
        }

        if (! is_string($voice['file_id'] ?? null)) {
            return 'Pesan suara tidak valid.';
        }

        try {
            $audio = $this->bot->downloadFile($voice['file_id'], (int) config('ai.input.voice_max_bytes', 10 * 1024 * 1024));
            $mime = is_string($voice['mime_type'] ?? null) && preg_match('#^audio/[a-z0-9.+-]{1,30}$#', $voice['mime_type']) === 1 ? $voice['mime_type'] : 'audio/ogg';

            return AiInput::voice($this->stt->transcribe($audio, $mime));
        } catch (TelegramApiException $e) {
            Log::warning('telegram.unduh_suara_gagal', ['kode' => $e->errorCode]);

            return 'Pesan suara tidak dapat diunduh. Coba kirim ulang.';
        } catch (SpeechToTextException $e) {
            return $e->getMessage();
        }
    }

    public function deliver(int $chatId, AiReply $reply): void
    {
        $buttons = [];

        foreach (array_slice($reply->drafts, 0, self::MAX_DRAFT_BUTTONS) as $draft) {
            $label = (string) config("rbac.modules.{$draft->module}.label", $draft->module);
            $buttons[] = TelegramBotService::webAppButton(
                'Periksa & Simpan: '.mb_substr($label, 0, 40),
                $this->bot->miniAppUrl(['draft' => $draft->uuid]),
            );
        }

        if ($reply->fallback || ($reply->drafts !== [] && array_filter($buttons) === [])) {
            $buttons[] = TelegramBotService::webAppButton('Buka formulir SiGAP', $this->bot->miniAppUrl());
        }

        $this->send($chatId, $reply->text, TelegramBotService::inlineKeyboard($buttons));
    }

    /** Teks polos tanpa parse_mode: isi dari model/pengguna tidak pernah dirender sebagai markup. */
    private function send(int $chatId, string $text, ?array $markup = null): void
    {
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

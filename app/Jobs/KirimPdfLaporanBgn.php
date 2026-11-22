<?php

namespace App\Jobs;

use App\Enums\JenisLaporanBgn;
use App\Models\Sppg;
use App\Models\User;
use App\Services\LaporanBgn\LaporanBgnPdfService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Buat PDF laporan BGN Lampiran 30a-30d lalu kirim ke chat Telegram pemintanya lewat sendDocument (R07-3, Q-6/Q-10).
 *
 * - Hak diperiksa ULANG saat job berjalan (pengguna masih aktif, telegram_id masih sama, Policy laporan + SPPG);
 *   pencabutan hak antara permintaan dan eksekusi = dilewati tanpa kirim.
 * - Berkas PDF sementara selalu dihapus (finally); log hanya jenis/status/hash chat (tanpa isi laporan).
 * - 429 dihormati (release); 400/403 Telegram permanen tidak diulang; galat lain diulang sesuai backoff.
 */
class KirimPdfLaporanBgn implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    /**
     * @param  array{akuntan_id?: int|null, kepala_sppg_id?: int|null}  $penandatangan
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $chatId,
        public readonly string $jenis,
        public readonly int $sppgId,
        public readonly string $awal,
        public readonly string $akhir,
        public readonly array $penandatangan = [],
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('telegram-out')];
    }

    public function handle(LaporanBgnPdfService $pdf, TelegramBotService $bot): void
    {
        $user = User::query()->withoutGlobalScopes()->find($this->userId);
        $jenis = JenisLaporanBgn::tryFrom($this->jenis);

        if ($user === null || $jenis === null || ! $user->canAuthenticate() || (int) $user->telegram_id !== $this->chatId) {
            $this->log('dilewati');

            return;
        }

        if (! $pdf->boleh($user, $jenis, $this->sppgId)) {
            $this->log('ditolak_hak');

            return;
        }

        $sppg = Sppg::query()->withoutGlobalScopes()->find($this->sppgId);

        if ($sppg === null || $sppg->trashed()) {
            $this->log('sppg_hilang');

            return;
        }

        $awal = CarbonImmutable::createFromFormat('Y-m-d', $this->awal, (string) config('operasional.timezone', 'Asia/Jakarta'))->startOfDay();
        $akhir = CarbonImmutable::createFromFormat('Y-m-d', $this->akhir, (string) config('operasional.timezone', 'Asia/Jakarta'))->startOfDay();
        $berkas = null;

        try {
            $berkas = $pdf->buat($user, $jenis, $sppg, $awal, $akhir, $this->penandatangan);
            $bot->sendDocument($this->chatId, $berkas, LaporanBgnPdfService::namaBerkas($jenis, $awal, $akhir), 'Laporan BGN Lampiran '.$jenis->lampiran().' ('.$this->awal.($this->awal === $this->akhir ? '' : ' s/d '.$this->akhir).')');
            $this->log('terkirim');
        } catch (ValidationException) {
            $this->log('penandatangan_tidak_valid');
        } catch (TelegramApiException $e) {
            if ($e->isRateLimited()) {
                $this->release(max(1, min(60, (int) ($e->retryAfter ?? 5))));

                return;
            }

            if ($e->isPermanent()) {
                $this->log('ditolak', $e->errorCode);

                return;
            }

            throw $e;
        } finally {
            if ($berkas !== null) {
                @unlink($berkas);
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->log('gagal', $e instanceof TelegramApiException ? $e->errorCode : null);

        try {
            app(TelegramBotService::class)->sendText($this->chatId, 'Laporan BGN gagal dibuat atau dikirim. Silakan coba lagi beberapa saat lagi.');
        } catch (Throwable) {
            // Pemberitahuan kegagalan bersifat upaya terbaik.
        }
    }

    private function log(string $status, ?int $code = null): void
    {
        Log::info('laporan_bgn.pdf', array_filter([
            'jenis' => $this->jenis,
            'status' => $status,
            'kode' => $code,
            'penerima' => substr(hash('sha256', (string) $this->chatId), 0, 12),
            'user_id' => $this->userId,
            'sppg_id' => $this->sppgId,
        ], fn ($v) => $v !== null));
    }
}

<?php

namespace Tests\Feature\Ai\Eksekusi;

use App\Enums\AksiAiDraft;
use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\BotAksi;
use App\Models\User;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Telegram\Bot\BotAksiService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Master\MasterApiTestCase;
use Tests\Feature\Telegram\FakesTelegram;

/**
 * Dasar tes eksekusi aksi Agen AI dari chat (Tahap 1). Webhook dijalankan sinkron tanpa pengguna login (seperti job
 * antrean); LLM palsu berskrip; Bot API di-fake. Semua data fiktif.
 */
abstract class AiEksekusiTestCase extends MasterApiTestCase
{
    use FakesTelegram;

    protected FakeLlmClient $llm;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    protected array $log = [];

    private int $cb = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
        config([
            'telegram.callback_rate' => 100000,
            'telegram.callback_gagal_rate' => 100000,
            'telegram.perintah_rate' => 100000,
            'ai.rate_limit.per_minute' => 1000,
            'ai.rate_limit.per_hour' => 1000,
        ]);
        $this->forgetActor();

        foreach ([$this->admin, $this->kepalaA, $this->kepalaB, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA] as $u) {
            $this->beriSesiPenuh($u);
        }

        $this->log = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->log[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    // ------------------------------------------------------------------ aktor

    protected function relawanDistribusi(): User
    {
        $u = User::factory()->role(Role::Relawan, RelawanUnit::Distribusi)->create(['sppg_id' => $this->sppgA]);
        $this->beriSesiPenuh($u);

        return $u;
    }

    protected function investor(): User
    {
        $u = User::factory()->role(Role::Investor)->create(['sppg_id' => null]);
        DB::table('investor_sppg')->insert(['user_id' => $u->id, 'sppg_id' => $this->sppgA, 'created_at' => now(), 'updated_at' => now()]);
        $this->beriSesiPenuh($u);

        return $u;
    }

    // ------------------------------------------------------------------ interaksi bot

    protected function chat(User $user, string $teks): void
    {
        $this->forgetActor();
        $this->webhook($this->privateUpdate((int) $user->telegram_id, ['text' => $teks]))->assertOk();
        $this->forgetActor();
    }

    /** Tekan tombol callback sebagai $user (default chat pribadinya sendiri). */
    protected function tekan(User $user, string $data, ?int $chatId = null, string $chatType = 'private'): void
    {
        $this->forgetActor();
        $this->webhook([
            'update_id' => 700000 + (++$this->cb),
            'callback_query' => [
                'id' => 'cb'.$this->cb,
                'from' => ['id' => (int) $user->telegram_id, 'is_bot' => false, 'first_name' => 'Uji'],
                'message' => ['message_id' => 55, 'date' => time(), 'chat' => ['id' => $chatId ?? (int) $user->telegram_id, 'type' => $chatType]],
                'chat_instance' => 'ci',
                'data' => $data,
            ],
        ])->assertOk();
        $this->forgetActor();
    }

    /** Pesan/edit terakhir ke chat $user. */
    protected function layar(User $user): ?Request
    {
        $hasil = null;

        foreach (Http::recorded() as [$r]) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            if ((str_ends_with($path, '/sendMessage') || str_ends_with($path, '/editMessageText')) && (string) $r['chat_id'] === (string) $user->telegram_id) {
                $hasil = $r;
            }
        }

        return $hasil;
    }

    protected function teks(User $user): string
    {
        return (string) ($this->layar($user)['text'] ?? '');
    }

    /** @return list<array<string, mixed>> */
    protected function tombolLayar(User $user): array
    {
        $layar = $this->layar($user);
        $markup = $layar === null ? null : $this->markupOf($layar);

        return $markup === null ? [] : array_merge(...($markup['inline_keyboard'] ?: [[]]));
    }

    protected function adaTombol(User $user, string $awal): bool
    {
        foreach ($this->tombolLayar($user) as $t) {
            if (str_starts_with((string) $t['text'], $awal)) {
                return true;
            }
        }

        return false;
    }

    protected function tombol(User $user, string $awal): string
    {
        foreach ($this->tombolLayar($user) as $t) {
            if (str_starts_with((string) $t['text'], $awal) && isset($t['callback_data'])) {
                return (string) $t['callback_data'];
            }
        }

        $this->fail("Tombol '{$awal}' tidak ada. Teks: ".$this->teks($user));
    }

    protected function jawaban(): ?string
    {
        $teks = null;

        foreach ($this->telegramCalls('answerCallbackQuery') as $r) {
            $teks = $r['text'] ?? null;
        }

        return $teks;
    }

    /** Token AI buatan (meniru baris server) untuk tes lompat langkah / pemalsuan. */
    protected function tokenAi(User $user, AiDraft $draft, string $jenis): string
    {
        $svc = app(BotAksiService::class);

        return $svc->callbackData($svc->terbit($user, (int) $user->telegram_id, $jenis, [
            'subjek_tipe' => BotAksi::SUBJEK_AI_DRAFT, 'subjek_id' => (int) $draft->id, 'expires_at' => $draft->expires_at,
        ]));
    }

    /**
     * Draf yang disisipkan langsung (melewati tool), untuk menguji lapisan eksekutor.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function drafPaksa(User $user, string $tool, string $module, AksiAiDraft $aksi, array $payload, ?int $target = null): AiDraft
    {
        $d = new AiDraft(['module' => $module, 'action' => $aksi, 'target_id' => $target, 'payload' => $payload, 'confidence' => [], 'status' => StatusAiDraft::Pending, 'expires_at' => now()->addMinutes(30)]);
        $d->forceFill(['user_id' => $user->id, 'sppg_id' => $user->isAdmin() ? null : $user->sppg_id, 'tool' => $tool])->save();

        return $d->fresh();
    }

    protected function drafTerakhir(): AiDraft
    {
        return AiDraft::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** Semua keluaran ke Telegram (pesan, edit, toast) sebagai satu teks. */
    protected function keluaranTelegram(): string
    {
        return collect(Http::recorded())->map(fn ($p) => json_encode($p[0]->data()))->implode("\n");
    }
}

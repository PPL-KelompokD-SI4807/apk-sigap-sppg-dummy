<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Telegram\FakesTelegram;

/**
 * Helper tes alur login dua lapis R-03: buka Mini App (initData) -> token pra-login ->
 * masuk/aktivasi/reset -> token 6 digit dari bot (Http::fake) -> token login penuh.
 */
trait AlurLogin
{
    use BuildsTelegramInitData, FakesTelegram;

    protected const HP = 'hp-siti-aaaaaaaaaaaaaaaaaaaaaaaa';

    protected const TABLET = 'tablet-dapur-bbbbbbbbbbbbbbbbbbbb';

    protected const LAPTOP = 'laptop-kantor-cccccccccccccccccccc';

    /** Lupakan pengguna yang di-cache guard dan header Authorization permintaan sebelumnya. */
    protected function lupakanGuard(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withoutToken();
    }

    /** POST /auth/telegram (Mini App dibuka) dengan initData sah milik $user. */
    protected function bukaMiniApp(User $user, ?string $device = self::HP, ?string $bearer = null): TestResponse
    {
        $this->lupakanGuard();
        $request = $bearer === null ? $this : $this->withToken($bearer);

        return $request->postJson('/api/v1/auth/telegram', array_filter([
            'init_data' => $this->makeInitData(['id' => (int) $user->telegram_id]),
            'device_id' => $device,
        ]));
    }

    protected function praLogin(User $user, string $device = self::HP): string
    {
        return (string) $this->bukaMiniApp($user, $device)->assertOk()->json('token');
    }

    /** @param array<string, mixed> $body */
    protected function kirim(string $token, string $url, array $body = [], string $method = 'POST'): TestResponse
    {
        $this->lupakanGuard();

        return $this->withToken($token)->json($method, $url, $body);
    }

    /** Kode 6 digit dari pesan bot terakhir ke chat $chatId. */
    protected function kodeTerakhir(int $chatId): string
    {
        /** @var Request|null $pesan */
        $pesan = $this->messagesTo($chatId)->last();
        $this->assertNotNull($pesan, 'Bot tidak mengirim token.');
        $this->assertSame(1, preg_match('/Token SiGAP SPPG: (\d{6})/', (string) $pesan['text'], $m), (string) $pesan['text']);

        return $m[1];
    }

    /** Alur lengkap masuk + token perangkat baru; mengembalikan token login penuh. */
    protected function loginPenuh(User $user, string $identitas, string $password, string $device = self::HP): string
    {
        $pra = $this->praLogin($user, $device);
        $tantangan = $this->kirim($pra, '/api/v1/auth/masuk', [
            'identitas' => $identitas, 'password' => $password, 'device_id' => $device,
        ])->assertStatus(202)->json('tantangan.id');

        return (string) $this->kirim($pra, '/api/v1/auth/token/verifikasi', [
            'tantangan_id' => $tantangan, 'kode' => $this->kodeTerakhir((int) $user->telegram_id), 'device_id' => $device,
        ])->assertOk()->json('token');
    }
}

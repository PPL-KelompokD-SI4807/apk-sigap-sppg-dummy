<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Llm\LlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** php artisan sigap:llm-uji: hanya balasan atau galat ramah; tidak pernah kunci/URL/body mentah. */
class UjiLlmCommandTest extends TestCase
{
    private const KEY = 'gsk_kunci-fiktif-artisan-jangan-bocor';

    /** PNG 1x1 fiktif. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'ai.provider' => 'openai_compat',
            'ai.openai_compat.base_url' => 'https://llm.test/openai/v1',
            'ai.openai_compat.api_key' => self::KEY,
            'ai.openai_compat.model' => 'openai/gpt-oss-120b',
            'ai.openai_compat.vision_model' => null,
        ]);
        $this->app->forgetInstance(LlmClient::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function berkas(string $isi): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sigap-uji-');
        file_put_contents($path, $isi);
        $this->files[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $args */
    private function jalankan(array $args = []): array
    {
        $code = Artisan::call('sigap:llm-uji', $args);

        return [$code, Artisan::output()];
    }

    private function assertAman(string $output): void
    {
        $this->assertStringNotContainsString(self::KEY, $output);
        $this->assertStringNotContainsString('llm.test', $output);
        $this->assertStringNotContainsString('rahasia', $output);
        $this->assertStringNotContainsString('Bearer', $output);
    }

    public function test_uji_koneksi_mencetak_hanya_balasan(): void
    {
        Http::fake(['llm.test/*' => Http::response([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'siap', 'reasoning' => 'rahasia penalaran'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 40],
        ])]);

        [$code, $output] = $this->jalankan();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Balasan: siap', $output);
        $this->assertStringContainsString('openai_compat', $output);
        $this->assertAman($output);
        Http::assertSent(fn (Request $r) => $r->data()['messages'][1]['content'] === 'balas dengan kata: siap' && ! isset($r->data()['tools']));
    }

    public function test_galat_dicetak_ramah_tanpa_kunci_maupun_body(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => ['message' => 'rahasia: invalid key '.self::KEY]], 401)]);

        [$code, $output] = $this->jalankan();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Gagal:', $output);
        $this->assertStringContainsString('llm:konfigurasi http=401', $output);
        $this->assertAman($output);
    }

    public function test_belum_dikonfigurasi_tidak_mengirim(): void
    {
        config(['ai.openai_compat.base_url' => 'http://llm.test/openai/v1']);
        $this->app->forgetInstance(LlmClient::class);
        Http::fake();

        [$code, $output] = $this->jalankan();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('konfigurasi', $output);
        $this->assertAman($output);
        Http::assertNothingSent();
    }

    public function test_ukur_offline_per_peran(): void
    {
        Http::fake();

        [$code, $output] = $this->jalankan(['--ukur' => true, '--teks' => 'catat belanja sayur 20 kg']);

        $this->assertSame(0, $code);

        foreach (['Admin', 'Kepala SPPG', 'Ahli Gizi', 'Akuntan', 'Staf Gudang', 'Relawan unit Dapur', 'Relawan unit Distribusi', 'Investor'] as $peran) {
            $this->assertStringContainsString($peran, $output);
        }

        $this->assertStringContainsString('Estimasi token (terpilih)', $output);
        $this->assertStringNotContainsString('tidak dapat diukur', $output);
        Http::assertNothingSent();
    }

    public function test_vision_kosong_dilaporkan_tidak_didukung(): void
    {
        Http::fake();

        [$code, $output] = $this->jalankan(['--vision' => $this->berkas(base64_decode(self::PNG))]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Vision tidak didukung', $output);
        Http::assertNothingSent();
    }

    public function test_vision_membaca_berkas_uji(): void
    {
        config(['ai.openai_compat.vision_model' => 'qwen/qwen3.8-27b']);
        $this->app->forgetInstance(LlmClient::class);
        Http::fake(['llm.test/*' => Http::response([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Gambar satu piksel.'], 'finish_reason' => 'stop']],
        ])]);

        [$code, $output] = $this->jalankan(['--vision' => $this->berkas(base64_decode(self::PNG))]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Hasil baca: Gambar satu piksel.', $output);
        $this->assertAman($output);
        Http::assertSent(fn (Request $r) => $r->data()['model'] === 'qwen/qwen3.8-27b');
    }

    public function test_vision_menolak_berkas_bukan_gambar(): void
    {
        config(['ai.openai_compat.vision_model' => 'qwen/qwen3.8-27b']);
        $this->app->forgetInstance(LlmClient::class);
        Http::fake();

        [$code, $output] = $this->jalankan(['--vision' => $this->berkas('<?php echo 1;')]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Format gambar tidak didukung', $output);
        Http::assertNothingSent();
    }

    public function test_stt_groq_mencetak_transkrip(): void
    {
        config(['ai.stt.driver' => 'http', 'ai.stt.url' => 'https://api.groq.com/openai/v1/audio/transcriptions', 'ai.stt.api_key' => self::KEY, 'ai.stt.model' => 'whisper-large-v3-turbo']);
        Http::fake(['api.groq.com/*' => Http::response(['text' => 'uji suara fiktif'])]);
        $ogg = "OggS\x00\x02".str_repeat("\x00", 20)."\x01\x1EOpusHead".str_repeat("\x00", 40);

        [$code, $output] = $this->jalankan(['--stt' => $this->berkas($ogg)]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Transkrip: uji suara fiktif', $output);
        $this->assertStringNotContainsString(self::KEY, $output);
        $this->assertStringNotContainsString('api.groq.com', $output);
    }
}

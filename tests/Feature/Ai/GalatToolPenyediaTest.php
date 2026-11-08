<?php

namespace Tests\Feature\Ai;

use App\Enums\HasilAi;
use App\Enums\JenisInputAi;
use App\Models\AiDraft;
use App\Models\AiInteraction;
use App\Models\BahanBaku;
use App\Models\Menu;
use App\Models\Scopes\SppgScope;
use App\Services\Ai\ActingUser;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\IntentParser;
use App\Services\Ai\Llm\JalurSkema;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\OpenAiCompatibleLlmClient;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use App\Services\Ai\Tools\ArgumentValidator;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\Tools\ToolSelector;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Master\MasterApiTestCase;

/**
 * Galat penyedia saat model membuat tool call (Groq + openai/gpt-oss-120b): Groq memvalidasi argumen tool call
 * terhadap skema yang dikirim dan menolak dengan HTTP 400 `tool_use_failed` bila tidak cocok. Fake HTTP di sini
 * meniru perilaku itu (validasi dengan skema yang benar-benar dikirim), sehingga ketidakcocokan skema terlihat.
 *
 * Skenario utama = pesan uji staging Ahli Gizi: draf menu nasi goreng dengan bahan "ayam 10 gram per porsi";
 * data bahan "Ayam" (kg) dan "Ayam fillet" (kg). Semua data fiktif.
 */
class GalatToolPenyediaTest extends MasterApiTestCase
{
    private const KEY = 'gsk_kunci-fiktif-groq-jangan-bocor';

    private const PESAN = 'Buatkan draf menu nasi goreng, kelompok sasaran siswa, tanggal sajian 2026-11-10, siklus 1, energi 10 kcal, karbo 10 g, lemak 5 g, protein 10 g, serat 5 g, bahan ayam 10 gram per porsi.';

    /** Penanda unik di failed_generation palsu: tidak boleh muncul di log mana pun. */
    private const RAHASIA_GENERASI = 'GENERASI-MENTAH-RAHASIA-7731';

    /** @var list<string> */
    private array $logs = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $entri = [];

    private BahanBaku $ayam;

    private BahanBaku $fillet;

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
            // Seperti staging: pemilihan tool dinamis aktif (auto + openai_compat).
            'ai.tool_selection.mode' => 'auto',
        ]);
        $this->app->forgetInstance(LlmClient::class);
        $this->forgetActor();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
            $this->entri[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });

        $this->ayam = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Ayam', 'satuan' => 'kg']);
        $this->fillet = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Ayam fillet', 'satuan' => 'kg']);
    }

    private function agent(): AiAgentService
    {
        return $this->app->make(AiAgentService::class);
    }

    /**
     * Argumen buat_menu seperti yang dihasilkan model untuk PESAN. Model mengisi semua properti item bahan,
     * termasuk `bahan_baku_id: null` karena hanya tahu nama (pola umum gpt-oss untuk properti opsional).
     *
     * @return array<string, mixed>
     */
    private static function argumenMenu(array $bahan = [['nama' => 'ayam', 'bahan_baku_id' => null, 'jumlah' => 10, 'satuan' => 'gram']], array $timpa = []): array
    {
        return $timpa + [
            'nama_menu' => 'Nasi Goreng',
            'kelompok_sasaran' => 'siswa',
            'tanggal_sajian' => '2026-11-10',
            'siklus' => '1',
            'energi_kkal' => 10,
            'karbohidrat_g' => 10,
            'lemak_g' => 5,
            'protein_g' => 10,
            'serat_g' => 5,
            'bahan' => $bahan,
        ];
    }

    private static function teks(string $content): array
    {
        return [
            'model' => 'openai/gpt-oss-120b',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 20],
        ];
    }

    /**
     * Respons meniru Groq untuk tool call model: argumen divalidasi terhadap skema tool yang DIKIRIM pada permintaan;
     * tidak cocok -> HTTP 400 tool_use_failed (pesan berisi path JSON pointer, failed_generation berisi keluaran mentah).
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function groqToolCall(Request $request, string $name, array $arguments, string $id = 'fc_1'): PromiseInterface
    {
        $tools = collect($request->data()['tools'] ?? [])->keyBy(fn (array $t) => $t['function']['name']);
        $mentah = json_encode(['name' => $name, 'arguments' => $arguments, 'catatan' => self::RAHASIA_GENERASI], JSON_UNESCAPED_UNICODE);

        if (! $tools->has($name)) {
            return Http::response(['error' => [
                'message' => "tool call validation failed: attempted to call tool '{$name}' which was not in request.tools",
                'type' => 'invalid_request_error', 'code' => 'tool_use_failed', 'failed_generation' => $mentah,
            ]], 400);
        }

        $schema = json_decode(json_encode($tools[$name]['function']['parameters']), true);
        $errors = (new ArgumentValidator)->validate($schema, $arguments);

        if ($errors !== []) {
            $paths = array_map(fn (string $e) => '`/'.str_replace('.', '/', strtok($e, ':')).'`: '.self::alasanGroq($e), $errors);

            return Http::response(['error' => [
                'message' => "tool call validation failed: parameters for tool {$name} did not match schema: errors: [".implode(', ', $paths).']',
                'type' => 'invalid_request_error', 'code' => 'tool_use_failed', 'failed_generation' => $mentah,
            ]], 400);
        }

        return Http::response([
            'model' => 'openai/gpt-oss-120b',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE)]],
            ]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 1800, 'completion_tokens' => 120],
        ]);
    }

    private static function alasanGroq(string $error): string
    {
        return match (true) {
            str_contains($error, 'tidak boleh null') => 'expected integer, but got null',
            str_contains($error, 'tipe harus') => 'expected string, but got number',
            default => 'value does not match',
        };
    }

    /** @return list<Request> */
    private function llmRequests(): array
    {
        return collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'llm.test'))->values()->all();
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function galatLlm(): array
    {
        return array_values(array_filter($this->entri, fn (array $e) => $e['message'] === 'ai.llm_galat'));
    }

    private function assertLogTanpaDataSensitif(): void
    {
        $semua = implode("\n", $this->logs);

        $this->assertStringNotContainsString(self::KEY, $semua);
        $this->assertStringNotContainsString(self::RAHASIA_GENERASI, $semua, 'failed_generation mentah tidak dicatat.');
        $this->assertStringNotContainsStringIgnoringCase('nasi goreng', $semua, 'Isi pesan/argumen tidak dicatat.');
        $this->assertStringNotContainsStringIgnoringCase('ayam', $semua, 'Argumen tool tidak dicatat.');
        $this->assertStringNotContainsString('2026-11-10', $semua);
        $this->assertStringNotContainsString('tool call validation failed', $semua, 'Pesan mentah penyedia tidak dicatat.');
        $this->assertStringNotContainsString(self::KEY, (string) json_encode(AiInteraction::query()->get()->map->getAttributes()));
    }

    // ------------------------------------------------------------------ reproduksi + perbaikan skema

    public function test_pesan_staging_menjadi_draf_menu_dengan_ayam_tepat_tanpa_tombol(): void
    {
        $langkah = 0;
        Http::fake(['llm.test/*' => function (Request $request) use (&$langkah) {
            return match ($langkah++) {
                0 => self::groqToolCall($request, 'buat_menu', self::argumenMenu()),
                default => Http::response(self::teks('Draf menu Nasi Goreng sudah disiapkan.')),
            };
        }]);

        $reply = $this->agent()->handle($this->ahliGiziA, AiInput::text(self::PESAN));

        $this->assertFalse($reply->fallback, $reply->text);
        $this->assertCount(1, $reply->drafts, $reply->text);
        $draft = AiDraft::query()->withoutGlobalScopes()->sole();
        $this->assertSame('menu-gizi', $draft->module);
        $this->assertSame($this->sppgA, $draft->sppg_id);
        // Konvensi komposisi: bahan bersatuan massa disimpan dalam GRAM (10 gram = 0,01 kg Ayam per porsi).
        $this->assertSame([['bahan_baku_id' => $this->ayam->id, 'gram_per_porsi' => 10]], $draft->payload['bahan']);
        $this->assertArrayNotHasKey(PilihanReferensiDraf::KUNCI, $draft->payload, '"ayam" cocok persis dengan "Ayam": tanpa tombol pilihan.');
        $this->assertSame('Nasi Goreng', $draft->payload['nama_menu']);
        $this->assertSame('1', $draft->payload['siklus']);
        $this->assertSame(0, Menu::query()->withoutGlobalScope(SppgScope::class)->count(), 'Tool tulis hanya membuat draf.');

        $requests = $this->llmRequests();
        $this->assertCount(2, $requests);
        $ditawarkan = array_map(fn (array $t) => $t['function']['name'], $requests[0]->data()['tools']);
        $this->assertContains('buat_menu', $ditawarkan);
        $this->assertSame(HasilAi::Sukses, AiInteraction::query()->sole()->hasil);
        $this->assertSame([], $this->galatLlm());
    }

    public function test_nama_awalan_ambigu_memunculkan_pilihan(): void
    {
        $langkah = 0;
        Http::fake(['llm.test/*' => function (Request $request) use (&$langkah) {
            return match ($langkah++) {
                0 => self::groqToolCall($request, 'buat_menu', self::argumenMenu([['nama' => 'ay', 'jumlah' => 10, 'satuan' => 'gram']])),
                default => Http::response(self::teks('Silakan pilih bahan lewat tombol.')),
            };
        }]);

        $reply = $this->agent()->handle($this->ahliGiziA, AiInput::text('Buatkan draf menu nasi goreng siswa, bahan ay 10 gram per porsi.'));

        $this->assertCount(1, $reply->drafts, $reply->text);
        $pilihan = AiDraft::query()->withoutGlobalScopes()->sole()->payload[PilihanReferensiDraf::KUNCI];
        $this->assertCount(1, $pilihan);
        $this->assertEqualsCanonicalizing([$this->ayam->id, $this->fillet->id], $pilihan[0]['kandidat']);
    }

    // ------------------------------------------------------------------ tool_use_failed: ulang sekali

    public function test_tool_use_failed_diulang_sekali_dengan_catatan_perbaikan_lalu_draf_dibuat(): void
    {
        $langkah = 0;
        Http::fake(['llm.test/*' => function (Request $request) use (&$langkah) {
            return match ($langkah++) {
                // Percobaan 1: model mengirim siklus sebagai angka (skema: teks) -> Groq 400 tool_use_failed.
                0 => self::groqToolCall($request, 'buat_menu', self::argumenMenu(timpa: ['siklus' => 1])),
                1 => self::groqToolCall($request, 'buat_menu', self::argumenMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']])),
                default => Http::response(self::teks('Draf siap.')),
            };
        }]);

        $reply = $this->agent()->handle($this->ahliGiziA, AiInput::text(self::PESAN));

        $this->assertCount(1, $reply->drafts, $reply->text);
        $this->assertSame([['bahan_baku_id' => $this->ayam->id, 'gram_per_porsi' => 10]], AiDraft::query()->withoutGlobalScopes()->sole()->payload['bahan']);

        $requests = $this->llmRequests();
        $this->assertCount(3, $requests);
        $sistem1 = $requests[0]->data()['messages'][0]['content'];
        $sistem2 = $requests[1]->data()['messages'][0]['content'];
        $this->assertStringNotContainsString('ditolak penyedia', $sistem1);
        $this->assertStringContainsString('ditolak penyedia', $sistem2, 'Percobaan ulang membawa catatan perbaikan di prompt sistem.');
        $this->assertStringContainsString('buat_menu', $sistem2);
        $this->assertStringContainsString('/siklus', $sistem2);
        $this->assertStringNotContainsString(self::RAHASIA_GENERASI, $requests[1]->body(), 'failed_generation tidak dikirim balik.');
        $this->assertSame($requests[0]->data()['messages'][1], $requests[1]->data()['messages'][1], 'Pesan pengguna tidak digandakan.');
        $this->assertCount(2, $requests[1]->data()['messages']);

        $galat = $this->galatLlm();
        $this->assertCount(1, $galat);
        $ctx = $galat[0]['context'];
        $this->assertSame('warning', $galat[0]['level']);
        $this->assertSame(400, $ctx['status']);
        $this->assertSame('tool_use_failed', $ctx['kode_penyedia']);
        $this->assertSame('invalid_request_error', $ctx['tipe_penyedia']);
        $this->assertSame('buat_menu', $ctx['tool']);
        $this->assertSame(['/siklus'], $ctx['jalur']);
        $this->assertTrue($ctx['diulang']);
        $this->assertSame('LlmException', $ctx['kelas']);
        $this->assertIsInt($ctx['estimasi_token']);
        $this->assertGreaterThan(0, $ctx['estimasi_token']);

        $log = AiInteraction::query()->sole();
        $this->assertSame(HasilAi::Sukses, $log->hasil);
        $this->assertSame(1, $log->argumen['ulang_tool_gagal']);
        $this->assertLogTanpaDataSensitif();
    }

    public function test_tool_use_failed_dua_kali_berhenti_dengan_balasan_ramah_tanpa_draf(): void
    {
        Http::fake(['llm.test/*' => fn (Request $request) => self::groqToolCall($request, 'buat_menu', self::argumenMenu(timpa: ['siklus' => 1]))]);

        $reply = $this->agent()->handle($this->ahliGiziA, AiInput::text(self::PESAN));

        $this->assertTrue($reply->fallback);
        $this->assertStringContainsString('belum dapat menyusun', $reply->text);
        $this->assertStringContainsString('formulir', $reply->text);
        $this->assertCount(2, $this->llmRequests(), 'Hanya SATU percobaan ulang.');
        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());

        $log = AiInteraction::query()->sole();
        $this->assertSame(HasilAi::Fallback, $log->hasil);
        $this->assertSame('llm:tool_gagal http=400 (tool_use_failed)', $log->error);
        $this->assertCount(2, $this->galatLlm());
        $this->assertFalse($this->galatLlm()[1]['context']['diulang']);
        $this->assertLogTanpaDataSensitif();
    }

    public function test_tool_tak_ditawarkan_dilaporkan_tanpa_nama_tool_karangan(): void
    {
        Http::fake(['llm.test/*' => fn (Request $request) => self::groqToolCall($request, 'repo_browser_open_ayam', ['q' => 'ayam'])]);

        $this->agent()->handle($this->ahliGiziA, AiInput::text(self::PESAN));

        $ctx = $this->galatLlm()[0]['context'];
        $this->assertSame('tool_use_failed', $ctx['kode_penyedia']);
        $this->assertArrayNotHasKey('tool', $ctx, 'Nama tool karangan model tidak dicatat.');
        $this->assertSame(['tool_tak_ditawarkan'], $ctx['masalah']);
        $this->assertLogTanpaDataSensitif();
    }

    public function test_galat_penyedia_lain_tercatat_aman_dan_tidak_diulang_oleh_agen(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => [
            'message' => 'Internal error while processing: nasi goreng ayam '.self::KEY,
            'type' => 'internal_server_error', 'code' => 'internal_error',
        ]], 503)]);

        $reply = $this->agent()->handle($this->ahliGiziA, AiInput::text(self::PESAN));

        $this->assertStringContainsString('sedang tidak tersedia', $reply->text);
        $this->assertCount(2, $this->llmRequests(), 'Hanya retry bawaan klien untuk 5xx.');
        $ctx = $this->galatLlm()[0]['context'];
        $this->assertSame(503, $ctx['status']);
        $this->assertSame('internal_error', $ctx['kode_penyedia']);
        $this->assertSame('tidak_tersedia', $ctx['kategori']);
        $this->assertLogTanpaDataSensitif();
    }

    public function test_galat_setelah_draf_dibuat_tetap_menawarkan_draf(): void
    {
        $langkah = 0;
        Http::fake(['llm.test/*' => function (Request $request) use (&$langkah) {
            return match ($langkah++) {
                0 => self::groqToolCall($request, 'buat_menu', self::argumenMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']])),
                default => Http::response(['error' => ['message' => 'x', 'type' => 'invalid_request_error', 'code' => 'invalid_request']], 400),
            };
        }]);

        $reply = $this->agent()->handle($this->ahliGiziA, AiInput::text(self::PESAN));

        $this->assertCount(1, $reply->drafts);
        $this->assertStringContainsString('draf', $reply->text);
        $this->assertSame(1, AiDraft::query()->withoutGlobalScopes()->count(), 'Draf tidak digandakan.');
        $this->assertSame(HasilAi::Fallback, AiInteraction::query()->sole()->hasil);
    }

    // ------------------------------------------------------------------ path berisi deret angka (NIK/telepon)

    public function test_path_angka_panjang_dibuang_dari_log_dan_catatan_perbaikan(): void
    {
        $nik = '3201234567890001';
        $langkah = 0;
        Http::fake(['llm.test/*' => function (Request $request) use (&$langkah, $nik) {
            return match ($langkah++) {
                0 => Http::response(['error' => [
                    'message' => "tool call validation failed: parameters for tool buat_menu did not match schema: errors: [`/{$nik}`: not allowed, `/bahan/{$nik}`: expected object, but got string, `/bahan/123/nama`: x, `/siklus`: expected string, but got number, `/bahan/0/nama`: expected string, but got number]",
                    'type' => 'invalid_request_error', 'code' => 'tool_use_failed', 'failed_generation' => self::RAHASIA_GENERASI,
                ]], 400),
                1 => self::groqToolCall($request, 'buat_menu', self::argumenMenu([['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram']])),
                default => Http::response(self::teks('Draf siap.')),
            };
        }]);

        $reply = $this->agent()->handle($this->ahliGiziA, AiInput::text(self::PESAN));

        $this->assertCount(1, $reply->drafts, $reply->text);
        $this->assertSame(['/siklus', '/bahan/0/nama'], $this->galatLlm()[0]['context']['jalur']);
        $sistem2 = $this->llmRequests()[1]->data()['messages'][0]['content'];
        $this->assertStringContainsString('kolom bermasalah: /siklus, /bahan/0/nama', $sistem2);
        $this->assertStringContainsString('jangan kirim kolom di luar skema', $sistem2);
        $this->assertStringNotContainsString('null', (string) strstr($sistem2, 'Catatan sistem'));
        $this->assertStringNotContainsString($nik, $sistem2);
        $this->assertStringNotContainsString('/bahan/123', $sistem2);
        $this->assertStringNotContainsString($nik, implode("\n", $this->logs));
        $this->assertLogTanpaDataSensitif();
    }

    public function test_jalur_skema_menolak_segmen_angka_panjang_dan_segmen_pertama_bukan_properti(): void
    {
        $nama = ['bahan' => true, 'nama' => true, 'siklus' => true];

        $this->assertTrue(JalurSkema::sah('/bahan/49/nama', $nama));
        $this->assertTrue(JalurSkema::sah('/siklus', $nama));
        $this->assertFalse(JalurSkema::sah('/3201234567890001', $nama));
        $this->assertFalse(JalurSkema::sah('/3201234567890001'), 'Tanpa daftar nama pun segmen pertama wajib nama properti.');
        $this->assertFalse(JalurSkema::sah('/bahan/3201234567890001', $nama));
        $this->assertFalse(JalurSkema::sah('/bahan/123/nama', $nama), 'Indeks maks 2 digit (maxItems 50).');
        $this->assertFalse(JalurSkema::sah('/0/nama', $nama));
        $this->assertFalse(JalurSkema::sah('/ktp/0', $nama), 'Bukan properti skema.');

        $catatan = app(IntentParser::class)->catatanToolGagal('buat_menu', ['/081234567890', '/bahan/081234567890', '/siklus']);
        $this->assertStringContainsString('kolom bermasalah: /siklus.', $catatan);
        $this->assertStringNotContainsString('081234567890', $catatan);
    }

    // ------------------------------------------------------------------ ID placeholder tanpa nama

    public function test_id_nol_atau_negatif_tanpa_nama_ditanyakan_bukan_tidak_ditemukan(): void
    {
        foreach ([0, -5, null] as $id) {
            $this->forgetActor();
            $hasil = ActingUser::run($this->ahliGiziA, fn () => app(ToolRegistry::class)->all()['buat_menu']->execute(
                $this->ahliGiziA,
                ['nama_menu' => 'Nasi Goreng', 'kelompok_sasaran' => 'siswa', 'bahan' => [['nama' => null, 'bahan_baku_id' => $id, 'jumlah' => 10, 'satuan' => 'gram']]],
                new ToolContext(null, JenisInputAi::Teks),
            ));

            $pesan = (string) $hasil->message;
            $this->assertSame(ToolResult::INVALID, $hasil->status, json_encode($id));
            $this->assertStringContainsString('sebutkan nama bahannya', $pesan);
            $this->assertStringNotContainsString('tidak ditemukan', $pesan);
            $this->assertStringNotContainsString('buat_bahan_baku', $pesan);
            $this->assertStringNotContainsString('#', $pesan);
        }

        $this->assertSame(0, AiDraft::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ skema tool sah untuk penyedia

    public function test_skema_semua_tool_sah_dan_buat_menu_menerima_nama_saja(): void
    {
        $tools = app(ToolRegistry::class)->all();
        $client = OpenAiCompatibleLlmClient::fromConfig();

        foreach ($tools as $name => $tool) {
            $schema = $tool->parameters();
            $this->assertSame('object', $schema['type'], $name);
            $this->assertSkemaSah($schema, $name);
            // Argumen kosong untuk tool tanpa kolom wajib harus lolos validator yang sama (skema konsisten).
            if (($schema['required'] ?? []) === []) {
                $this->assertSame([], (new ArgumentValidator)->validate($schema, []), $name);
            }
        }

        $kirim = $client->convertTools(ToolSelector::schemas($tools, true));
        $this->assertNotFalse(json_encode($kirim));

        $menu = $tools['buat_menu']->parameters()['properties']['bahan']['items'];
        $validator = new ArgumentValidator;
        foreach ([
            ['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram'],
            ['nama' => 'ayam', 'bahan_baku_id' => null, 'jumlah' => 10, 'satuan' => 'gram'],
            ['bahan_baku_id' => 3, 'jumlah' => 0.5, 'satuan' => 'kg'],
        ] as $item) {
            $this->assertSame([], $validator->validate($menu, $item), json_encode($item));
        }
        $this->assertNotSame([], $validator->validate($menu, ['nama' => 'ayam', 'jumlah' => 10, 'satuan' => 'gram', 'harga' => 1]));
    }

    /** @param array<string, mixed> $schema */
    private function assertSkemaSah(array $schema, string $path): void
    {
        $this->assertSame([], array_diff(array_keys($schema), ArgumentValidator::KEYWORDS), $path.': kata kunci skema tidak didukung');

        foreach ((array) ($schema['type'] ?? []) as $type) {
            $this->assertContains($type, ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'], $path);
        }

        if (isset($schema['required'])) {
            $this->assertTrue(array_is_list($schema['required']), $path);
            $this->assertSame([], array_diff($schema['required'], array_keys((array) ($schema['properties'] ?? []))), $path.': required tanpa properti');
        }

        if (isset($schema['enum'])) {
            $this->assertNotEmpty($schema['enum'], $path);
        }

        foreach ((array) ($schema['properties'] ?? []) as $prop => $child) {
            $this->assertIsArray($child, $path.'.'.$prop);
            $this->assertSkemaSah($child, $path.'.'.$prop);
        }

        if (is_array($schema['items'] ?? null)) {
            $this->assertSkemaSah($schema['items'], $path.'[]');
        }

        if (is_array($schema['additionalProperties'] ?? null)) {
            $this->assertSkemaSah($schema['additionalProperties'], $path.'{}');
        }
    }
}

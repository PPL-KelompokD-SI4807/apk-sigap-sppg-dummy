<?php

namespace Tests\Feature\Ai;

use App\Enums\JenisInputAi;
use App\Models\AiDraft;
use App\Models\BuktiDistribusi;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\RekapHarian;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Catalog\RecordPresenter;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;
use Tests\Feature\Investor\InvestorTestCase;

/**
 * Tinjauan ulang T3 #140 (saran S-B): teks bebas tool katalog yang dapat memuat data pribadi orang lain dan dibaca
 * peran lain (termasuk investor, R keuangan/rekap) kini lewat $personalText: uraian pengeluaran, catatan pencairan,
 * catatan/catatan selisih bukti distribusi, dan catatan rekap harian. NIK/HP (termasuk bersisipan karakter tak
 * terlihat) disamarkan, nama menjadi inisial, dipotong SESUDAH disamarkan; nominal "Rp 1.500.000" dan rentang jam
 * tetap utuh. Semua data contoh fiktif.
 */
class ToolKatalogTeksBebasDisamarkanTest extends InvestorTestCase
{
    /** NIK 16 digit bersisipan zero-width, HP lokal dengan NBSP/ZWJ, HP +62 berspasi, email. */
    private const PRIBADI = "kontak Budi Santoso 0812\u{00A0}3456\u{200D}7890 / +62 812 3456 7890, NIK 3201\u{200B}0123\u{FEFF}4567\u{2060}8901, wali.fiktif@contoh.id";

    private FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
        $this->forgetActor();
    }

    /**
     * Jalankan satu tool lewat Agen AI (FakeLlm) dan kembalikan [konten mentah hasil tool, data JSON terdekode].
     *
     * @param  array<string, mixed>  $argumen
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function lewatAgen(User $user, string $tool, array $argumen = []): array
    {
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
        $this->llm->push(LlmResponse::toolCall($tool, $argumen))->push(LlmResponse::text('Sudah saya ringkas.'));
        $this->forgetActor();

        $this->app->make(AiAgentService::class)->handle($user, AiInput::text('tampilkan datanya'));

        $this->assertArrayHasKey(1, $this->llm->calls, "tool {$tool} harus dijalankan untuk {$user->role->value}");
        $konten = (string) collect($this->llm->calls[1]['messages'])->where('role', 'tool')->last()['content'];
        $this->assertSame(1, preg_match('#^<hasil_tool_([0-9a-f]{12})>\n(.*)\n</hasil_tool_\1>$#s', $konten, $m), $konten);

        $json = json_decode($m[2], true);
        $this->assertSame('ok', $json['status'] ?? null, $konten);
        $this->assertSame(RecordPresenter::NOTE, $json['data']['catatan'] ?? null);

        return [$konten, $json['data']];
    }

    /**
     * Detail satu data (argumen `id`) langsung lewat ToolRegistry::resolveFor (scope peran + SPPG aktif sama dengan
     * jalur agen): argumen `id` termasuk ai.protected_arguments sehingga dibuang sebelum tool dijalankan dari agen.
     *
     * Elemen pertama hanya memuat kolom teks bebas (JSON): kolom lain berisi nilai acak factory (no_referensi,
     * jumlah porsi, nama titik layanan) yang dapat kebetulan memuat "3201"/"0812" dan membuat tes flaky.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function detail(User $user, string $tool, int $id): array
    {
        $this->forgetActor();
        $hasil = ActingUser::run($user, fn () => $this->app->make(ToolRegistry::class)->resolveFor($user, $tool)
            ->execute($user, ['id' => $id], new ToolContext(null, JenisInputAi::Teks)));

        $this->assertSame(ToolResult::OK, $hasil->status, (string) $hasil->message);
        $this->assertSame(RecordPresenter::NOTE, $hasil->data['catatan'] ?? null);

        $teksBebas = array_map(fn (array $baris) => array_intersect_key($baris, array_flip(['catatan', 'catatan_selisih', 'uraian'])), $hasil->data['hasil']);

        return [(string) json_encode($teksBebas, JSON_UNESCAPED_UNICODE), $hasil->data];
    }

    private function assertTanpaNikHp(string $teks): void
    {
        foreach (['3201', '0123', '4567', '8901', '0812', '3456', '7890', '+62', 'wali.fiktif', 'Budi', 'Santoso'] as $pribadi) {
            $this->assertStringNotContainsString($pribadi, $teks, "data pribadi lolos: {$pribadi}");
        }
        $this->assertSame(0, preg_match('/[\x{200B}\x{200D}\x{FEFF}\x{2060}\x{00A0}]/u', $teks), 'karakter tak terlihat harus dibuang');
    }

    private function pengeluaran(int $sppgId, string $uraian): Pengeluaran
    {
        $this->forgetActor();

        return Pengeluaran::factory()->create(['sppg_id' => $sppgId, 'uraian' => $uraian, 'nominal' => 1500000]);
    }

    // ------------------------------------------------------------------ cari_pengeluaran (akuntan + investor)

    public function test_uraian_pengeluaran_nik_dan_hp_disamarkan_nominal_dan_jam_utuh(): void
    {
        $this->pengeluaran($this->sppgA, 'beli beras Rp 1.500.000, antar jam 07.30 - 08.00, '.self::PRIBADI);

        [$konten, $data] = $this->lewatAgen($this->akuntanA, 'cari_pengeluaran');
        $uraian = (string) $data['hasil'][0]['uraian'];

        $this->assertStringStartsWith('beli beras Rp 1.500.000, antar jam 07.30 - 08.00, kontak B. S. '.RecordPresenter::REDACTED, $uraian);
        $this->assertStringEndsWith(', N. '.RecordPresenter::REDACTED.', '.RecordPresenter::REDACTED, $uraian);
        $this->assertSame(1500000, $data['hasil'][0]['nominal']);
        $this->assertTanpaNikHp($konten);
    }

    public function test_investor_mendapat_uraian_tersamar_yang_sama_dan_hanya_sppg_aktif(): void
    {
        $teks = 'beli beras Rp 1.500.000, antar jam 07.30 - 08.00, '.self::PRIBADI;
        $this->pengeluaran($this->sppgA, $teks);
        $this->pengeluaran($this->sppgC, $teks);
        $this->pengeluaran($this->sppgB, 'uraian SPPG B rahasia');

        [, $akuntan] = $this->lewatAgen($this->akuntanA, 'cari_pengeluaran');
        [$konten, $investor] = $this->lewatAgen($this->investorC, 'cari_pengeluaran');

        $this->assertSame(1, $investor['jumlah']);
        $this->assertSame($akuntan['hasil'][0]['uraian'], $investor['hasil'][0]['uraian']);
        $this->assertStringNotContainsString('rahasia', $konten);
        $this->assertTanpaNikHp($konten);
    }

    public function test_uraian_pengeluaran_normal_tetap_terbaca(): void
    {
        foreach (['Beli beras 100 kg Rp 1.500.000', 'Gas elpiji 12 kg', 'Belanja sayur pasar pagi jam 07.30 - 08.00', 'Insentif relawan dapur'] as $uraian) {
            $this->pengeluaran($this->sppgA, $uraian);
        }

        [, $data] = $this->lewatAgen($this->akuntanA, 'cari_pengeluaran');

        $this->assertEqualsCanonicalizing(
            ['Beli beras 100 kg Rp 1.500.000', 'Gas elpiji 12 kg', 'Belanja sayur pasar pagi jam 07.30 - 08.00', 'Insentif relawan dapur'],
            array_column($data['hasil'], 'uraian'),
        );
    }

    public function test_uraian_pengeluaran_dipotong_sesudah_disamarkan(): void
    {
        // Bila dipotong lebih dulu (160 karakter), "32010" (5 digit, di bawah ambang 6) akan lolos utuh.
        $this->pengeluaran($this->sppgA, str_repeat('x', 155).'3201012345678901');

        [, $data] = $this->lewatAgen($this->akuntanA, 'cari_pengeluaran');
        $uraian = (string) $data['hasil'][0]['uraian'];

        $this->assertDoesNotMatchRegularExpression('/\d/', $uraian, $uraian);
        $this->assertLessThanOrEqual(RecordPresenter::MAX_TEXT_PERSONAL + 1, mb_strlen($uraian));
    }

    public function test_draf_pengeluaran_tidak_menampilkan_uraian_di_ringkasan_chat(): void
    {
        $draft = AiDraft::factory()->create([
            'sppg_id' => $this->sppgA, 'user_id' => $this->akuntanA->id, 'module' => 'keuangan',
            'payload' => ['tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Gas elpiji '.self::PRIBADI, 'nominal' => 150000],
        ]);
        $tool = $this->app->make(ToolRegistry::class)->all()['buat_pengeluaran'];

        $ringkasan = ActingUser::run($this->akuntanA, fn () => $tool->ringkasan($this->akuntanA, $draft));

        $semua = implode("\n", $ringkasan->baris);
        $this->assertStringContainsString('(tidak ditampilkan di chat', $semua);
        $this->assertTanpaNikHp($semua);
    }

    // ------------------------------------------------------------------ cari_pencairan_dana / cari_rekap_harian

    public function test_catatan_pencairan_dana_disamarkan_untuk_akuntan_dan_investor(): void
    {
        $this->forgetActor();
        $a = PencairanDana::factory()->create(['sppg_id' => $this->sppgA, 'catatan' => 'pencairan termin kedua Rp 1.500.000, '.self::PRIBADI]);
        $c = PencairanDana::factory()->create(['sppg_id' => $this->sppgC, 'catatan' => 'pencairan termin kedua Rp 1.500.000, '.self::PRIBADI]);

        [$konten, $data] = $this->detail($this->akuntanA, 'cari_pencairan_dana', $a->id);
        $catatan = (string) $data['hasil'][0]['catatan'];
        $this->assertStringStartsWith('pencairan termin kedua Rp 1.500.000, kontak B. S. '.RecordPresenter::REDACTED, $catatan);
        $this->assertTanpaNikHp($konten);

        [$konten, $data] = $this->detail($this->investorC, 'cari_pencairan_dana', $c->id);
        $this->assertSame($catatan, $data['hasil'][0]['catatan']);
        $this->assertTanpaNikHp($konten);
    }

    public function test_pencairan_sppg_lain_tidak_tersaji_lewat_id(): void
    {
        $this->forgetActor();
        $a = PencairanDana::factory()->create(['sppg_id' => $this->sppgA, 'catatan' => 'catatan SPPG A rahasia']);

        [$konten, $data] = $this->detail($this->investorC, 'cari_pencairan_dana', $a->id);

        $this->assertSame([], $data['hasil']);
        $this->assertStringNotContainsString('rahasia', $konten);
    }

    public function test_catatan_rekap_harian_disamarkan_untuk_kepala_dan_investor(): void
    {
        $this->forgetActor();
        $a = RekapHarian::factory()->create(['sppg_id' => $this->sppgA, 'catatan' => 'distribusi lancar jam 07.30 - 08.00, '.self::PRIBADI]);
        $c = RekapHarian::factory()->create(['sppg_id' => $this->sppgC, 'catatan' => 'distribusi lancar jam 07.30 - 08.00, '.self::PRIBADI]);

        [$konten, $data] = $this->detail($this->kepalaA, 'cari_rekap_harian', $a->id);
        $catatan = (string) $data['hasil'][0]['catatan'];
        $this->assertStringStartsWith('distribusi lancar jam 07.30 - 08.00, kontak B. S. '.RecordPresenter::REDACTED, $catatan);
        $this->assertSame(1200, $data['hasil'][0]['ringkasan']['target_porsi'], 'ringkasan angka agregat tidak berubah');
        $this->assertTanpaNikHp($konten);

        [$konten, $data] = $this->detail($this->investorC, 'cari_rekap_harian', $c->id);
        $this->assertSame($catatan, $data['hasil'][0]['catatan']);
        $this->assertTanpaNikHp($konten);
    }

    // ------------------------------------------------------------------ cari_bukti_distribusi

    public function test_catatan_bukti_distribusi_disamarkan(): void
    {
        $this->forgetActor();
        $bukti = BuktiDistribusi::factory()->create([
            'sppg_id' => $this->sppgA,
            'catatan' => 'Diterima Ibu Sartika jam 07.30 - 08.00, '.self::PRIBADI,
            'catatan_selisih' => 'kurang 5 porsi, hubungi 0812 - 3456 - 7890 atau NIK 3201.0123.4567.8901',
        ]);

        [$konten, $data] = $this->detail($this->kepalaA, 'cari_bukti_distribusi', $bukti->id);
        $hasil = $data['hasil'][0];

        $this->assertStringStartsWith('Diterima Ibu S. jam 07.30 - 08.00, kontak B. S. '.RecordPresenter::REDACTED, (string) $hasil['catatan']);
        $this->assertSame('kurang 5 porsi, hubungi '.RecordPresenter::REDACTED.' atau N. '.RecordPresenter::REDACTED, $hasil['catatan_selisih']);
        $this->assertStringNotContainsString('Sartika', $konten);
        $this->assertTanpaNikHp($konten);
    }

    public function test_catatan_bukti_distribusi_normal_tetap_terbaca(): void
    {
        $this->forgetActor();
        $bukti = BuktiDistribusi::factory()->create([
            'sppg_id' => $this->sppgA,
            'catatan' => 'Diterima lengkap, wadah kembali 40 kotak',
            'catatan_selisih' => 'Selisih 2 porsi karena tumpah',
        ]);

        [, $data] = $this->detail($this->akuntanA, 'cari_bukti_distribusi', $bukti->id);

        $this->assertSame('Diterima lengkap, wadah kembali 40 kotak', $data['hasil'][0]['catatan']);
        $this->assertSame('Selisih 2 porsi karena tumpah', $data['hasil'][0]['catatan_selisih']);
    }
}

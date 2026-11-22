<?php

namespace Tests\Feature\Ai;

use App\Models\FeedbackKeluhan;
use App\Models\PenerimaManfaat;
use App\Models\Pengemudi;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Catalog\RecordPresenter;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use Tests\Feature\Investor\InvestorTestCase;

/**
 * Issue #175 (temuan kasus 61): penyamaran ke LLM tidak lagi menyingkat setiap kata berhuruf kapital pada teks bebas
 * ("Kemasan Uji Bocor" tetap utuh), tetapi nomor HP/NIK tetap tersamar, nama orang terdaftar dan nama sesudah
 * sapaan tetap inisial, dan nilai kolom data orang (penerima manfaat, pengemudi) tetap inisial. Data contoh fiktif.
 */
class PenyamaranNamaKontekstualTest extends InvestorTestCase
{
    /**
     * Jalankan satu tool lewat Agen AI (FakeLlm) dan kembalikan [isi pesan tool, seluruh panggilan LLM sebagai JSON, data].
     *
     * @param  array<string, mixed>  $argumen
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function lewatAgen(User $user, string $tool, array $argumen = []): array
    {
        $llm = new FakeLlmClient([LlmResponse::toolCall($tool, $argumen), LlmResponse::text('Sudah saya ringkas.')]);
        $this->app->instance(LlmClient::class, $llm);
        $this->forgetActor();

        $this->app->make(AiAgentService::class)->handle($user, AiInput::text('tampilkan datanya'));

        $this->assertArrayHasKey(1, $llm->calls, "tool {$tool} harus dijalankan");
        $konten = (string) collect($llm->calls[1]['messages'])->where('role', 'tool')->last()['content'];
        $this->assertSame(1, preg_match('#^<hasil_tool_([0-9a-f]{12})>\n(.*)\n</hasil_tool_\1>$#s', $konten, $m), $konten);
        $json = json_decode($m[2], true);
        $this->assertSame('ok', $json['status'] ?? null, $konten);

        return [$m[2], (string) json_encode($llm->calls, JSON_UNESCAPED_UNICODE), $json['data']];
    }

    public function test_deskripsi_keluhan_kata_kapital_utuh_dan_nomor_hp_tersamar(): void
    {
        $this->forgetActor();
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Kemasan Uji Bocor, hubungi 081234567890']);

        [$konten, $semua, $data] = $this->lewatAgen($this->kepalaA, 'cari_keluhan');

        $this->assertSame('Kemasan Uji Bocor, hubungi '.RecordPresenter::REDACTED, $data['hasil'][0]['deskripsi']);
        $this->assertStringNotContainsString('K. U. B.', $konten);
        foreach (['081234567890', '0812', '34567890'] as $nomor) {
            $this->assertStringNotContainsString($nomor, $semua, $nomor);
        }
    }

    public function test_nama_terdaftar_dan_nama_sesudah_sapaan_di_teks_bebas_tetap_inisial(): void
    {
        $this->forgetActor();
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Rizky Pratama']);
        FeedbackKeluhan::factory()->create([
            'sppg_id' => $this->sppgA,
            'deskripsi' => 'Kemasan Uji Bocor, Rizky Pratama mual, lapor ke Bu Sartika, NIK 3201012345678901',
        ]);

        [, $semua, $data] = $this->lewatAgen($this->kepalaA, 'cari_keluhan');

        $this->assertSame('Kemasan Uji Bocor, R. P. mual, lapor ke Bu S., NIK '.RecordPresenter::REDACTED, $data['hasil'][0]['deskripsi']);
        foreach (['Rizky', 'Pratama', 'Sartika', '3201012345678901', '3201'] as $pribadi) {
            $this->assertStringNotContainsString($pribadi, $semua, $pribadi);
        }
    }

    public function test_nama_akun_dan_pic_data_uji_tidak_membuat_kata_umum_disingkat(): void
    {
        // Revisi #175 (staging): nama data uji/demo memuat kata umum ("Uji", "Dapur", "Pusat", "Satu") dan label dalam
        // kurung; kata-kata itu tidak boleh masuk kamus nama, sedangkan nama asli terdaftar tetap menjadi inisial.
        $this->forgetActor();
        TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'pic_nama' => 'PIC Satu (Uji)']);
        User::factory()->create(['sppg_id' => $this->sppgA, 'name' => 'Relawan Dapur 1 (Demo)']);
        User::factory()->create(['sppg_id' => $this->sppgA, 'name' => 'Admin Pusat (Demo)']);
        Pengemudi::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Pengemudi Satu (Uji)']);
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Penerima Uji 1-01 (Uji)']);
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Siti Rahma']);
        FeedbackKeluhan::factory()->create([
            'sppg_id' => $this->sppgA,
            'deskripsi' => 'Kemasan Uji Bocor, hubungi 081234567890. Siti Rahma pusing, Tim Dapur Pusat sudah cek Satu per Satu (Demo)',
        ]);

        [, $semua, $data] = $this->lewatAgen($this->kepalaA, 'cari_keluhan');

        $this->assertSame(
            'Kemasan Uji Bocor, hubungi '.RecordPresenter::REDACTED.'. S. R. pusing, Tim Dapur Pusat sudah cek Satu per Satu (Demo)',
            $data['hasil'][0]['deskripsi'],
        );
        foreach (['081234567890', '0812', 'Siti', 'Rahma'] as $pribadi) {
            $this->assertStringNotContainsString($pribadi, $semua, $pribadi);
        }
    }

    public function test_nilai_kolom_nama_orang_tetap_inisial(): void
    {
        $this->forgetActor();
        PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Kemasan Uji Bocor']);
        Pengemudi::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Joko Susilo']);

        [, $semuaPenerima, $penerima] = $this->lewatAgen($this->kepalaA, 'cari_penerima_manfaat');
        [, $semuaPengemudi, $pengemudi] = $this->lewatAgen($this->kepalaA, 'cari_pengemudi');

        // Kolom nama penerima manfaat selalu inisial, apa pun isinya (penyamaran kolom orang tidak bergantung kamus).
        $this->assertSame('K.U.B.', $penerima['hasil'][0]['nama']);
        $this->assertStringNotContainsString('Kemasan', $semuaPenerima);
        $this->assertSame('J.S.', $pengemudi['hasil'][0]['nama']);
        $this->assertStringNotContainsString('Joko', $semuaPengemudi);
        $this->assertStringNotContainsString('Susilo', $semuaPengemudi);
    }
}

<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\StatusKirim;
use App\Events\KeluhanKritis;
use App\Events\PerjalananDimulai;
use App\Events\RekapHarianDibuat;
use App\Models\BotAksi;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\NotifikasiPerjalanan;
use App\Models\PemeriksaanMutu;
use App\Models\RekapHarian;
use App\Models\Scopes\SppgScope;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Telegram\Bot\BotAksiService;
use App\Services\Telegram\Bot\BotAksiTulisService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Dasar tes aksi tulis berkonfirmasi Bot (R-02 PR-3). Data fiktif per SPPG; teks keluhan memuat penanda privasi
 * (nama + telepon fiktif) yang tidak boleh pernah tampil di layar konfirmasi/hasil, log, atau audit.
 * Event domain yang memicu notifikasi di-fake agar jumlah efek (kirim rekap, perjalanan dimulai) dapat dihitung.
 */
abstract class AksiTulisTestCase extends LayarBacaTestCase
{
    protected const TEKS_KELUHAN = 'Anak muntah, hubungi Budi Santoso 081234567890 RAHASIA-KELUHAN';

    protected string $kemarin;

    /** false = event domain memakai listener asli (jalur notifikasi sungguhan). */
    protected bool $fakeEventDomain = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kemarin = now(config('operasional.timezone', 'Asia/Jakarta'))->subDay()->toDateString();
        if ($this->fakeEventDomain) {
            Event::fake([RekapHarianDibuat::class, PerjalananDimulai::class, KeluhanKritis::class]);
        }
    }

    // ------------------------------------------------------------------ data uji

    protected function rekap(int $sppgId, ?string $tanggal = null): RekapHarian
    {
        $this->forgetActor();

        return RekapHarian::factory()->create([
            'sppg_id' => $sppgId, 'tanggal' => $tanggal ?? $this->hari, 'status_kirim' => StatusKirim::Terkirim, 'dikirim_pada' => now()->subHour(),
        ]);
    }

    protected function keluhan(int $sppgId, string $status = 'baru'): FeedbackKeluhan
    {
        $this->forgetActor();
        $titik = TitikLayanan::factory()->create(['sppg_id' => $sppgId, 'nama' => 'SD Keluhan Uji', 'pic_nama' => 'Siti Rahma']);

        return FeedbackKeluhan::factory()->create([
            'sppg_id' => $sppgId, 'titik_layanan_id' => $titik->id, 'kategori' => 'rasa', 'prioritas' => 'sedang', 'status' => $status,
            'deskripsi' => self::TEKS_KELUHAN, 'tindak_lanjut' => $status === 'baru' ? null : 'Tindak lanjut RAHASIA-TL',
        ]);
    }

    /** Jadwal distribusi HARI INI berstatus siap, batch dengan hasil mutu Layak (atau tidak). */
    protected function jadwalSiap(int $sppgId, array $titik = [], bool $layak = true, ?string $tanggal = null): JadwalDistribusi
    {
        $this->forgetActor();
        $tanggal ??= $this->hari;
        $produksi = JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'tanggal_produksi' => $tanggal]);
        $tl = TitikLayanan::factory()->create(array_merge(['sppg_id' => $sppgId, 'nama' => 'SD Tujuan Uji', 'jarak_km' => 5, 'pic_nama' => 'Siti Rahma', 'pic_telepon' => '0812-0000-9999'], $titik));
        $jadwal = JadwalDistribusi::factory()->siap()->create([
            'sppg_id' => $sppgId, 'jadwal_produksi_id' => $produksi->id, 'titik_layanan_id' => $tl->id, 'tanggal_distribusi' => $tanggal,
        ]);
        PemeriksaanMutu::factory()->{$layak ? 'layak' : 'tidakLayak'}()->create(['sppg_id' => $sppgId, 'jadwal_produksi_id' => $produksi->id]);

        return $jadwal;
    }

    // ------------------------------------------------------------------ alur

    /** Buka layar baca $layar lalu tekan tombol aksi berlabel awal $label (layar konfirmasi). Mengembalikan data tombol aksi. */
    protected function keKonfirmasi(User $user, string $layar, string $label): string
    {
        $this->assertNotNull($this->buka($user, $layar), "{$layar} harus terbuka. Jawaban: ".$this->jawabanTerakhir());
        $aksi = $this->dataTombol($user, $label);
        $this->tekan($user, $aksi);
        $this->assertStringContainsString('<b>Konfirmasi:', $this->teksLayar($user), 'Layar konfirmasi. Jawaban: '.$this->jawabanTerakhir());

        return $aksi;
    }

    protected function ya(User $user): string
    {
        return $this->dataTombol($user, BotAksiTulisService::LABEL_YA);
    }

    protected function batal(User $user): string
    {
        return $this->dataTombol($user, BotAksiTulisService::LABEL_BATAL);
    }

    /**
     * Token aksi tulis langsung (meniru baris yang diterbitkan server), untuk tes hak/IDOR/sesi.
     *
     * @param  array<string, mixed>  $opsi
     */
    protected function token(User $user, string $jenis, array $opsi, ?string $grup = null): string
    {
        $svc = app(BotAksiService::class);

        return $svc->callbackData($svc->terbit($user, (int) $user->telegram_id, $jenis, $opsi, $grup));
    }

    /** @return array{sppg_id: int, subjek_tipe: string, subjek_id: int, subjek_versi: int} */
    protected function opsi(int $sppgId, string $subjekTipe, int $id, int $versi = 1): array
    {
        return ['sppg_id' => $sppgId, 'subjek_tipe' => $subjekTipe, 'subjek_id' => $id, 'subjek_versi' => $versi];
    }

    /** Sidik keadaan yang SAMA dengan server (untuk token buatan yang lolos pemeriksaan versi). */
    protected function versiAsli(string $aksi, Model $subjek): int
    {
        $svc = app(BotAksiTulisService::class);

        return (fn (string $a, Model $m): int => $this->versi($a, $m))->call($svc, $aksi, $subjek->fresh());
    }

    protected function rekapRaw(int $id): RekapHarian
    {
        return RekapHarian::query()->withoutGlobalScope(SppgScope::class)->findOrFail($id);
    }

    protected function keluhanRaw(int $id): FeedbackKeluhan
    {
        return FeedbackKeluhan::query()->withoutGlobalScope(SppgScope::class)->findOrFail($id);
    }

    protected function jumlahPerjalanan(int $jadwalId): int
    {
        return NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)->where('jadwal_distribusi_id', $jadwalId)->count();
    }

    protected function statusToken(string $data): ?string
    {
        $kode = explode('.', $data)[1] ?? '';

        return BotAksi::query()->where('kode', $kode)->value('status');
    }

    /** Semua pesan/edit bot dan log tidak memuat teks keluhan, nama, atau telepon fiktif. */
    protected function assertTanpaBocor(): void
    {
        $keluaran = collect(['sendMessage', 'editMessageText', 'answerCallbackQuery'])
            ->flatMap(fn (string $m) => $this->telegramCalls($m))
            ->map(fn ($r) => json_encode($r->data()))->implode("\n");

        foreach (['RAHASIA-KELUHAN', 'RAHASIA-TL', '081234567890', 'Budi Santoso', 'Siti Rahma', '0812-0000-9999'] as $t) {
            $this->assertStringNotContainsString($t, $keluaran, "Keluaran bot bocor '{$t}'");
            $this->assertStringNotContainsString($t, json_encode($this->logBot), "Log bocor '{$t}'");
        }
    }
}

<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusKirim;
use App\Events\PerjalananDimulai;
use App\Events\RekapHarianDibuat;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\FeedbackKeluhan;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Keluhan\KeluhanService;
use App\Services\Telegram\Bot\BotAksiTulisService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavigasi;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Keamanan aksi tulis Bot (R-02 PR-3): hak dicek ulang tiap klik, IDOR lintas SPPG, replay, tekan ganda, perubahan
 * peran/penempatan/unit/sesi di antara render dan klik, token palsu/milik orang lain/kedaluwarsa, keadaan basi (409),
 * matriks tombol per peran, efek samping tak terduga, dan galat tanpa bocor isi.
 */
class BotAksiTulisKeamananTest extends AksiTulisTestCase
{
    /** @return array{rekap: int, keluhan: int, jadwal: int} */
    private function dataA(): array
    {
        return [
            'rekap' => $this->rekap($this->sppgA)->id,
            'keluhan' => $this->keluhan($this->sppgA)->id,
            'jadwal' => $this->jadwalSiap($this->sppgA)->id,
        ];
    }

    /** Tidak ada efek bisnis apa pun. */
    private function assertTanpaEfek(array $d): void
    {
        Event::assertNotDispatched(RekapHarianDibuat::class);
        Event::assertNotDispatched(PerjalananDimulai::class);
        $this->assertSame(StatusKirim::Terkirim, $this->rekapRaw($d['rekap'])->status_kirim);
        $this->assertSame('baru', $this->keluhanRaw($d['keluhan'])->status->value);
        $this->assertSame(0, $this->jumlahPerjalanan($d['jadwal']));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('sumber', 'bot')->count());
    }

    /** Buka ketiga layar konfirmasi kepala A; mengembalikan token "Ya" per aksi. @return array<string, string> */
    private function tigaKonfirmasi(User $user): array
    {
        $ya = [];

        foreach (['rk' => 'Kirim ulang rekap', 'kl' => 'Proses keluhan', 'mp' => 'Berangkat:'] as $layar => $label) {
            $this->keKonfirmasi($user, $layar, $label);
            $ya[$layar] = $this->ya($user);
        }

        return $ya;
    }

    // ------------------------------------------------------------------ IDOR

    public function test_idor_subjek_sppg_lain_ditolak_tanpa_efek(): void
    {
        $a = $this->dataA();
        $b = ['rekap' => $this->rekap($this->sppgB)->id, 'keluhan' => $this->keluhan($this->sppgB)->id, 'jadwal' => $this->jadwalSiap($this->sppgB)->id];
        $this->sesiPenuh($this->kepalaA);

        foreach ([
            [BotAksi::JENIS_KIRIM_ULANG_REKAP, BotAksi::SUBJEK_REKAP, $b['rekap']],
            [BotAksi::JENIS_TINDAK_KELUHAN, BotAksi::SUBJEK_KELUHAN, $b['keluhan']],
            [BotAksi::JENIS_PICU_PERJALANAN, BotAksi::SUBJEK_JADWAL_DISTRIBUSI, $b['jadwal']],
            [BotAksi::JENIS_YA_KIRIM_ULANG_REKAP, BotAksi::SUBJEK_REKAP, $b['rekap']],
            [BotAksi::JENIS_YA_TINDAK_KELUHAN, BotAksi::SUBJEK_KELUHAN, $b['keluhan']],
            [BotAksi::JENIS_YA_PICU_PERJALANAN, BotAksi::SUBJEK_JADWAL_DISTRIBUSI, $b['jadwal']],
        ] as [$jenis, $tipe, $id]) {
            // Baris berisi subjek SPPG B dengan sppg_id A, dan baris dengan sppg_id B: keduanya ditolak.
            foreach ([$this->sppgA, $this->sppgB] as $sppg) {
                $data = $this->token($this->kepalaA, $jenis, $this->opsi($sppg, $tipe, $id));
                $this->tekan($this->kepalaA, $data);
                $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir(), "{$jenis}/{$sppg}");
                $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $this->statusToken($data), 'Ditolak hak: token kedaluwarsa (bukan dipakai), tanpa efek');
                $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_HAK, $this->teksLayar($this->kepalaA), 'Layar asal dinetralkan');
            }
        }

        $this->assertTanpaEfek($a);
        $this->assertTanpaEfek($b);
    }

    public function test_subjek_jenis_tidak_cocok_ditolak(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);

        // Token aksi rekap yang menunjuk id keluhan (subjek_tipe salah).
        $this->tekan($this->kepalaA, $this->token($this->kepalaA, BotAksi::JENIS_YA_KIRIM_ULANG_REKAP, $this->opsi($this->sppgA, BotAksi::SUBJEK_KELUHAN, $a['keluhan'])));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());
        $this->assertTanpaEfek($a);
    }

    // ------------------------------------------------------------------ replay, tekan ganda, grup

    public function test_replay_dan_tekan_ganda_tidak_menggandakan_efek(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);
        $ya = $this->tigaKonfirmasi($this->kepalaA);

        foreach ($ya as $data) {
            $this->tekan($this->kepalaA, $data);
            $this->tekan($this->kepalaA, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        }

        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);
        Event::assertDispatchedTimes(PerjalananDimulai::class, 1);
        $this->assertSame(1, $this->jumlahPerjalanan($a['jadwal']));
        $this->assertSame(3, AuditLog::query()->withoutGlobalScopes()->where('sumber', 'bot')->count());
    }

    public function test_tombol_aksi_sekali_pakai_dan_ya_batal_satu_grup(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);

        $aksi = $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan');
        $ya = $this->ya($this->kepalaA);
        $batal = $this->batal($this->kepalaA);

        // Tombol aksi yang sudah membuka konfirmasi tidak dapat membuka konfirmasi kedua.
        $this->tekan($this->kepalaA, $aksi);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());

        $this->tekan($this->kepalaA, $ya);
        $this->tekan($this->kepalaA, $batal);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir(), 'Batal dari layar yang sama mati setelah Ya');
        $this->assertSame('diproses', $this->keluhanRaw($a['keluhan'])->status->value);
    }

    public function test_dua_layar_konfirmasi_terbuka_hanya_satu_efek(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);

        $yaSatu = $this->tigaKonfirmasi($this->kepalaA);
        $yaDua = $this->tigaKonfirmasi($this->kepalaA);

        foreach (['rk', 'kl', 'mp'] as $layar) {
            $this->tekan($this->kepalaA, $yaSatu[$layar]);
            $this->tekan($this->kepalaA, $yaDua[$layar]);
            $this->assertStringContainsString(BotAksiTulisService::PESAN_BERUBAH, $this->teksLayar($this->kepalaA), "{$layar}: layar kedua basi");
        }

        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);
        $this->assertSame(1, $this->jumlahPerjalanan($a['jadwal']));
    }

    // ------------------------------------------------------------------ hak berubah di antara render dan klik

    /** @return iterable<string, array{string, string}> */
    public static function perubahan(): iterable
    {
        foreach (['peran', 'nonaktif', 'pindah_sppg', 'sesi_dicabut', 'sesi_kedaluwarsa'] as $ubah) {
            foreach (['aksi', 'ya'] as $tahap) {
                yield "{$ubah} x {$tahap}" => [$ubah, $tahap];
            }
        }
    }

    #[DataProvider('perubahan')]
    public function test_hak_dicek_ulang_tiap_klik(string $ubah, string $tahap): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);
        $token = [];

        foreach (['rk' => 'Kirim ulang rekap', 'kl' => 'Proses keluhan', 'mp' => 'Berangkat:'] as $layar => $label) {
            if ($tahap === 'aksi') {
                $this->buka($this->kepalaA, $layar);
                $token[] = $this->dataTombol($this->kepalaA, $label);
            } else {
                $this->keKonfirmasi($this->kepalaA, $layar, $label);
                $token[] = $this->ya($this->kepalaA);
            }
        }

        match ($ubah) {
            'peran' => DB::table('users')->where('id', $this->kepalaA->id)->update(['role' => Role::Akuntan->value]),
            'nonaktif' => DB::table('users')->where('id', $this->kepalaA->id)->update(['is_active' => false]),
            'pindah_sppg' => DB::table('users')->where('id', $this->kepalaA->id)->update(['sppg_id' => $this->sppgB]),
            'sesi_dicabut' => app(SesiService::class)->keluarSemua($this->kepalaA),
            'sesi_kedaluwarsa' => $this->kepalaA->tokens()->update(['expires_at' => now()->subMinute()]),
        };

        foreach ($token as $data) {
            $this->tekan($this->kepalaA, $data);
            $harap = match ($ubah) {
                'nonaktif' => BotCallbackHandler::TIDAK_BERLAKU,
                'sesi_dicabut', 'sesi_kedaluwarsa' => BotCallbackHandler::PERLU_MASUK_SINGKAT,
                default => BotCallbackHandler::TIDAK_BERHAK,
            };
            $this->assertSame($harap, $this->jawabanTerakhir(), "{$ubah}/{$tahap}");
            // Tanpa sesi / nonaktif: token tidak dihabiskan. Ditolak hak (peran, penempatan): token + grup kedaluwarsa.
            $status = in_array($ubah, ['peran', 'pindah_sppg'], true) ? BotAksi::STATUS_KEDALUWARSA : BotAksi::STATUS_AKTIF;
            $this->assertSame($status, $this->statusToken($data), "{$ubah}/{$tahap}: status token");

            if (str_starts_with($ubah, 'sesi')) {
                $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->messagesTo((int) $this->kepalaA->telegram_id)->last()['text']);
            }
        }

        $this->assertTanpaEfek($a);
    }

    public function test_relawan_unit_dicabut_ditolak(): void
    {
        $a = $this->dataA();
        $kurir = $this->relawanDistribusi();
        $this->sesiPenuh($kurir);
        $this->keKonfirmasi($kurir, 'mp', 'Berangkat:');
        $ya = $this->ya($kurir);

        DB::table('users')->where('id', $kurir->id)->update(['unit' => RelawanUnit::Dapur->value]);
        $this->tekan($kurir, $ya);

        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());
        $this->assertTanpaEfek($a);
    }

    /** E2-6: klik ditolak hak mengedaluwarsakan Ya DAN Batal satu layar; hak dipulihkan pun token lama tidak hidup lagi. */
    public function test_ditolak_hak_mengedaluwarsakan_grup_ya_dan_batal(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan');
        $ya = $this->ya($this->kepalaA);
        $batal = $this->batal($this->kepalaA);

        DB::table('users')->where('id', $this->kepalaA->id)->update(['role' => Role::Akuntan->value]);
        $this->tekan($this->kepalaA, $ya);
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir());
        $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $this->statusToken($ya));
        $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $this->statusToken($batal));

        DB::table('users')->where('id', $this->kepalaA->id)->update(['role' => Role::KepalaSppg->value]);

        foreach ([$ya, $batal] as $data) {
            $this->tekan($this->kepalaA, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        }

        $this->assertTanpaEfek($a);
    }

    // ------------------------------------------------------------------ token palsu / orang lain / kedaluwarsa

    public function test_token_palsu_milik_orang_lain_dan_kedaluwarsa_ditolak_seragam(): void
    {
        $a = $this->dataA();
        $kepalaA2 = $this->userOf(Role::KepalaSppg, $this->sppgA);
        $this->sesiPenuh($this->kepalaA, $kepalaA2, $this->kepalaB);
        $ya = $this->tigaKonfirmasi($this->kepalaA);

        foreach ($ya as $data) {
            // Satu karakter MAC diubah.
            $palsu = substr($data, 0, -1).(str_ends_with($data, 'A') ? 'B' : 'A');
            $this->tekan($this->kepalaA, $palsu);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());

            // Kepala lain (SPPG sama maupun beda) menekan token milik kepala A.
            $this->tekan($kepalaA2, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
            $this->tekan($this->kepalaB, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());

            // Chat lain (grup) dengan pengirim yang sama: hanya indikator tunggu dihentikan, tanpa teks, tanpa pesan/edit.
            $jawabSebelum = $this->telegramCalls('answerCallbackQuery')->count();
            $this->tekan($this->kepalaA, $data, -100555, chatType: 'group');
            $this->assertCount($jawabSebelum + 1, $this->telegramCalls('answerCallbackQuery'));
            $this->assertNull($this->jawabanTerakhir(), 'Ketukan grup dijawab seragam tanpa teks');
            $this->assertSame(BotAksi::STATUS_AKTIF, $this->statusToken($data));
        }

        $this->assertCount(0, $this->messagesTo(-100555));
        $this->assertSame(0, $this->telegramCalls('editMessageText')->filter(fn ($r) => (string) $r['chat_id'] === '-100555')->count());

        BotAksi::query()->where('status', BotAksi::STATUS_AKTIF)->update(['expires_at' => now()->subSecond()]);

        foreach ($ya as $data) {
            $this->tekan($this->kepalaA, $data);
            $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        }

        $this->assertTanpaEfek($a);
    }

    // ------------------------------------------------------------------ keadaan basi (409)

    public function test_keadaan_berubah_antara_render_dan_klik_data_sudah_berubah(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);

        // Langkah 1: keluhan diproses orang lain lewat Mini App setelah layar dirender.
        $this->buka($this->kepalaA, 'kl');
        $aksi = $this->dataTombol($this->kepalaA, 'Proses keluhan');
        $this->travel(1)->seconds();
        app(KeluhanService::class)->perbarui($this->ahliGiziA, FeedbackKeluhan::query()->withoutGlobalScopes()->find($a['keluhan']), ['status' => 'diproses']);

        $this->tekan($this->kepalaA, $aksi);
        $this->assertSame('Data sudah berubah.', $this->jawabanTerakhir());
        $this->assertStringContainsString(BotAksiTulisService::PESAN_BERUBAH, $this->teksLayar($this->kepalaA));
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Muat ulang'));
        $this->assertSame(BotAksi::STATUS_SELESAI, $this->statusToken($aksi), 'Token basi ditutup');
        $this->assertSame('diproses', $this->keluhanRaw($a['keluhan'])->status->value);
        $this->assertNull($this->keluhanRaw($a['keluhan'])->tindak_lanjut);

        // Langkah 2: rekap disusun ulang (17.00) setelah konfirmasi tampil.
        $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap');
        $ya = $this->ya($this->kepalaA);
        $this->travel(1)->seconds();
        DB::table('rekap_harians')->where('id', $a['rekap'])->update(['status_kirim' => 'gagal', 'updated_at' => now()]);
        $this->tekan($this->kepalaA, $ya);
        $this->assertStringContainsString(BotAksiTulisService::PESAN_BERUBAH, $this->teksLayar($this->kepalaA));
        Event::assertNotDispatched(RekapHarianDibuat::class);

        // "Muat ulang" membuka layar baca segar (n1, hak dicek ulang) dengan tombol versi baru.
        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Muat ulang'));
        $this->assertStringContainsString('<b>Rekap Harian</b>', $this->teksLayar($this->kepalaA));
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Kirim ulang rekap'));
    }

    // ------------------------------------------------------------------ matriks tombol per peran

    /** @return iterable<string, array{string}> */
    public static function peran(): iterable
    {
        foreach (['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorAB', 'investorC'] as $p) {
            yield $p => [$p];
        }
    }

    #[DataProvider('peran')]
    public function test_matriks_tombol_aksi_per_peran(string $peran): void
    {
        $this->dataA();
        $this->keluhan($this->sppgC);
        $this->rekap($this->sppgC);
        $user = $this->pengguna($peran);
        $this->sesiPenuh($user);
        $sppg = $this->sppgDari($peran);

        // Ditulis tangan dari 3.4/3.7-B + rbac.php: layar yang dapat dibuka, dan tombol aksi yang wajib tampil.
        $buka = [
            'admin' => ['rk', 'kl'], 'kepalaA' => ['rk', 'kl', 'mp'], 'ahliGiziA' => ['rk', 'kl'], 'akuntanA' => ['rk'],
            'stafGudangA' => [], 'relawanA' => ['kl'], 'relawanDistribusi' => ['kl', 'mp'], 'relawanTanpaUnit' => ['kl'],
            'investorAB' => ['rk', 'kl'], 'investorC' => ['rk', 'kl'],
        ][$peran];
        $harap = match ($peran) {
            'kepalaA' => ['rk', 'kl', 'mp'],
            'ahliGiziA' => ['kl'],
            'relawanDistribusi' => ['mp'],
            default => [],
        };

        foreach (['rk' => 'Kirim ulang rekap', 'kl' => 'Proses keluhan', 'mp' => 'Berangkat:'] as $layar => $label) {
            $teks = $this->buka($user, $layar, $sppg);

            if (! in_array($layar, $buka, true)) {
                $this->assertNull($teks, "{$peran} tidak boleh membuka {$layar}");
                $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir(), "{$peran}/{$layar}");

                continue;
            }

            $this->assertNotNull($teks, "{$peran} harus bisa membuka {$layar}");
            $this->assertSame(in_array($layar, $harap, true), $this->adaTombol($user, $label), "{$peran}/{$layar}: tombol {$label}");
        }

        $jenisTulis = [BotAksi::JENIS_KIRIM_ULANG_REKAP, BotAksi::JENIS_TINDAK_KELUHAN, BotAksi::JENIS_PICU_PERJALANAN];
        $jumlah = BotAksi::query()->where('user_id', $user->id)->whereIn('jenis', $jenisTulis)->count();
        $harap === [] ? $this->assertSame(0, $jumlah, "{$peran} tidak boleh menerima token aksi tulis") : $this->assertGreaterThan(0, $jumlah);

        if ($peran === 'admin') {
            $this->assertSame(0, BotAksi::query()->count(), 'Admin tidak pernah menerbitkan token');
        }
    }

    /** @return iterable<string, array{string}> peran selain kepala_sppg (satu-satunya pemilik ketiga aksi) */
    public static function peranTanpaHakPenuh(): iterable
    {
        foreach (self::peran() as $nama => $arg) {
            if ($nama !== 'kepalaA') {
                yield $nama => $arg;
            }
        }
    }

    #[DataProvider('peranTanpaHakPenuh')]
    public function test_token_aksi_buatan_untuk_peran_tanpa_hak_ditolak(string $peran): void
    {
        $a = $this->dataA();
        $user = $this->pengguna($peran);
        $this->sesiPenuh($user);

        foreach ([
            [BotAksi::JENIS_YA_KIRIM_ULANG_REKAP, BotAksi::SUBJEK_REKAP, $a['rekap']],
            [BotAksi::JENIS_YA_TINDAK_KELUHAN, BotAksi::SUBJEK_KELUHAN, $a['keluhan']],
            [BotAksi::JENIS_KIRIM_ULANG_REKAP, BotAksi::SUBJEK_REKAP, $a['rekap']],
            [BotAksi::JENIS_TINDAK_KELUHAN, BotAksi::SUBJEK_KELUHAN, $a['keluhan']],
        ] as [$jenis, $tipe, $id]) {
            $this->tekan($user, $this->token($user, $jenis, $this->opsi($this->sppgA, $tipe, $id)));
            // Ahli gizi berhak U keluhan: token buatan berversi palsu ditolak tepat karena versi (bukan hak).
            $harap = $peran === 'ahliGiziA' && $tipe === BotAksi::SUBJEK_KELUHAN ? 'Data sudah berubah.' : BotCallbackHandler::TIDAK_BERHAK;
            $this->assertSame($harap, $this->jawabanTerakhir(), "{$peran}/{$jenis}");
        }

        // Mulai perjalanan: relawan distribusi berhak, sehingga token buatan berversi palsu ditolak tepat karena versi;
        // peran lain ditolak karena hak.
        $this->tekan($user, $this->token($user, BotAksi::JENIS_YA_PICU_PERJALANAN, $this->opsi($this->sppgA, BotAksi::SUBJEK_JADWAL_DISTRIBUSI, $a['jadwal'])));
        $this->assertSame($peran === 'relawanDistribusi' ? 'Data sudah berubah.' : BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir(), "{$peran}/picu");

        $this->assertTanpaEfek($a);
    }

    public function test_tombol_beranda_mulai_perjalanan_hanya_bila_hak_ada(): void
    {
        $registry = app(BotLayarRegistry::class);
        $label = fn (User $u) => array_column($registry->tombolBeranda($u, true), 'label');

        $this->assertContains('Mulai Perjalanan', $label($this->kepalaA));
        $this->assertContains('Mulai Perjalanan', $label($this->relawanDistribusi()));

        foreach ([$this->admin, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA, $this->relawanTanpaUnit(), $this->investorC] as $u) {
            $this->assertNotContains('Mulai Perjalanan', $label($u), $u->role->value);
        }
    }

    // ------------------------------------------------------------------ efek samping & galat

    public function test_render_layar_aksi_tidak_memicu_efek_samping(): void
    {
        $this->dataA();
        Queue::fake();
        Bus::fake();
        Notification::fake();
        Mail::fake();
        $nav = app(BotNavigasi::class);

        foreach ([$this->kepalaA, $this->relawanDistribusi(), $this->admin] as $u) {
            $this->sesiPenuh($u);
            $konteks = new KonteksLayar((int) $u->telegram_id, (int) $u->telegram_id);

            foreach (['rk', 'kl', 'mp'] as $layar) {
                $nav->buka($u, $layar, $this->sppgA, $konteks);
            }
        }

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Event::assertNotDispatched(RekapHarianDibuat::class);
        Event::assertNotDispatched(PerjalananDimulai::class);
    }

    public function test_segarkan_berulang_tidak_menumpuk_token_aksi(): void
    {
        $this->dataA();
        $this->sesiPenuh($this->kepalaA);

        $this->buka($this->kepalaA, 'kl');
        $awal = BotAksi::query()->count();

        for ($i = 0; $i < 10; $i++) {
            $this->buka($this->kepalaA, 'kl');
        }

        $this->assertSame($awal, BotAksi::query()->count());
    }

    public function test_galat_service_dicatat_aman_tanpa_isi_dan_dijawab_umum(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan');

        $this->app->instance(KeluhanService::class, new class extends KeluhanService
        {
            public function perbarui(User $pengguna, FeedbackKeluhan $keluhan, array $data): FeedbackKeluhan
            {
                throw new RuntimeException('SQL RAHASIA-KELUHAN 081234567890');
            }
        });
        $this->app->forgetInstance(BotAksiTulisService::class);
        $this->tekan($this->kepalaA, $this->ya($this->kepalaA));

        $this->assertStringContainsString('Terjadi kesalahan', $this->teksLayar($this->kepalaA));
        $galat = collect($this->logBot)->firstWhere('message', 'bot.galat');
        $this->assertNotNull($galat);
        $this->assertSame(RuntimeException::class, $galat['context']['kelas']);
        $this->assertTanpaBocor();
        $this->assertSame('baru', $this->keluhanRaw($a['keluhan'])->status->value);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('sumber', 'bot')->count(), 'Tanpa jejak audit (service gagal sebelum audit; rollback audit dengan listener asli: BotAksiTulisNotifikasiAsliTest)');
    }

    public function test_rate_limit_callback_menolak_tanpa_efek(): void
    {
        $a = $this->dataA();
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan');
        $ya = $this->ya($this->kepalaA);

        config(['telegram.callback_rate' => 1]);
        $this->tekan($this->kepalaA, $ya);

        $this->assertSame('Terlalu cepat. Coba lagi sebentar.', $this->jawabanTerakhir());
        $this->assertSame(BotAksi::STATUS_AKTIF, $this->statusToken($ya));
        $this->assertTanpaEfek($a);
    }
}

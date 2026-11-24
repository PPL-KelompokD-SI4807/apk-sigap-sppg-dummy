<?php

namespace App\Console\Commands;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Evaluasi\PenilaiKasus;
use App\Services\Ai\Evaluasi\PerekamLlmClient;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\NullLlmClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Evaluasi akurasi Agen AI terhadap kumpulan kasus teks fiktif (NFR-11: akurasi pemilihan tool dan pengisian kolom).
 *
 *   php artisan sigap:ai-evaluasi [--mulai=1] [--batas=] [--berkas=tests/ai-eval/kasus.json] [--jeda=3000]
 *
 * Memanggil LLM SUNGGUHAN (kuota penyedia terpakai): HANYA dijalankan manual, tidak pernah dari CI/scheduler.
 * Tiap kasus dijalankan lewat AiAgentService::handle (jalur yang sama dengan bot: sanitasi, rate limit, HakModulAi,
 * ToolRegistry per peran, ToolSelector, prompt sistem) di dalam transaksi DB yang SELALU di-rollback, dengan pengguna dan
 * SPPG fiktif. LlmClient dibungkus PerekamLlmClient: pemilihan tool + argumen model direkam, lalu iterasi berikutnya
 * dijawab teks tetap tanpa ke penyedia. Tool tulis hanya membuat AiDraft (tidak pernah mengeksekusi aksi) dan draf itu ikut
 * di-rollback; tool baca hanya membaca. Setelah run, jumlah baris ai_drafts/ai_interactions/users/sppgs dibandingkan
 * dengan sebelum run sebagai bukti tidak ada data yang tertinggal.
 */
class AiEvaluasi extends Command
{
    protected $signature = 'sigap:ai-evaluasi
        {--mulai=1 : Nomor urut kasus pertama (mulai dari 1) untuk dicicil}
        {--batas= : Jumlah kasus maksimum yang dijalankan (kosong = sampai akhir)}
        {--berkas=tests/ai-eval/kasus.json : Berkas kasus (JSON)}
        {--jeda= : Jeda antar-kasus dalam milidetik (bawaan config ai.evaluasi.jeda_ms) untuk menjaga kuota penyedia}
        {--ulang=3 : Berapa kali satu kasus diulang saat penyedia menolak 429 (sibuk); 0 = tidak mengulang}';

    protected $description = 'Evaluasi akurasi Agen AI (tool + kolom) terhadap kasus teks fiktif. Memanggil LLM sungguhan; hanya manual.';

    /** Tabel yang dibandingkan sebelum/sesudah run untuk membuktikan rollback. */
    private const TABEL_PANTAU = ['ai_drafts', 'ai_interactions', 'users', 'sppgs'];

    public function handle(): int
    {
        if (($galat = $this->tolakJalan()) !== null) {
            $this->error($galat);

            return self::FAILURE;
        }

        $mulai = filter_var($this->option('mulai'), FILTER_VALIDATE_INT);
        $batas = $this->option('batas');
        $batas = ($batas === null || $batas === '') ? null : filter_var($batas, FILTER_VALIDATE_INT);
        $jeda = $this->option('jeda');
        $jeda = ($jeda === null || $jeda === '') ? max(0, (int) config('ai.evaluasi.jeda_ms', 3000)) : filter_var($jeda, FILTER_VALIDATE_INT);
        $ulang = filter_var($this->option('ulang'), FILTER_VALIDATE_INT);

        if ($mulai === false || $mulai < 1 || $batas === false || ($batas !== null && $batas < 1) || $jeda === false || $jeda < 0 || $ulang === false || $ulang < 0) {
            $this->error('Opsi tidak valid: --mulai dan --batas harus bilangan bulat >= 1, --jeda >= 0 (milidetik), --ulang >= 0.');

            return self::FAILURE;
        }

        $kasus = $this->muatKasus((string) $this->option('berkas'));

        if ($kasus === null) {
            return self::FAILURE;
        }

        $terpilih = array_slice($kasus, $mulai - 1, $batas);

        if ($terpilih === []) {
            $this->error('Tidak ada kasus pada rentang itu (total kasus: '.count($kasus).').');

            return self::FAILURE;
        }

        $sebelum = $this->hitungTabel();
        $llm = app(LlmClient::class);
        $hasil = [];

        $this->warn('Memanggil LLM sungguhan ('.$llm->modelName().'): '.count($terpilih).' kasus, jeda '.$jeda.' ms, ulang maks '.$ulang.'x saat 429. Semua data uji di-rollback.');

        $totalUlang = 0;

        foreach (array_values($terpilih) as $i => $satu) {
            if ($i > 0 && $jeda > 0) {
                usleep($jeda * 1000);
            }

            // 429 = penyedia menolak karena batas laju, BUKAN kesalahan model. Tanpa pengulangan, kasusnya
            // tercatat 'galat' dan hilang dari penyebut akurasi, sehingga angka akhir dihitung atas sampel
            // yang jauh lebih kecil tanpa terlihat. Ulangi dengan tunggu mundur eksponensial.
            $r = $this->jalankanKasus($llm, $satu);

            for ($p = 1; $p <= $ulang && ($r['sibuk'] ?? false); $p++) {
                $tunggu = max(
                    (int) round(((float) ($r['retry_after'] ?? 0)) * 1000),
                    max($jeda, 1000) * (2 ** $p),
                );
                $this->line(sprintf('[%s] 429, menunggu %.1f detik lalu mengulang (%d/%d)', $satu['id'], $tunggu / 1000, $p, $ulang));
                usleep($tunggu * 1000);
                $totalUlang++;
                $r = $this->jalankanKasus($llm, $satu);
            }

            $hasil[] = $r;
            $this->line(sprintf('[%s] %-8s %-34s %s', $r['id'], $r['status'], (string) ($r['tool_dipilih'] ?? '-'), $r['tool_benar'] ? 'benar' : 'salah'));
        }

        $sesudah = $this->hitungTabel();
        $ringkasan = $this->susunRingkasan($hasil, $llm->modelName(), $mulai, $batas, $jeda, $sebelum === $sesudah);
        $berkas = $this->simpan($terpilih, $hasil, $ringkasan);

        $this->tampilkan($ringkasan);
        $this->line('Hasil disimpan: '.$berkas);

        if (! $ringkasan['data_tidak_berubah']) {
            $this->error('PERINGATAN: jumlah baris tabel pantau berubah setelah run (rollback tidak sempurna). Periksa basis data.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Alasan menolak berjalan (null = boleh). */
    private function tolakJalan(): ?string
    {
        $ci = in_array(strtolower((string) (getenv('CI') ?: '')), ['1', 'true', 'yes'], true) || getenv('GITHUB_ACTIONS') !== false;

        if ($ci && ! app()->runningUnitTests()) {
            return 'Perintah ini memanggil LLM sungguhan dan tidak boleh dijalankan di CI. Jalankan manual di mesin pengembang/staging.';
        }

        $provider = (string) config('ai.provider');
        $kosong = fn (string $kunci): bool => trim((string) config($kunci)) === '';

        if ($provider === 'openai_compat' && ($kosong('ai.openai_compat.base_url') || $kosong('ai.openai_compat.api_key') || $kosong('ai.openai_compat.model'))) {
            return 'Konfigurasi LLM belum lengkap: LLM_BASE_URL, LLM_API_KEY, dan LLM_MODEL wajib diisi untuk LLM_PROVIDER=openai_compat.';
        }

        if ($provider === 'anthropic' && $kosong('ai.anthropic.api_key')) {
            return 'Konfigurasi LLM belum lengkap: LLM_API_KEY wajib diisi untuk LLM_PROVIDER=anthropic.';
        }

        $llm = app(LlmClient::class);

        if ($llm instanceof NullLlmClient) {
            return 'Agen AI nonaktif: LLM_PROVIDER belum dikonfigurasi (anthropic atau openai_compat). Evaluasi memerlukan LLM sungguhan.';
        }

        if ($llm instanceof FakeLlmClient && ! app()->runningUnitTests()) {
            return 'LLM_PROVIDER=fake tidak bermakna untuk evaluasi akurasi. Gunakan penyedia sungguhan.';
        }

        return null;
    }

    /** @return list<array<string, mixed>>|null */
    private function muatKasus(string $berkas): ?array
    {
        $path = str_starts_with($berkas, DIRECTORY_SEPARATOR) ? $berkas : base_path($berkas);

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Berkas kasus tidak ditemukan atau tidak dapat dibaca: '.$berkas);

            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || $data === [] || ! array_is_list($data)) {
            $this->error('Berkas kasus harus berupa larik JSON yang tidak kosong.');

            return null;
        }

        foreach ($data as $i => $k) {
            $ok = is_array($k)
                && is_string($k['id'] ?? null)
                && is_string($k['peran'] ?? null) && Role::tryFrom($k['peran']) !== null
                && is_string($k['pesan'] ?? null) && trim($k['pesan']) !== ''
                && is_string($k['tool'] ?? null)
                && is_array($k['argumen'] ?? null);

            if (! $ok) {
                $this->error('Kasus nomor '.($i + 1).' tidak valid (wajib: id, peran sah, pesan, tool, argumen).');

                return null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $kasus
     * @return array<string, mixed>
     */
    private function jalankanKasus(LlmClient $llm, array $kasus): array
    {
        $perekam = new PerekamLlmClient($llm);
        $mulai = microtime(true);
        $galat = null;
        $drafDibuat = 0;
        $userId = null;

        DB::beginTransaction();

        try {
            $user = $this->buatPengguna($kasus);
            $userId = $user->getKey();

            try {
                app()->make(AiAgentService::class, ['llm' => $perekam])->handle($user, AiInput::text((string) $kasus['pesan']));
            } catch (Throwable $e) {
                $galat = class_basename($e);
            }

            $drafDibuat = AiDraft::query()->withoutGlobalScopes()->count();
        } catch (Throwable $e) {
            $galat = 'siapkan_konteks:'.class_basename($e);
        } finally {
            DB::rollBack();

            if ($userId !== null) {
                RateLimiter::clear("ai:m:{$userId}");
                RateLimiter::clear("ai:h:{$userId}");
            }
        }

        // Tool yang dipilih: utamakan panggilan bertool yang diharapkan (model boleh memanggil beberapa), lalu yang pertama.
        $panggilan = null;
        $status = 'ok';
        $sibuk = false;          // 429 penyedia: layak diulang, bukan kesalahan model
        $retryAfter = null;      // saran tunggu dari header Retry-After, bila penyedia mengirimkannya

        if ($perekam->toolCalls !== []) {
            $panggilan = collect($perekam->toolCalls)->first(fn ($c) => $c->name === $kasus['tool']) ?? $perekam->toolCalls[0];
        } elseif ($perekam->ditolakPenyedia !== null) {
            $status = 'ditolak_penyedia';
        } elseif ($galat !== null) {
            $status = 'galat';
        } elseif ($perekam->galat !== null) {
            $status = 'galat';
            $galat = $perekam->galat->logLabel();
            $sibuk = $perekam->galat->status === 429;
            $retryAfter = $perekam->galat->retryAfter;
        } elseif ($perekam->panggilan === 0) {
            $status = 'ditolak_server';
        } else {
            $status = 'tanpa_tool';
        }

        $toolDipilih = $panggilan?->name ?? ($status === 'ditolak_penyedia' ? $perekam->ditolakPenyedia['tool'] : null);
        $aktual = $panggilan !== null ? $panggilan->arguments : ($status === 'ditolak_penyedia' ? $perekam->ditolakPenyedia['argumen'] : []);
        $nilai = PenilaiKasus::nilai((string) $kasus['tool'], (array) $kasus['argumen'], $toolDipilih, $aktual);

        return [
            'id' => $kasus['id'],
            'modul' => (string) ($kasus['modul'] ?? '-'),
            'peran' => $kasus['peran'],
            'status' => $status,
            'galat' => $galat,
            'sibuk' => $sibuk,
            'retry_after' => $retryAfter,
            'tool_diharapkan' => $kasus['tool'],
            'tool_dipilih' => $toolDipilih,
            'tool_benar' => $nilai['tool_benar'],
            'argumen_diharapkan' => $kasus['argumen'],
            'argumen_aktual' => $aktual,
            'kolom' => $nilai['kolom'],
            'kolom_cocok' => $nilai['kolom_cocok'],
            'kolom_total' => $nilai['kolom_total'],
            'draf_dibuat_lalu_dibatalkan' => $drafDibuat,
            'token_masuk' => $perekam->tokenMasuk,
            'token_keluar' => $perekam->tokenKeluar,
            'durasi_ms' => (int) round((microtime(true) - $mulai) * 1000),
        ];
    }

    /** Pengguna + SPPG fiktif (di dalam transaksi yang akan di-rollback). */
    private function buatPengguna(array $kasus): User
    {
        $role = Role::from((string) $kasus['peran']);
        $sppgId = null;

        if (! $role->tanpaSppgId()) {
            $sppg = new Sppg;
            $sppg->forceFill([
                'kode' => 'SPPG-EVAL',
                'nama' => 'SPPG Uji Evaluasi',
                'alamat' => 'Jl. Fiktif No. 1',
                'kapasitas_porsi_harian' => 3000,
                'status_operasional' => 'aktif',
            ])->save();
            $sppgId = $sppg->getKey();
        }

        $unit = $role === Role::Relawan ? (RelawanUnit::tryFrom((string) ($kasus['unit'] ?? 'dapur')) ?? RelawanUnit::Dapur) : null;

        $user = new User;
        $user->forceFill([
            'sppg_id' => $sppgId,
            'name' => 'Pengguna Evaluasi',
            'role' => $role,
            'unit' => $unit,
            'is_active' => true,
        ])->save();

        return $user;
    }

    /** @return array<string, int> */
    private function hitungTabel(): array
    {
        $hasil = [];

        foreach (self::TABEL_PANTAU as $tabel) {
            $hasil[$tabel] = (int) DB::table($tabel)->count();
        }

        return $hasil;
    }

    /**
     * @param  list<array<string, mixed>>  $hasil
     * @return array<string, mixed>
     */
    private function susunRingkasan(array $hasil, string $model, int $mulai, ?int $batas, int $jeda, bool $dataTidakBerubah): array
    {
        $per = [];

        foreach ($hasil as $r) {
            $per[$r['modul']][] = $r;
        }

        ksort($per);

        return [
            'dijalankan_pada' => now()->toIso8601String(),
            'model' => $model,
            'mulai' => $mulai,
            'batas' => $batas,
            'jeda_ms' => $jeda,
            'data_tidak_berubah' => $dataTidakBerubah,
            'total' => $this->hitungGrup($hasil),
            'per_modul' => array_map(fn (array $g) => $this->hitungGrup($g), $per),
        ];
    }

    /**
     * Akurasi dihitung atas kasus yang TIDAK galat (galat penyedia/429 dilaporkan terpisah, tidak menurunkan akurasi).
     *
     * @param  list<array<string, mixed>>  $grup
     * @return array<string, int|float|null>
     */
    private function hitungGrup(array $grup): array
    {
        $dinilai = array_values(array_filter($grup, fn (array $r) => $r['status'] !== 'galat'));
        $benar = count(array_filter($dinilai, fn (array $r) => $r['tool_benar']));
        $cocok = array_sum(array_column($dinilai, 'kolom_cocok'));
        $total = array_sum(array_column($dinilai, 'kolom_total'));

        return [
            'kasus' => count($grup),
            'dinilai' => count($dinilai),
            'galat' => count($grup) - count($dinilai),
            'tool_benar' => $benar,
            'akurasi_tool' => $dinilai === [] ? null : round($benar / count($dinilai) * 100, 1),
            'kolom_cocok' => (int) $cocok,
            'kolom_total' => (int) $total,
            'akurasi_kolom' => $total === 0 ? null : round($cocok / $total * 100, 1),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $kasus
     * @param  list<array<string, mixed>>  $hasil
     * @param  array<string, mixed>  $ringkasan
     */
    private function simpan(array $kasus, array $hasil, array $ringkasan): string
    {
        $dir = storage_path('app/ai-eval');
        File::ensureDirectoryExists($dir);

        $dasar = now()->format('Y-m-d_His');
        $path = $dir.'/'.$dasar.'.json';

        for ($n = 2; is_file($path); $n++) {
            $path = $dir.'/'.$dasar.'_'.$n.'.json';
        }

        file_put_contents($path, json_encode([
            'ringkasan' => $ringkasan,
            'kasus' => array_values($kasus),
            'hasil' => $hasil,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return $path;
    }

    /** @param  array<string, mixed>  $ringkasan */
    private function tampilkan(array $ringkasan): void
    {
        $baris = fn (string $nama, array $g): array => [
            $nama,
            $g['kasus'],
            $g['galat'],
            $g['tool_benar'].'/'.$g['dinilai'],
            $g['akurasi_tool'] === null ? '-' : $g['akurasi_tool'].'%',
            $g['kolom_cocok'].'/'.$g['kolom_total'],
            $g['akurasi_kolom'] === null ? '-' : $g['akurasi_kolom'].'%',
        ];

        $rows = [];

        foreach ($ringkasan['per_modul'] as $modul => $g) {
            $rows[] = $baris((string) $modul, $g);
        }

        $rows[] = $baris('TOTAL', $ringkasan['total']);

        $this->table(['Modul', 'Kasus', 'Galat', 'Tool benar', 'Akurasi tool', 'Kolom cocok', 'Akurasi kolom'], $rows);
    }
}

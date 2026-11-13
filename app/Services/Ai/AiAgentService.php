<?php

namespace App\Services\Ai;

use App\Enums\HasilAi;
use App\Enums\JenisInputAi;
use App\Models\AiDraft;
use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\Catalog\DraftToolResolver;
use App\Services\Ai\Drafts\DraftLimitException;
use App\Services\Ai\Drafts\GalatDraf;
use App\Services\Ai\Llm\JalurSkema;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Llm\SupportsVision;
use App\Services\Ai\Llm\ToolCall;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\ArgumentValidator;
use App\Services\Ai\Tools\PemisahArgumen;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolException;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\Tools\ToolSelector;
use App\Services\Ai\Tools\WriteDraftTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Orkestrator Agen AI (FR-11). Alur per pesan:
 *   1. pengguna aktif + rate limit (per menit & per jam, NFR-12);
 *   2. masukan dibersihkan, dipotong, dibungkus sebagai data tak tepercaya (IntentParser);
 *      foto pada klien SupportsVision: tahap 1 model vision tanpa tool -> teks hasil baca = data (D6);
 *   3. tool disaring per peran (forUser) lalu, bila aktif, dipilih sesuai niat (ToolSelector, subset);
 *   4. loop tool-calling maks config ai.agent.max_iterations; tiap panggilan melewati pelindung token
 *      global (TokenBudget); tiap tool call: nama dikenal? -> tool milik peran pengguna? -> ditawarkan?
 *      -> argumen JSON sah? -> argumen terlindungi dibuang -> skema argumen valid? -> eksekusi
 *      (baca: langsung ber-scope; tulis: hanya AiDraft);
 *   5. masukan salah pada tool TULIS (galat skema argumen, kolom ditolak penyedia, rujukan tidak ditemukan, satuan
 *      salah, galat Form Request) -> draf "perlu diperbaiki" (GalatDraf) dan loop BERHENTI tanpa panggilan LLM lagi;
 *      balasan = satu pesan server (daftar kolom) + tombol Mini App ke formulir draf;
 *   6. draf terbentuk -> balasan = ringkasan SERVER dari draf (WriteDraftTool::kalimatDraf, tanpa id/keyakinan), bukan
 *      teks model; tanpa draf -> jawaban model disaring (PenyaringBalasan: tag hasil_tool, JSON), klaim "draf disiapkan"
 *      atau sisa jejak internal -> PESAN_TANPA_DRAF + tombol Mini App. Teks yang meniru panggilan tool -> SATU iterasi
 *      koreksi (ai.agent.koreksi_tool_teks). Jawaban teks polos dipotong; jejak + token (angka) dicatat di
 *      ai_interactions dan SATU baris log 'ai.pesan' per pesan (tanpa isi pesan).
 * LLM gagal/tidak tersedia/terlalu lama -> balasan ramah per kategori + fallback formulir manual; galat penyedia
 * dicatat aman ('ai.llm_galat'); tool call ditolak penyedia (tool_use_failed) diulang sekali dengan catatan perbaikan;
 * draf yang sudah dibuat sebelum galat tetap ditawarkan.
 * Hemat token (R-05): foto -> subset tool dokumen (ToolSelector::selectFoto), iterasi ai.foto.max_iterations, hasil
 * tool dipotong lebih pendek, loop BERHENTI begitu draf terbentuk; tiap panggilan dicek terhadap anggaran token per pesan
 * (ai.agent.token_per_pesan) SEBELUM memanggil penyedia. 429 token per menit tanpa draf + PercobaanAi::bolehTunda ->
 * AiReply::tunda (job di-release; interaksi & hasil baca foto dipakai ulang pada proses ulang).
 */
class AiAgentService
{
    /** Sisa anggaran waktu minimum (detik) untuk mengulang setelah tool_use_failed. */
    private const MIN_SISA_ULANG = 5.0;

    /** Balasan server setelah draf dari foto terbentuk (loop berhenti tanpa iterasi ringkasan LLM). */
    public const PESAN_DRAF_FOTO = 'Draf dari foto sudah disiapkan. Periksa lalu simpan; data BELUM disimpan.';

    /** Penutup ringkasan draf (jalur teks/suara) yang disusun server, menggantikan jawaban teks model. */
    public const PESAN_DRAF = 'Data BELUM disimpan. Periksa lalu konfirmasi lewat tombol yang muncul.';

    /**
     * Balasan JUJUR server bila tidak ada AiDraft baru pada giliran ini, padahal jawaban model mengklaim draf/data sudah
     * disiapkan atau masih memuat jejak internal (tag hasil tool, JSON, ID). Disertai tombol formulir Mini App.
     */
    public const PESAN_TANPA_DRAF = 'Draf belum dibuat dan tidak ada data yang disimpan. Agen AI belum berhasil menyiapkan draf dari pesan ini. Silakan isi lewat formulir di Mini App, atau kirim ulang dengan keterangan yang lebih jelas.';

    /** Jumlah kalimat ringkasan draf maksimum di balasan (sama dengan batas tombol draf AiChatHandler). */
    private const MAKS_RINGKASAN_DRAF = 3;

    /** Hasil baca foto pesan yang sedang diproses (untuk dipakai ulang bila pesan ditunda). Tidak di-log. */
    private ?string $teksVision = null;

    /**
     * Jeda release job untuk 429 token per menit: Retry-After penyedia (+1 dtk), atau jeda_bawaan (60) bila tidak ada.
     * Null = TIDAK diulang: Retry-After > ai.retry_429.retry_after_maks (90) -> pesan ramah langsung.
     */
    public static function jedaUlang(LlmException $e): ?int
    {
        $maks = max(1, (int) config('ai.retry_429.retry_after_maks', 90));

        if ($e->retryAfter === null || $e->retryAfter <= 0) {
            return max(1, (int) config('ai.retry_429.jeda_bawaan', 60));
        }

        return $e->retryAfter > $maks ? null : (int) ceil($e->retryAfter) + 1;
    }

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolRegistry $registry,
        private readonly IntentParser $parser,
        private readonly ArgumentValidator $validator,
        private readonly ToolSelector $selector,
        private readonly TokenBudget $budget,
        private readonly DraftToolResolver $resolver,
    ) {}

    /**
     * False bila klien aktif membaca gambar lewat model vision terpisah yang belum dikonfigurasi
     * (LLM_VISION_MODEL kosong): AiChatHandler membalas ramah SEBELUM mengunduh foto (D6).
     * Klien tanpa SupportsVision (Anthropic, Fake) menerima gambar langsung (perilaku lama).
     */
    public function acceptsImages(): bool
    {
        return ! $this->llm instanceof SupportsVision || $this->llm->supportsVision();
    }

    public function handle(User $user, AiInput $input, ?PercobaanAi $percobaan = null): AiReply
    {
        $started = microtime(true);
        $percobaan ??= new PercobaanAi;
        $this->teksVision = null;

        if (! $user->canAuthenticate()) {
            return new AiReply('Akun Anda nonaktif. Hubungi Admin SPPG.', fallback: true);
        }

        // Proses ulang (retry 429): interaksi percobaan sebelumnya dipakai ulang (tidak digandakan). Batas laju pengguna
        // dan pelindung token berlaku seperti permintaan baru (setiap percobaan memanggil penyedia; tidak ditembus).
        $interaction = $percobaan->interaksiId === null ? null : AiInteraction::query()->withoutGlobalScopes()
            ->whereKey($percobaan->interaksiId)->where('user_id', $user->getKey())->first();
        $lanjutan = $interaction !== null;
        // Keadaan percobaan sebelumnya (angka saja) untuk dibawa ke log percobaan ini.
        $sebelumnya = $lanjutan ? (array) ($interaction->argumen ?? []) : [];

        $interaction ??= AiInteraction::query()->create([
            'sppg_id' => $user->isInvestor() ? $user->sppgAktifId() : $user->sppg_id,
            'user_id' => $user->getKey(),
            'jenis_input' => $input->type,
            'model' => mb_substr($this->llm->modelName(), 0, 100),
            'hasil' => HasilAi::Gagal,
        ]);

        $log = ['iterasi' => 0, 'tools' => [], 'panjang_masukan' => 0, 'gambar' => count($input->images)];

        if ($percobaan->ulang()) {
            $log['percobaan'] = $percobaan->ke;
        }

        // Hasil baca foto dipakai ulang: pemakaian token vision percobaan pertama tetap tercatat (angka saja).
        if ($lanjutan && $percobaan->teksVision !== null) {
            $log['gambar'] = max(1, (int) ($sebelumnya['gambar'] ?? 1));
            $log['media_terkirim'] = true;
            $log['vision'] = 'dipakai_ulang';

            if (isset($sebelumnya['token_vision'])) {
                $log['token_vision'] = $sebelumnya['token_vision'];
            }
        }

        if ($input->detikAudio > 0) {
            $log['stt_detik'] = $input->detikAudio;
        }

        try {
            if (! $this->withinRateLimit($user)) {
                return $this->finish($interaction, $started, $log, HasilAi::Ditolak, 'rate_limited', new AiReply(
                    'Batas penggunaan Agen AI tercapai. Coba lagi nanti atau isi formulir di Mini App.',
                    fallback: true,
                    interactionId: $interaction->id,
                ));
            }

            // Investor (R-06a) di chat bot tidak mengirim header SPPG aktif: hanya boleh bila SPPG-nya satu.
            if ($user->isInvestor() && $user->sppgAktifId() === null) {
                return $this->finish($interaction, $started, $log, HasilAi::Ditolak, 'sppg_aktif_belum_dipilih', new AiReply(
                    'Anda memiliki lebih dari satu SPPG (atau belum terhubung ke SPPG). Buka Mini App dan pilih SPPG aktif untuk melihat datanya.',
                    fallback: true,
                    interactionId: $interaction->id,
                ));
            }

            $text = $this->parser->sanitize($input->text, (int) config('ai.agent.max_input_chars', 2000));
            $log['panjang_masukan'] = mb_strlen($text);

            if ($text === '' && $input->images === [] && $percobaan->teksVision === null) {
                return $this->finish($interaction, $started, $log, HasilAi::Ditolak, 'masukan_kosong', new AiReply(
                    'Silakan ketik permintaan Anda, kirim foto nota, atau pesan suara.',
                    interactionId: $interaction->id,
                ));
            }

            return ActingUser::run($user, function () use ($user, $input, $text, $interaction, $started, &$log, $percobaan) {
                return $this->loop($user, $input, $text, $interaction, $started, $log, $percobaan);
            });
        } catch (LlmException $e) {
            // 429 token per menit dan belum ada draf (loop() sudah menawarkan draf bila ada): tunda, proses ulang nanti
            // (job di-release). Tidak ada balasan pada percobaan ini selain pemberitahuan "sedang diproses".
            $detik = $percobaan->bolehTunda && $e->tokenPerMenit() ? self::jedaUlang($e) : null;

            if ($detik !== null) {
                $log['tertunda_detik'] = $detik;

                return $this->finish($interaction, $started, $log, HasilAi::Fallback, $e->logLabel().' tertunda', new AiReply(
                    '',
                    interactionId: $interaction->id,
                    tunda: new TundaAi($detik, (int) $interaction->id, $this->teksVision ?? $percobaan->teksVision),
                ));
            }

            // Hanya kategori + status HTTP + kode detail yang dicatat (tanpa isi respons/kunci, D5).
            return $this->finish($interaction, $started, $log, HasilAi::Fallback, $e->logLabel(), new AiReply(
                $e->tokenPerMenit() && $percobaan->ulang() ? LlmException::PESAN_RAMAI_SETELAH_ULANG : $e->friendlyMessage(),
                fallback: true,
                interactionId: $interaction->id,
            ));
        } catch (Throwable $e) {
            report($e);

            return $this->finish($interaction, $started, $log, HasilAi::Gagal, class_basename($e), new AiReply(
                'Maaf, terjadi kesalahan saat memproses pesan. Silakan isi lewat formulir di Mini App.',
                fallback: true,
                interactionId: $interaction->id,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $log
     */
    private function loop(User $user, AiInput $input, string $text, AiInteraction $interaction, float $started, array &$log, PercobaanAi $percobaan): AiReply
    {
        $budget = (float) config('ai.agent.time_budget_seconds', 25);
        $deadline = $started + $budget;
        $nonce = $this->parser->nonce();

        // D6: klien dengan model vision terpisah -> tahap 1 baca gambar tanpa tool; hasilnya data. Proses ulang (retry
        // 429) memakai hasil baca percobaan sebelumnya: model vision tidak dipanggil dua kali.
        $imageText = $percobaan->teksVision ?? ($input->images !== [] && $this->llm instanceof SupportsVision
            ? $this->readImages($this->llm, $input, $text, $deadline, $log, $interaction)
            : null);
        $this->teksVision = $imageText;
        $foto = $input->type === JenisInputAi::Foto && ($input->images !== [] || $imageText !== null);
        // Pesan foto: tanpa retry HTTP (429/5xx) agar kuota TPM tidak habis; satu-satunya ulang = tool_use_failed.
        $tanpaUlang = $foto;

        // D7: subset tool milik peran yang relevan (selalu subset forUser()). Foto: subset dokumen (selectFoto).
        $allowed = $this->registry->forUser($user);
        $gabungan = trim($text.' '.($imageText ?? ''));
        $selection = $foto
            ? $this->selector->selectFoto($allowed, $text, $gabungan)
            : $this->selector->select($allowed, $gabungan, $input->type);
        $tools = $selection->tools;
        $schemas = $selection->schemas;
        $log['tool_ditawarkan'] = count($tools);

        if ($selection->active) {
            $log['tool_dipilih'] = true;
        }

        $system = $this->parser->systemPrompt($user, array_keys($tools), $nonce, $selection->active ? $selection->otherModules : [], $foto);
        $messages = [$this->parser->userMessage($input, $text, $nonce, $imageText)];
        // Teks pengguna hanya untuk penjagaan server (ResolverReferensi), tidak disimpan dan tidak dikirim ulang.
        $context = new ToolContext($interaction->id, $input->type, $gabungan);

        $maxIterations = max(1, (int) ($foto ? config('ai.foto.max_iterations', 3) : config('ai.agent.max_iterations', 4)));
        $maxCalls = max(1, (int) config('ai.agent.max_tool_calls_per_turn', 5));
        $maxHasil = (int) ($foto ? config('ai.foto.max_tool_result_chars', 1500) : config('ai.agent.max_tool_result_chars', 6000));
        $drafts = [];
        $final = null;
        $model = $this->llm->modelName();

        $sudahUlang = false;
        $dikoreksi = false;

        for ($i = 0; $i < $maxIterations; $i++) {
            if (microtime(true) - $started > $budget) {
                break;
            }

            $log['iterasi'] = $i + 1;

            try {
                $response = $this->chat($model, $system, $messages, $schemas, $deadline, $log, $interaction, $sudahUlang, $tanpaUlang);
            } catch (LlmException $e) {
                // tool_use_failed yang tetap gagal setelah SATU percobaan ulang (atau tanpa waktu untuk ulang): bagian
                // yang sudah dipahami menjadi draf "perlu diperbaiki" (tanpa panggilan LLM lagi).
                if ($e->toolGagal() && ($balasan = $this->drafDariPenolakan($user, $e, $context, $log, $tools, $interaction, $started, $drafts)) !== null) {
                    return $balasan;
                }

                if ($drafts === []) {
                    throw $e;
                }

                // Draf dari langkah sebelumnya sudah tersimpan sebagai AiDraft: tetap ditawarkan (tidak dibuat ulang).
                return $this->finish($interaction, $started, $log, HasilAi::Fallback, $e->logLabel(), new AiReply(
                    $this->teksDraf($user, $drafts, 'Sebagian permintaan sudah disiapkan sebagai draf. Periksa dan simpan di Mini App.'),
                    $drafts,
                    interactionId: $interaction->id,
                ));
            }

            if (! $response->hasToolCalls()) {
                // Model meniru panggilan/hasil tool sebagai TEKS (tag hasil_tool + JSON argumen) atau mengklaim draf tanpa
                // memanggil tool tulis (temuan staging): SATU iterasi koreksi dengan catatan sistem. Teks itu tidak
                // dikirim ke pengguna dan tidak dikirim balik ke penyedia (riwayat pesan tidak diubah).
                if (! $dikoreksi && $drafts === [] && $i + 1 < $maxIterations && $this->perluKoreksi($response->text, $tools, $deadline)) {
                    $dikoreksi = true;
                    $log['koreksi_tool_teks'] = 1;
                    $system .= "\n\n".$this->parser->catatanToolTeks($this->namaToolTulis($tools));

                    continue;
                }

                $final = $response->text;
                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->text,
                'tool_calls' => array_map(fn (ToolCall $c) => $c->toArray(), $response->toolCalls),
            ];

            foreach ($response->toolCalls as $n => $call) {
                $result = $n < $maxCalls
                    ? $this->runTool($user, $call, $context, $log, $tools)
                    : $this->record($log, $call->name, ToolResult::denied('Terlalu banyak pemanggilan tool dalam satu langkah.'), []);

                if ($result->draft !== null) {
                    $drafts[] = $result->draft;
                }

                // Masukan salah: BERHENTI di sini (tanpa panggilan LLM lagi, tanpa pertanyaan balik). Balasan disusun
                // server; draf dibuka di Mini App untuk diperbaiki. Sisa tool call pada langkah ini tidak dijalankan.
                if ($result->perluDiperbaiki()) {
                    $tertunda = count($response->toolCalls) - $n - 1;

                    if ($tertunda > 0) {
                        $log['aksi_tertunda'] = $tertunda;
                    }

                    return $this->balasPerbaikan($interaction, $started, $log, $result->draft, $drafts, $tertunda > 0);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'name' => $call->name,
                    'content' => $this->parser->wrapToolResult($result->toModelContent($maxHasil), $nonce),
                    'is_error' => $result->isError(),
                ];
            }

            // Foto: draf sudah terbentuk -> BERHENTI tanpa iterasi ringkasan LLM (iterasi itu yang memicu 429 TPM di
            // staging). Balasan disusun server; layar konfirmasi/tombol Mini App per draf dari AiChatHandler.
            if ($foto && $drafts !== []) {
                $log['berhenti_setelah_draf'] = 1;

                return $this->finish($interaction, $started, $log, HasilAi::Sukses, null, new AiReply(
                    $this->teksDraf($user, $drafts, self::PESAN_DRAF_FOTO),
                    $drafts,
                    interactionId: $interaction->id,
                ));
            }
        }

        $statuses = array_column($log['tools'], 'status');
        $deniedOnly = $statuses !== [] && array_diff($statuses, [ToolResult::DITOLAK, ToolResult::INVALID]) === [];

        if ($final === null) {
            // Loop terpotong (iterasi/waktu habis): jangan teruskan; draf yang sudah ada tetap ditawarkan.
            return $this->finish($interaction, $started, $log, HasilAi::Fallback, 'loop_terpotong', new AiReply(
                $drafts === []
                    ? 'Maaf, permintaan ini belum dapat diselesaikan otomatis. Silakan isi lewat formulir di Mini App.'
                    : $this->teksDraf($user, $drafts, 'Sebagian permintaan sudah disiapkan sebagai draf. Periksa dan simpan di Mini App.'),
                $drafts,
                fallback: $drafts === [],
                interactionId: $interaction->id,
            ));
        }

        // Draf terbentuk pada giliran ini: balasan = ringkasan yang dirender SERVER dari data draf (tanpa id internal dan
        // skor keyakinan), BUKAN teks model. Layar konfirmasi/tombol per draf dikirim AiChatHandler.
        if ($drafts !== []) {
            $log['balasan'] = 'server';

            return $this->finish($interaction, $started, $log, $deniedOnly ? HasilAi::Ditolak : HasilAi::Sukses, null, new AiReply(
                $this->teksDraf($user, $drafts, self::PESAN_DRAF),
                $drafts,
                interactionId: $interaction->id,
            ));
        }

        // Tanpa draf: jawaban model disaring (tag hasil_tool, JSON hasil tool). Sisa jejak internal (fail-safe) atau klaim
        // "draf disiapkan/data tersimpan" padahal tidak ada AiDraft baru -> pesan server yang jujur + tombol Mini App.
        [$bersih, $dibuang] = PenyaringBalasan::saring($final);

        if ($dibuang > 0) {
            $log['balasan_disaring'] = $dibuang;
        }

        $reply = $bersih === null ? '' : $this->cleanOutput($bersih);
        $alasan = match (true) {
            $bersih === null || PenyaringBalasan::adaJejakInternal($reply) => 'tanpa_draf:sisa_jejak',
            PenyaringBalasan::klaimDraf($reply) => 'tanpa_draf:klaim_draf',
            $reply === '' && $dibuang > 0 => 'tanpa_draf:jejak_dibuang',
            default => null,
        };

        if ($alasan !== null) {
            $log['balasan'] = 'server';

            return $this->finish($interaction, $started, $log, HasilAi::Fallback, $alasan, new AiReply(
                self::PESAN_TANPA_DRAF,
                fallback: true,
                interactionId: $interaction->id,
            ));
        }

        return $this->finish($interaction, $started, $log, $deniedOnly ? HasilAi::Ditolak : HasilAi::Sukses, null, new AiReply(
            $reply === '' ? 'Baik.' : $reply,
            interactionId: $interaction->id,
        ));
    }

    /**
     * Ringkasan draf yang dirender SERVER dari draf tersimpan: satu kalimat per draf (WriteDraftTool::kalimatDraf: nama
     * dari basis data, tanpa id internal/skor keyakinan) + kalimat penutup. Dipanggil di dalam ActingUser::run. Galat
     * menyusun ringkasan tidak menggagalkan balasan (label modul saja).
     *
     * @param  list<AiDraft>  $drafts
     */
    private function teksDraf(User $user, array $drafts, string $penutup): string
    {
        $baris = [];

        foreach (array_slice($drafts, 0, self::MAKS_RINGKASAN_DRAF) as $draft) {
            $kalimat = null;

            try {
                $kalimat = $this->resolver->resolve($draft)?->kalimatDraf($user, $draft);
            } catch (Throwable $e) {
                report($e);
            }

            $kalimat = is_string($kalimat) ? WriteDraftTool::satuBaris($kalimat, 300) : '';
            $baris[] = $kalimat !== '' && ! PenyaringBalasan::adaJejakInternal($kalimat)
                ? $kalimat
                : 'Draf '.WriteDraftTool::satuBaris((string) config("rbac.modules.{$draft->module}.label", $draft->module), 60);
        }

        if (count($drafts) > self::MAKS_RINGKASAN_DRAF) {
            $baris[] = 'Dan '.(count($drafts) - self::MAKS_RINGKASAN_DRAF).' draf lain (lihat di Mini App).';
        }

        return implode("\n", [...$baris, $penutup]);
    }

    /**
     * Jawaban teks (tanpa tool call) perlu satu iterasi koreksi: fitur aktif, ada tool tulis yang ditawarkan, sisa waktu
     * cukup, dan teks meniru panggilan/hasil tool (jejak internal) atau mengklaim draf tanpa draf.
     *
     * @param  array<string, AiTool>  $tools
     */
    private function perluKoreksi(string $text, array $tools, float $deadline): bool
    {
        return (bool) config('ai.agent.koreksi_tool_teks', true)
            && $this->namaToolTulis($tools) !== []
            && $deadline - microtime(true) >= self::MIN_SISA_ULANG
            && (PenyaringBalasan::adaJejakInternal($text) || PenyaringBalasan::klaimDraf($text));
    }

    /**
     * @param  array<string, AiTool>  $tools
     * @return list<string>
     */
    private function namaToolTulis(array $tools): array
    {
        return array_values(array_map(fn (AiTool $t) => $t->name(), array_filter($tools, fn (AiTool $t) => $t instanceof WriteDraftTool)));
    }

    /**
     * Balasan jalur kesalahan: SATU pesan server (label modul + daftar kolom, tanpa nilai masukan) dan draf "perlu
     * diperbaiki" di urutan pertama (tombol Mini App di AiChatHandler). $adaTertunda = ada aksi lain pada pesan yang sama
     * yang tidak diproses karena loop berhenti: pesan menyebut bahwa sisanya perlu dikirim ulang.
     *
     * @param  array<string, mixed>  $log
     * @param  list<AiDraft>  $drafts
     */
    private function balasPerbaikan(AiInteraction $interaction, float $started, array $log, AiDraft $draft, array $drafts, bool $adaTertunda = false): AiReply
    {
        $log['perlu_diperbaiki'] = 1;
        $label = (string) config("rbac.modules.{$draft->module}.label", $draft->module);
        $lain = array_values(array_filter($drafts, fn (AiDraft $d) => $d->getKey() !== $draft->getKey()));
        $teks = GalatDraf::pesanBalasan($label, GalatDraf::dari($draft));

        if ($adaTertunda) {
            $teks .= ' '.GalatDraf::PESAN_TERTUNDA;
        }

        return $this->finish($interaction, $started, $log, HasilAi::Sukses, null, new AiReply(
            $teks,
            [$draft, ...$lain],
            interactionId: $interaction->id,
        ));
    }

    /**
     * tool_use_failed final (percobaan ulang SATU kali sudah habis/tidak sempat): argumen yang ditolak penyedia
     * (failed_generation, data tak tepercaya) dijalankan lewat pipeline tool BIASA (tool dikenal + milik peran +
     * ditawarkan, argumen terlindungi dibuang, skema -> PemisahArgumen, Form Request) dengan kolom yang ditolak
     * penyedia dikosongkan. Hasil draf -> balasan server + tombol Mini App. Hanya tool tulis; argumen tidak dicatat di
     * log interaksi (failed_generation tidak pernah disimpan). Null = tidak berlaku (galat ramah seperti biasa).
     *
     * @param  array<string, mixed>  $log
     * @param  array<string, AiTool>  $offered
     * @param  list<AiDraft>  $drafts
     */
    private function drafDariPenolakan(User $user, LlmException $e, ToolContext $context, array &$log, array $offered, AiInteraction $interaction, float $started, array $drafts): ?AiReply
    {
        $tool = $e->tool === null ? null : ($offered[$e->tool] ?? null);

        if (! $tool instanceof WriteDraftTool) {
            return null;
        }

        $galat = PemisahArgumen::dariJalurPenyedia(JalurSkema::saring($e->jalur, JalurSkema::namaProperti($tool->parameters())));
        $result = $this->runTool($user, new ToolCall('gagal_penyedia', $tool->name(), $e->argumenGagal ?? []), $context, $log, $offered, $galat, catatArgumen: false);

        if ($result->draft === null) {
            return null;
        }

        $log['draf_dari_penolakan'] = 1;
        $drafts[] = $result->draft;

        if ($result->perluDiperbaiki()) {
            return $this->balasPerbaikan($interaction, $started, $log, $result->draft, $drafts);
        }

        return $this->finish($interaction, $started, $log, HasilAi::Sukses, null, new AiReply(
            $this->teksDraf($user, $drafts, 'Draf sudah disiapkan. Periksa dan simpan di Mini App. Data BELUM disimpan.'),
            $drafts,
            interactionId: $interaction->id,
        ));
    }

    /**
     * Satu panggilan chat melewati pelindung token. Penyedia menolak tool call buatan model (Groq HTTP 400
     * `tool_use_failed`, mis. argumen tidak cocok skema) -> diulang SEKALI per pesan dengan catatan perbaikan singkat di
     * prompt sistem. Aman diulang: penolakan terjadi sebelum ada tool yang dijalankan, jadi tidak ada draf ganda;
     * riwayat pesan tidak diubah (pesan pengguna tidak digandakan, failed_generation tidak dikirim balik).
     * Setiap galat penyedia dicatat aman (catatGalatLlm). Masih gagal setelah ulang -> loop() membuat draf "perlu
     * diperbaiki" dari bagian yang dipahami (drafDariPenolakan).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $schemas
     * @param  array<string, mixed>  $log
     *
     * @throws LlmException
     */
    private function chat(string $model, string $system, array $messages, array $schemas, float $deadline, array &$log, AiInteraction $interaction, bool &$sudahUlang, bool $tanpaUlang = false): LlmResponse
    {
        $estimate = TokenBudget::estimateRequest($system, $messages, $schemas);
        $this->cekAnggaranPesan($interaction, $model, $estimate, $log);
        $this->budget->check($model, $estimate);

        // Klien tanpa vision terpisah (Anthropic): gambar ikut panggilan ini -> media terkirim ke penyedia.
        foreach ($messages as $m) {
            if (($m['images'] ?? []) !== []) {
                $log['media_terkirim'] = true;
            }
        }

        try {
            $response = $this->llm->chat($messages, $schemas, ['system' => $system, 'deadline' => $deadline] + ($tanpaUlang ? ['tanpa_ulang' => true] : []));
        } catch (LlmException $e) {
            $ulang = $e->toolGagal() && ! $sudahUlang && $deadline - microtime(true) >= self::MIN_SISA_ULANG;
            $this->catatGalatLlm($e, $interaction, $model, 'chat', $log, $estimate, count($schemas), $ulang);

            if ($e->toolGagal()) {
                // Prompt sudah diproses penyedia: estimasi dihitung ke pelindung token global dan anggaran pesan.
                $this->budget->record($model, $estimate);
                $log['token_pesan'] = (int) ($log['token_pesan'] ?? 0) + $estimate;
            }

            if (! $ulang) {
                throw $e;
            }

            $sudahUlang = true;
            $log['ulang_tool_gagal'] = 1;

            $skemaTool = $e->tool === null ? null : collect($schemas)->firstWhere('name', $e->tool);
            $nama = is_array($skemaTool['input_schema'] ?? null) ? JalurSkema::namaProperti($skemaTool['input_schema']) : [];
            $catatan = $this->parser->catatanToolGagal($e->tool, $e->jalur, $nama);

            return $this->chat($model, $system."\n\n".$catatan, $messages, $schemas, $deadline, $log, $interaction, $sudahUlang, $tanpaUlang);
        }

        $this->recordUsage($model, $response, $estimate, $log);
        $used = $response->inputTokens + $response->outputTokens;
        $log['token_pesan'] = (int) ($log['token_pesan'] ?? 0) + ($used > 0 ? $used : $estimate);

        return $response;
    }

    /**
     * Anggaran token TOTAL satu pesan ke model teks (ai.agent.token_per_pesan; 0 = nonaktif): pemakaian panggilan
     * sebelumnya (usage nyata, atau estimasi) + estimasi panggilan ini. Melampaui -> LlmException terlalu_besar
     * (anggaran_pesan) TANPA memanggil penyedia; loop() menawarkan draf yang sudah ada, selain itu balasan "permintaan
     * terlalu besar". Log hanya angka.
     *
     * @param  array<string, mixed>  $log
     *
     * @throws LlmException
     */
    private function cekAnggaranPesan(AiInteraction $interaction, string $model, int $estimate, array &$log): void
    {
        $batas = max(0, (int) config('ai.agent.token_per_pesan', 0));
        $terpakai = (int) ($log['token_pesan'] ?? 0);

        if ($batas === 0 || $terpakai + $estimate <= $batas) {
            return;
        }

        $log['anggaran_pesan'] = ['terpakai' => $terpakai, 'estimasi' => $estimate, 'batas' => $batas];
        Log::info('ai.anggaran_pesan', [
            'interaksi_id' => $interaction->id,
            'model' => mb_substr($model, 0, 100),
            'iterasi' => (int) ($log['iterasi'] ?? 0),
        ] + $log['anggaran_pesan']);

        throw LlmException::tooLarge('anggaran_pesan');
    }

    /**
     * Log galat LLM yang AMAN (kanal default, nama 'ai.llm_galat'): status HTTP, kode/tipe galat penyedia, nama tool
     * yang ditolak (hanya tool yang dikirim), path argumen tanpa nilai, estimasi token, kelas exception. TIDAK memuat
     * isi pesan pengguna, argumen tool, failed_generation, initData, token, atau kunci.
     *
     * @param  array<string, mixed>  $log
     */
    private function catatGalatLlm(LlmException $e, AiInteraction $interaction, string $model, string $tahap, array $log, int $estimate, int $toolDitawarkan, bool $diulang): void
    {
        Log::warning('ai.llm_galat', [
            'interaksi_id' => $interaction->id,
            'user_id' => $interaction->user_id,
            'model' => mb_substr($model, 0, 100),
            'tahap' => $tahap,
            'iterasi' => (int) ($log['iterasi'] ?? 0),
            'estimasi_token' => $estimate,
            'tool_ditawarkan' => $toolDitawarkan,
            'diulang' => $diulang,
        ] + $e->konteksLog());
    }

    /**
     * @param  array<string, mixed>  $log
     * @param  array<string, AiTool>  $offered  tool yang ditawarkan pada pesan ini (subset forUser())
     * @param  array<string, list<string>>  $galatPenyedia  kolom tool ini yang ditolak penyedia (tool_use_failed final)
     * @param  bool  $catatArgumen  false = argumen tidak masuk jejak log (argumen dari failed_generation)
     */
    private function runTool(User $user, ToolCall $call, ToolContext $context, array &$log, array $offered, array $galatPenyedia = [], bool $catatArgumen = true): ToolResult
    {
        try {
            $tool = $this->registry->resolveFor($user, $call->name);
        } catch (ToolException $e) {
            Log::warning('ai.tool_ditolak', ['user_id' => $user->getKey(), 'alasan' => $e->reason, 'tool' => mb_substr($call->name, 0, 64)]);

            return $this->record($log, $call->name, $e->reason === ToolException::UNKNOWN
                ? ToolResult::invalid('Tool tidak dikenal.')
                : ToolResult::denied(), []);
        }

        // Pemilihan tool dinamis: hanya tool yang ditawarkan yang boleh dijalankan (skemanya dikenal model).
        if (! isset($offered[$tool->name()])) {
            return $this->record($log, $call->name, ToolResult::invalid('Tool tidak ditawarkan untuk pesan ini. Minta pengguna menyebut modulnya dengan jelas.'), []);
        }

        if (! $call->argumentsValid) {
            return $this->record($log, $call->name, ToolResult::invalid('Argumen harus berupa objek JSON.'), []);
        }

        // Argumen terlindungi dibuang; hanya tool yang menyatakannya eksplisit (MenerimaArgumenTerlindungi) yang
        // mempertahankan role/unit/sppg_id tingkat atas (divalidasi enum + Form Request + Policy).
        [$arguments, $stripped] = ArgumentValidator::stripProtectedFor($tool, $call->arguments);

        $json = (string) json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (mb_strlen($json) > (int) config('ai.agent.max_argument_chars', 4000)) {
            return $this->record($log, $call->name, ToolResult::invalid('Argumen terlalu panjang.'), [], $stripped);
        }

        $errors = $this->validator->validate($tool->parameters(), $arguments);
        $galat = $tool instanceof WriteDraftTool ? $galatPenyedia : [];

        if ($errors !== []) {
            // Tool tulis: kolom yang tidak lolos skema dibuang + dicatat sebagai galat kolom (draf "perlu diperbaiki"),
            // bukan dikembalikan ke LLM untuk ditanyakan balik. Tool baca / galat tak terpetakan: ditolak seperti biasa.
            $pisah = $tool instanceof WriteDraftTool ? PemisahArgumen::pisahkan($this->validator, $tool->parameters(), $arguments) : null;

            if ($pisah === null) {
                return $this->record($log, $call->name, ToolResult::invalid('Argumen tidak valid: '.implode(' ', array_slice($errors, 0, 8))), $catatArgumen ? $arguments : [], $stripped);
            }

            [$arguments, $galatSkema] = $pisah;
            $galat = GalatDraf::gabung($galatSkema, $galat);
            $log['galat_skema'] = (int) ($log['galat_skema'] ?? 0) + 1;
        }

        try {
            $result = $tool->execute($user, $arguments, $galat === [] ? $context : $context->denganGalatArgumen($galat));
        } catch (AuthorizationException) {
            $result = ToolResult::denied();
        } catch (DraftLimitException $e) {
            $result = ToolResult::denied($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $result = ToolResult::failed();
        }

        return $this->record($log, $call->name, $result, $catatArgumen ? $arguments : [], $stripped);
    }

    /**
     * Catat jejak tool (argumen diminimalkan: nilai teks dipotong, kunci rahasia disamarkan).
     *
     * @param  array<string, mixed>  $log
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $stripped
     */
    private function record(array &$log, string $name, ToolResult $result, array $arguments, array $stripped = []): ToolResult
    {
        if (count($log['tools']) < 20) {
            $log['tools'][] = array_filter([
                'nama' => mb_substr($name, 0, 64),
                'status' => $result->status,
                'argumen' => $this->redact($arguments),
                'argumen_dibuang' => $stripped,
                'draf' => $result->draft?->uuid,
            ], fn ($v) => $v !== [] && $v !== null);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function redact(array $arguments, int $depth = 0): array
    {
        $out = [];

        foreach ($arguments as $key => $value) {
            if (is_string($key) && preg_match('/token|password|sandi|secret|api_key|nik/i', $key) === 1) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = $depth >= 3 ? '[...]' : $this->redact($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = mb_substr($value, 0, 120);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Tahap 1 vision (D6): model vision TANPA tool menyalin teks gambar. Hasilnya data tak tepercaya
     * (dibersihkan & dibungkus nonce oleh IntentParser::userMessage). Gagal -> LlmException ramah.
     *
     * @param  array<string, mixed>  $log
     *
     * @throws LlmException
     */
    private function readImages(SupportsVision $vision, AiInput $input, string $caption, float $deadline, array &$log, AiInteraction $interaction): string
    {
        if (! $vision->supportsVision()) {
            throw LlmException::visionUnsupported();
        }

        $model = (string) $vision->visionModelName();
        $estimate = $this->estimasiGambar(count($input->images));
        $this->cekBatasGambar($model, $estimate);
        $this->budget->check($model, $estimate);
        // Mulai di sini gambar dikirim ke penyedia (juga bila panggilan gagal/timeout): privasi boleh dicatat.
        $log['media_terkirim'] = true;

        try {
            // Tanpa retry (opsi tanpa_ulang dipaksa klien): 429 langsung menjadi "Sedang ramai".
            $response = $vision->readImages($input->images, $this->parser->visionInstruction(), ['deadline' => $deadline, 'tanpa_ulang' => true]);
        } catch (LlmException $e) {
            $this->catatGalatLlm($e, $interaction, $model, 'vision', $log, $estimate, 0, false);
            // Model tidak ada/dihentikan -> arahan Mini App; sibuk/kuota/konfigurasi/terlalu besar tetap; lain = tak terbaca.
            throw LlmException::untukVision($e);
        }

        $this->recordUsage($model, $response, $estimate, $log, 'token_vision');
        $text = $this->parser->sanitize($response->text, (int) config('ai.agent.max_input_chars', 2000));
        $log['panjang_hasil_baca'] = mb_strlen($text);

        if ($text === '' || stripos($text, IntentParser::PENANDA_TIDAK_TERBACA) === 0) {
            $log['vision'] = 'tidak_terbaca';

            throw $text === '' ? LlmException::visionFailed() : LlmException::fotoBuram();
        }

        if (stripos($text, IntentParser::PENANDA_BUKAN_DOKUMEN) === 0) {
            $log['vision'] = 'bukan_dokumen';

            // Tanpa keterangan, foto non-dokumen tidak punya maksud yang jelas: balas ramah tanpa memanggil model teks.
            if (trim($caption) === '') {
                throw LlmException::bukanDokumen();
            }

            $text = 'Bukan dokumen: '.trim(substr($text, strlen(IntentParser::PENANDA_BUKAN_DOKUMEN)));
        }

        return $text;
    }

    /** Estimasi token satu permintaan vision: instruksi + gambar (TokenBudget::imageTokens per gambar). */
    private function estimasiGambar(int $jumlah = 1): int
    {
        return TokenBudget::estimate($this->parser->visionInstruction()) + max(1, $jumlah) * TokenBudget::imageTokens();
    }

    /**
     * Pelindung token global untuk foto, dicek AiChatHandler SEBELUM foto diunduh (tanpa menghitung): bila sisa kuota
     * menit/hari model vision (atau model teks untuk klien tanpa vision terpisah) tidak cukup untuk satu gambar,
     * LlmException sibuk/kuota dilempar tanpa unduhan maupun panggilan penyedia.
     *
     * @throws LlmException
     */
    public function cekAnggaranGambar(): void
    {
        $model = $this->llm instanceof SupportsVision ? (string) $this->llm->visionModelName() : $this->llm->modelName();

        if ($model !== '') {
            $estimate = $this->estimasiGambar();
            $this->cekBatasGambar($model, $estimate);
            $this->budget->check($model, $estimate);
        }
    }

    /**
     * Salah konfigurasi: AI_TOKEN_PER_MINUTE / AI_TOKEN_PER_DAY (> 0) lebih kecil dari biaya SATU gambar, sehingga foto
     * tidak akan pernah lolos. Bukan "sedang ramai": peringatan konfigurasi dicatat (angka saja) dan pengguna diarahkan
     * ke Mini App (kategori vision_tidak_tersedia).
     *
     * @throws LlmException
     */
    private function cekBatasGambar(string $model, int $estimate): void
    {
        foreach (['per_minute' => 'AI_TOKEN_PER_MINUTE', 'per_day' => 'AI_TOKEN_PER_DAY'] as $kunci => $env) {
            $batas = max(0, (int) config("ai.token_budget.{$kunci}", 0));

            if ($batas > 0 && $estimate > $batas) {
                Log::warning('ai.konfigurasi_token_gambar', [
                    'variabel' => $env,
                    'batas_token' => $batas,
                    'estimasi_gambar' => $estimate,
                    'model' => mb_substr($model, 0, 100),
                ]);

                throw LlmException::anggaranGambarTidakCukup();
            }
        }
    }

    /**
     * Catat pemakaian token (angka saja, tanpa isi prompt) ke log interaksi dan pelindung token global.
     *
     * @param  array<string, mixed>  $log
     */
    private function recordUsage(string $model, LlmResponse $response, int $estimate, array &$log, string $key = 'token'): void
    {
        $used = $response->inputTokens + $response->outputTokens;
        $this->budget->record($model, $used > 0 ? $used : $estimate);

        $log[$key] = [
            'masuk' => (int) ($log[$key]['masuk'] ?? 0) + $response->inputTokens,
            'keluar' => (int) ($log[$key]['keluar'] ?? 0) + $response->outputTokens,
            'estimasi' => (int) ($log[$key]['estimasi'] ?? 0) + $estimate,
        ];
    }

    /**
     * True bila batas laju AI pengguna sudah tercapai (TANPA menghitung). Dipakai AiChatHandler sebelum mengunduh
     * foto / mentranskripsi suara agar biaya media tidak terjadi untuk pesan yang pasti ditolak.
     */
    public function batasLajuTercapai(User $user): bool
    {
        $id = $user->getKey();

        return RateLimiter::tooManyAttempts("ai:m:{$id}", max(1, (int) config('ai.rate_limit.per_minute', 20)))
            || RateLimiter::tooManyAttempts("ai:h:{$id}", max(1, (int) config('ai.rate_limit.per_hour', 30)));
    }

    private function withinRateLimit(User $user): bool
    {
        $id = $user->getKey();
        $perMinute = max(1, (int) config('ai.rate_limit.per_minute', 20));
        $perHour = max(1, (int) config('ai.rate_limit.per_hour', 30));

        if (RateLimiter::tooManyAttempts("ai:m:{$id}", $perMinute) || RateLimiter::tooManyAttempts("ai:h:{$id}", $perHour)) {
            return false;
        }

        RateLimiter::hit("ai:m:{$id}", 60);
        RateLimiter::hit("ai:h:{$id}", 3600);

        return true;
    }

    /** Teks polos aman: tanpa karakter kontrol dan penanda Markdown mentah, dipotong ke batas keluaran. */
    private function cleanOutput(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = trim(self::tanpaMarkdown($text));
        $max = (int) config('ai.agent.max_output_chars', 3500);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    /**
     * Penanda Markdown mentah dari jawaban LLM dibuang (balasan dikirim tanpa parse_mode, jadi penanda akan tampil
     * apa adanya): **tebal**, __tebal__, `kode`, ```blok```, heading `#`, dan *miring* berpasangan. Isi teks tetap; tanda
     * hubung daftar ("- ") dan tanda bintang tunggal (mis. "2 * 3") dipertahankan.
     */
    public static function tanpaMarkdown(string $text): string
    {
        $text = (string) preg_replace('/^[ \t]*```[^\n]*\n?/mu', '', $text);
        $text = (string) preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+/mu', '', $text);
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/su', '$1', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/su', '$1', $text);
        $text = (string) preg_replace('/`([^`\n]+)`/u', '$1', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}*])\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*(?![\p{L}\p{N}*])/u', '$1', $text);

        return str_replace(['**', '```'], '', $text);
    }

    /**
     * @param  array<string, mixed>  $log
     */
    private function finish(AiInteraction $interaction, float $started, array $log, HasilAi $hasil, ?string $error, AiReply $reply): AiReply
    {
        $names = array_values(array_unique(array_column($log['tools'], 'nama')));

        if (($log['media_terkirim'] ?? false) === true && ! $reply->mediaTerkirim) {
            $reply = new AiReply($reply->text, $reply->drafts, $reply->fallback, $reply->interactionId, true, $reply->tunda);
        }

        $interaction->forceFill([
            'tool_name' => $names === [] ? null : mb_substr(implode(',', $names), 0, 100),
            'argumen' => $log,
            'latensi_ms' => (int) round((microtime(true) - $started) * 1000),
            'hasil' => $hasil,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
        ])->save();

        $this->logPesan($interaction, $log, $hasil, $error, $reply);

        return $reply;
    }

    /**
     * SATU baris log per pesan Agen AI ('ai.pesan'): hasil (draf_dibuat | tanpa_draf | galat), jumlah iterasi, token
     * terpakai (angka), jenis masukan, sumber balasan, dan kode galat bertipe tetap. TANPA isi pesan, hasil baca foto,
     * nama, argumen, atau JSON (UU PDP). Level dari config ai.agent.log_level (bawaan info).
     *
     * @param  array<string, mixed>  $log
     */
    private function logPesan(AiInteraction $interaction, array $log, HasilAi $hasil, ?string $error, AiReply $reply): void
    {
        $status = match (true) {
            $reply->drafts !== [] => 'draf_dibuat',
            $hasil === HasilAi::Gagal || ($error !== null && str_starts_with($error, 'llm:')) => 'galat',
            default => 'tanpa_draf',
        };
        $vision = (array) ($log['token_vision'] ?? []);
        $jenis = $interaction->jenis_input;
        $level = (string) config('ai.agent.log_level', 'info');

        Log::log(in_array($level, ['debug', 'info', 'notice', 'warning', 'error'], true) ? $level : 'info', 'ai.pesan', array_filter([
            'interaksi_id' => $interaction->id,
            'jenis' => $jenis instanceof \BackedEnum ? $jenis->value : (is_string($jenis) ? mb_substr($jenis, 0, 16) : null),
            'hasil' => $status,
            'iterasi' => (int) ($log['iterasi'] ?? 0),
            'token' => (int) ($log['token_pesan'] ?? 0),
            'token_vision' => (int) ($vision['masuk'] ?? 0) + (int) ($vision['keluar'] ?? 0),
            'draf' => count($reply->drafts),
            'balasan' => $log['balasan'] ?? ($error === null && $reply->drafts === [] ? 'model' : 'server'),
            'ditunda' => $reply->tunda !== null ? 1 : null,
            'koreksi' => isset($log['koreksi_tool_teks']) ? 1 : null,
            'disaring' => isset($log['balasan_disaring']) ? (int) $log['balasan_disaring'] : null,
            // Kode tetap buatan server (kategori LLM / nama kelas / label tanpa_draf); tanpa isi pesan.
            'kode' => $error === null ? null : mb_substr((string) preg_replace('/[^A-Za-z0-9_:=() .-]/', '', $error), 0, 80),
        ], fn ($v) => $v !== null));
    }
}

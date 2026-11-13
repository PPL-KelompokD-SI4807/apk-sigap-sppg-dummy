<?php

namespace App\Services\Ai\Tools;

use App\Enums\AksiAiDraft;
use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Drafts\DraftService;
use App\Services\Ai\Drafts\DraftValidationResult;
use App\Services\Ai\Drafts\DraftValidator;
use App\Services\Ai\Drafts\GalatDraf;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Tool tulis (buat_..., ubah_..., hapus_...): TIDAK PERNAH menulis data modul. Alurnya:
 *   argumen tervalidasi skema -> argumen terlindungi dibuang -> target (ubah/hapus) dicari lewat
 *   SppgScope + Policy -> payload divalidasi dry-run dengan Form Request modul (DraftValidator)
 *   -> AiDraft pending (berlaku config ai.drafts.ttl_minutes).
 * Masukan salah (galat skema dari AiAgentService, galat rujukan, galat Form Request) TIDAK dikembalikan ke LLM untuk
 * ditanyakan ulang: draf dibuat dari bagian yang valid, kolom bergalat dikosongkan dan dicatat (GalatDraf), lalu
 * ToolResult::perbaiki menghentikan loop agen; bot mengarahkan pengguna ke formulir Mini App.
 * Data tersimpan hanya setelah KONFIRMASI MANUSIA: tombol Simpan di Mini App (endpoint modul + ai_draft_id), atau
 * tombol Lanjutkan di chat Bot bila tool punya eksekusi() (AiDraftExecutor memanggil endpoint REST yang sama sebagai
 * sub-request atas nama pengguna penekan tombol; Policy + Form Request + Service dijalankan ulang).
 *
 * Subkelas mengisi $name, $description, $module, $action (C/U/D), $model, lalu:
 *   formRequest(): kelas Form Request store/update modul (null untuk hapus)
 *   fields(): ['properties' => [...], 'required' => [...]] skema kolom form
 *   routeParameter(): nama parameter rute model untuk Form Request update (mis. 'titikLayanan')
 *   rujukan(): opsional, nama data master -> ID di server (ResolverReferensi); ambigu = pilihan tertunda (tombol),
 *              tidak ditemukan / satuan salah = galat per kolom (draf "perlu diperbaiki", GalatDraf)
 *   toPayload(): opsional, memetakan argumen ke kolom form (mis. nama pemasok -> pemasok_id)
 *   eksekusi(): opsional, rute REST untuk menjalankan draf dari chat (null = hanya Mini App)
 *   ringkasan()/berbahaya()/olahHasil(): opsional, layar konfirmasi dan hasil
 */
abstract class WriteDraftTool extends BaseAiTool
{
    protected string $action = 'C';

    final public function isWrite(): bool
    {
        return true;
    }

    /** @return class-string<FormRequest>|null */
    abstract protected function formRequest(): ?string;

    /**
     * @return array{properties: array<string, array<string, mixed>>, required?: list<string>}
     */
    abstract protected function fields(): array;

    protected function routeParameter(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function toPayload(User $user, array $arguments): array
    {
        return $arguments;
    }

    /**
     * Hook opsional resolusi rujukan nama -> ID di SERVER (App\Services\Ai\Referensi\ResolverReferensi), dipanggil
     * sebelum toPayload(). Kembalikan ToolResult untuk berhenti TANPA draf (hanya untuk penolakan hak, mis. hak baca
     * data master tidak ada), atau [argumen, slot pilihan tertunda, info untuk model, galat per kolom]. Slot pilihan
     * (rujukan ambigu) disimpan di payload `_pilihan` (hanya ID kandidat) dan dipilih pengguna lewat tombol. Galat per
     * kolom (nama tidak ditemukan, satuan tak cocok, angka tidak valid) membuat draf "perlu diperbaiki" (GalatDraf):
     * baris/kolom bermasalah dikosongkan, pengguna memperbaikinya di Mini App, LLM tidak ditanya lagi.
     *
     * @param  array<string, mixed>  $arguments
     * @return ToolResult|array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3?: array<string, list<string>>}
     */
    protected function rujukan(User $user, array $arguments, ToolContext $context): ToolResult|array
    {
        return [$arguments, [], [], []];
    }

    /**
     * Hook opsional setelah validasi dry-run, sebelum draf disimpan: sesuaikan payload/keyakinan
     * (mis. kolom yang disimpulkan tool dari teks = keyakinan rendah, penanda sub-modul draf).
     * Tidak boleh menulis data modul. Bawaan: tidak mengubah apa pun.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, float>  $confidence
     * @param  array<string, list<string>>  $errors
     * @return array{0: array<string, mixed>, 1: array<string, float>} [payload, keyakinan]
     */
    protected function afterValidation(array $payload, array $confidence, array $errors): array
    {
        return [$payload, $confidence];
    }

    /**
     * Hook opsional: sisipkan nilai yang HARUS berasal dari server (bukan LLM) ke payload, mis. `versi` record
     * target. Dipanggil setelah argumen terlindungi dibuang dan SEBELUM validasi dry-run.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sisipkanDariServer(array $payload, ?Model $target): array
    {
        return $payload;
    }

    /** Cari record target lewat query ber-SppgScope (null = tidak ditemukan / SPPG lain). */
    protected function findTarget(User $user, int $id): ?Model
    {
        return $this->model === null ? null : $this->model::query()->find($id);
    }

    /** Policy per record target untuk draf ubah/hapus (bawaan: Policy update/delete model). */
    protected function izinkanTarget(User $user, Model $target): bool
    {
        return $this->allows($user, $this->action === 'U' ? 'update' : 'delete', $target);
    }

    /**
     * Pemeriksaan bisnis tambahan saat draf dibuat (pesan ramah untuk model), mis. Admin tidak dapat menonaktifkan
     * dirinya sendiri. Null = lolos. Tidak menggantikan aturan Service yang tetap dijalankan saat eksekusi.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function tolakDraf(User $user, ?Model $target, array $payload): ?string
    {
        return null;
    }

    /** Penanda sub-modul pada draf (`_resource`), null = sumber daya bawaan modul. */
    public function draftResource(): ?string
    {
        return null;
    }

    /**
     * Draf wajib ber-SPPG (peran satu-SPPG). False hanya untuk tool lintas SPPG milik Admin (mis. modul pengguna),
     * yang drafnya tidak terikat SPPG. Tool modul ber-tenant untuk Admin (memilih SPPG tujuan) = titik ekstensi tahap 2.
     */
    protected function butuhSppg(): bool
    {
        return true;
    }

    /** Cara menjalankan draf dari tombol konfirmasi Bot; null = hanya lewat Mini App ("Periksa & Simpan"). */
    public function eksekusi(): ?SpesifikasiEksekusi
    {
        return null;
    }

    /** Aksi berbahaya -> konfirmasi DUA LANGKAH + peringatan tegas. Bawaan: semua hapus. */
    public function berbahaya(AiDraft $draft): bool
    {
        return $this->action === 'D';
    }

    /** Label singkat sumber daya untuk ringkasan ("pemasok", "titik layanan"). */
    protected function labelSumberDaya(): string
    {
        return (string) config("rbac.modules.{$this->module}.label", $this->module);
    }

    /**
     * Ringkasan konfirmasi dari payload draf TERSIMPAN + nama target dari basis data (dipanggil di dalam
     * ActingUser::run). Bawaan: daftar kolom payload "Kolom: nilai" (teks polos, dipotong).
     */
    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $judul = match ($this->action) {
            'U' => 'Ubah '.$this->labelSumberDaya(),
            'D' => 'Hapus '.$this->labelSumberDaya(),
            default => 'Buat '.$this->labelSumberDaya(),
        };

        $target = null;

        if ($draft->target_id !== null) {
            $record = $this->findTarget($user, (int) $draft->target_id);
            $target = $record === null ? '#'.(int) $draft->target_id.' (tidak ditemukan)' : $this->namaTarget($record);
        }

        $baris = [];

        foreach ((array) $draft->payload as $kunci => $nilai) {
            if (! is_string($kunci) || str_starts_with($kunci, '_') || in_array($kunci, ['versi', 'ai_draft_id'], true) || count($baris) >= 12) {
                continue;
            }

            $baris[] = self::labelKolom($kunci).': '.(in_array($kunci, $this->kolomTersembunyi(), true) && is_string($nilai)
                ? '(tidak ditampilkan di chat, '.mb_strlen($nilai).' karakter)'
                : self::nilaiTeks($nilai));
        }

        return new RingkasanDraf($judul, $baris, $target, $this->action === 'D' ? 'Data akan dihapus.' : null);
    }

    /**
     * Satu kalimat ringkasan draf untuk balasan chat Agen AI (dibangun SERVER, dipanggil di dalam ActingUser::run),
     * TANPA id internal dan skor keyakinan. Bawaan: jenis aksi + label sumber daya (+ nama target tanpa "#id").
     * Subkelas boleh menambah isian penting (mis. pembelian: bahan, jumlah, harga, total, pemasok).
     */
    public function kalimatDraf(User $user, AiDraft $draft): string
    {
        $label = self::satuBaris($this->labelSumberDaya(), 60);
        $teks = match ($this->action) {
            'U' => 'Draf ubah '.$label,
            'D' => 'Draf hapus '.$label,
            default => 'Draf '.$label.' baru',
        };
        $target = $draft->target_id === null ? null : $this->ringkasan($user, $draft)->target;
        $target = $target === null ? '' : trim((string) preg_replace('/\s*\(#\d+\)\s*$/u', '', $target));

        if ($target !== '' && preg_match('/#\d+/u', $target) !== 1) {
            $teks .= ': '.self::satuBaris($target, 80);
        }

        return $teks;
    }

    /** Kolom teks bebas yang dapat memuat data pribadi: tidak ditampilkan di ringkasan chat. */
    protected function kolomTersembunyi(): array
    {
        return [];
    }

    /** Nama tampilan record target (dari basis data). */
    protected function namaTarget(Model $record): string
    {
        foreach (['nama', 'name', 'nama_menu', 'plat_nomor', 'kode'] as $kolom) {
            $nilai = $record->getAttribute($kolom);

            if (is_string($nilai) && self::satuBaris($nilai) !== '') {
                return self::satuBaris($nilai, 80).' (#'.$record->getKey().')';
            }
        }

        return '#'.$record->getKey();
    }

    /**
     * Sidik keadaan target (diisi server saat draf ubah/hapus dibuat dan dihitung ulang saat dijalankan). Berbeda =
     * data berubah sejak draf dibuat -> draf tidak dijalankan. Bawaan: versi (bila ada) + updated_at.
     */
    protected function sidikTarget(Model $target): ?string
    {
        $diubah = $target->getAttribute('updated_at');

        return substr(hash('sha256', implode('|', [
            $target->getKey(),
            (string) $target->getAttribute('versi'),
            $diubah instanceof CarbonInterface ? $diubah->getTimestamp() : (string) $diubah,
        ])), 0, 16);
    }

    /**
     * Sidik target SAAT INI untuk draf ubah/hapus (dipanggil di dalam ActingUser::run): null = draf tanpa target,
     * false = target tidak ditemukan / di luar scope.
     */
    public function sidikTargetSaatIni(User $user, AiDraft $draft): string|false|null
    {
        if ($draft->target_id === null) {
            return null;
        }

        $target = $this->findTarget($user, (int) $draft->target_id);

        if ($target === null) {
            return false;
        }

        return $this->izinkanTarget($user, $target) ? (string) $this->sidikTarget($target) : false;
    }

    /**
     * Body sub-request eksekusi: payload TERSIMPAN disaring ke kunci aturan Form Request (bila ada), tanpa kunci
     * cadangan draf (`_resource`, `_peringatan`).
     *
     * @return array<string, mixed>
     */
    public function bodyEksekusi(AiDraft $draft, ?DraftValidationResult $cek): array
    {
        $payload = (array) $draft->payload;
        $payload = $cek === null ? ($this->formRequest() === null ? [] : $payload) : $cek->saring($payload);

        return array_filter($payload, fn ($kunci) => ! is_string($kunci) || ! str_starts_with($kunci, '_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Olah respons sukses sub-request: [hasil yang BOLEH disimpan (id/status saja), pesan rahasia untuk pengguna
     * atau null]. Bawaan: id data hasil bila ada, tanpa rahasia.
     *
     * @param  array<string, mixed>  $json
     * @return array{0: array<string, int|string|bool|null>, 1: ?string}
     */
    public function olahHasil(array $json, AiDraft $draft): array
    {
        $id = $json['data']['id'] ?? null;

        return [is_int($id) ? ['id' => $id] : [], null];
    }

    /**
     * Batalkan hasil aksi yang sudah dijalankan bila rahasianya gagal sampai ke pengguna (dipanggil di dalam
     * ActingUser::run + sumber audit `ai`). Bawaan: tidak didukung (false).
     */
    public function cabutHasil(User $user, AiDraft $draft): bool
    {
        return false;
    }

    public function draftAction(): AksiAiDraft
    {
        return match ($this->action) {
            'U' => AksiAiDraft::Update,
            'D' => AksiAiDraft::Delete,
            default => AksiAiDraft::Create,
        };
    }

    public function parameters(): array
    {
        $fields = $this->fields();
        $properties = $fields['properties'];
        $required = $fields['required'] ?? [];

        if ($this->action !== 'C') {
            $properties = ['target_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID data yang akan diubah/dihapus (dari tool cari_*).']] + $properties;
            $required = array_values(array_unique(array_merge(['target_id'], $required)));
        }

        $properties['keyakinan'] = [
            'type' => 'object',
            'description' => 'Opsional. Keyakinan 0-1 per nama kolom yang Anda isi (mis. {"nama": 0.9}). Beri nilai rendah bila tulisan nota/suara kurang jelas.',
            'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    /**
     * Draf butuh SPPG untuk peran satu-SPPG; Admin tidak ditawari tool tulis modul ber-tenant (butuhSppg).
     * Investor (R-06a) baca saja: tidak pernah ditawari tool tulis walau matriks salah isi.
     */
    public function isAvailableFor(User $user): bool
    {
        if ($user->isInvestor()) {
            return false;
        }

        if ($this->butuhSppg() && $user->sppg_id === null) {
            return false;
        }

        return parent::isAvailableFor($user);
    }

    /**
     * Validasi ulang draf tersimpan (GET /ai-drafts/{uuid}, eksekusi Bot) dengan aturan Form Request terkini.
     * Null bila modul tidak memakai Form Request (hapus).
     */
    public function revalidate(User $user, AiDraft $draft): ?DraftValidationResult
    {
        if ($this->formRequest() === null) {
            return null;
        }

        $routeParameters = [];

        if ($draft->target_id !== null && $this->routeParameter() !== null) {
            $target = $this->findTarget($user, (int) $draft->target_id);

            if ($target === null) {
                return new DraftValidationResult(false);
            }

            $routeParameters = [$this->routeParameter() => $target];
        }

        return app(DraftValidator::class)->validate($this->formRequest(), (array) $draft->payload, $user, $routeParameters, (array) $draft->confidence);
    }

    final public function execute(User $user, array $arguments, ToolContext $context): ToolResult
    {
        if (! $this->isAvailableFor($user)) {
            return ToolResult::denied();
        }

        $confidence = is_array($arguments['keyakinan'] ?? null) ? $arguments['keyakinan'] : [];
        unset($arguments['keyakinan']);

        $target = null;

        if ($this->action !== 'C') {
            $target = $this->findTarget($user, (int) ($arguments['target_id'] ?? 0));
            unset($arguments['target_id']);

            if ($target === null) {
                return ToolResult::invalid('Data yang dimaksud tidak ditemukan.');
            }

            if (! $this->izinkanTarget($user, $target)) {
                return ToolResult::denied('Anda tidak berhak mengubah/menghapus data ini.');
            }
        }

        $rujukan = $this->rujukan($user, $arguments, $context);

        if ($rujukan instanceof ToolResult) {
            return $rujukan;
        }

        [$arguments, $pilihan, $infoPilihan] = $rujukan;
        // Galat server: argumen tidak lolos skema / ditolak penyedia (kolomnya sudah dibuang di AiAgentService) + rujukan.
        $galat = GalatDraf::gabung(GalatDraf::bersihkan($context->galatArgumen), GalatDraf::bersihkan($rujukan[3] ?? []));

        [$payload] = ArgumentValidator::stripProtectedFor($this, $this->toPayload($user, $arguments));
        $payload = GalatDraf::kosongkan($payload, $galat);

        $payload = $this->sisipkanDariServer($payload, $target);

        if (($tolak = $this->tolakDraf($user, $target, $payload)) !== null) {
            return ToolResult::invalid($tolak);
        }

        $errors = [];
        $scores = [];

        if ($this->formRequest() !== null) {
            $routeParameters = $target !== null && $this->routeParameter() !== null ? [$this->routeParameter() => $target] : [];
            $result = app(DraftValidator::class)->validate($this->formRequest(), $payload, $user, $routeParameters, $confidence);

            if (! $result->authorized) {
                return ToolResult::denied();
            }

            $errors = $result->errors;
            $scores = $result->confidence;
            // Hanya kunci yang diatur Form Request yang disimpan di draf (kunci isian server dibuang).
            $payload = $result->saring($payload);
            $dipertahankan = array_merge(array_keys($payload), array_map(fn ($k) => explode('.', (string) $k)[0], array_keys($errors)), array_map(fn ($k) => explode('.', (string) $k)[0], array_keys($galat)));
            $scores = array_intersect_key($scores, array_flip($dipertahankan));

            // Galat Form Request (selain baris placeholder pilihan tertunda, yang dipilih lewat tombol) = perlu diperbaiki.
            $galat = GalatDraf::gabung($galat, GalatDraf::bersihkan(self::tanpaGalatSlot($errors, $pilihan)));
            $payload = GalatDraf::kosongkan($payload, $galat);
        }

        [$payload, $scores] = $this->afterValidation($payload, $scores, $errors);

        if (($resource = $this->draftResource()) !== null) {
            $payload['_resource'] = $resource;
        }

        // Rujukan ambigu: disimpan server (ID kandidat saja), dipilih pengguna lewat tombol; draf tidak dapat dijalankan
        // dari chat selama masih ada pilihan tertunda (AiDraftExecutor::hambatan).
        if ($pilihan !== []) {
            $payload[PilihanReferensiDraf::KUNCI] = $pilihan;
        }

        if ($galat !== []) {
            $payload[GalatDraf::KUNCI] = $galat;
        }

        $draft = app(DraftService::class)->create(
            user: $user,
            module: $this->module,
            action: $this->draftAction(),
            payload: $payload,
            confidence: $scores,
            interactionId: $context->interactionId,
            targetId: $target?->getKey(),
            tool: $this->name(),
            sidikTarget: $target === null ? null : $this->sidikTarget($target),
        );

        // Masukan salah: berhenti di server (tanpa bertanya balik lewat LLM); pengguna memperbaiki di Mini App.
        if ($galat !== []) {
            return ToolResult::perbaiki($draft, $galat);
        }

        return ToolResult::draft($draft, self::tanpaGalatSlot($errors, $pilihan), $pilihan === [] && ($this->eksekusi()?->dapatDijalankan() ?? false), $infoPilihan);
    }

    /**
     * Galat untuk hasil tool ke LLM: galat baris placeholder slot pilihan tertunda (mis. `bahan.1.bahan_baku_id` wajib
     * diisi) dibuang karena pengguna memilihnya lewat tombol; LLM tidak perlu (dan tidak boleh) mengisinya sendiri.
     * Galat lengkap tetap ada di draf/API (AiDraftResource `errors`, kontrak Mini App).
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<array<string, mixed>>  $pilihan
     * @return array<string, list<string>>
     */
    private static function tanpaGalatSlot(array $errors, array $pilihan): array
    {
        $awalan = [];

        foreach ($pilihan as $slot) {
            if (is_string($slot['kolom'] ?? null) && is_int($slot['indeks'] ?? null)) {
                $awalan[] = $slot['kolom'].'.'.$slot['indeks'];
            }
        }

        return array_filter($errors, function ($kunci) use ($awalan): bool {
            foreach ($awalan as $a) {
                if ((string) $kunci === $a || str_starts_with((string) $kunci, $a.'.')) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    /** Akses Gate untuk subkelas (Policy kelas/record). */
    protected function gate(User $user): \Illuminate\Contracts\Auth\Access\Gate
    {
        return Gate::forUser($user);
    }

    private static function labelKolom(string $kunci): string
    {
        $teks = str_replace('_', ' ', preg_replace('/_id$/', '', $kunci) ?? $kunci);

        return mb_strtoupper(mb_substr($teks, 0, 1)).mb_substr($teks, 1);
    }

    private static function nilaiTeks(mixed $nilai): string
    {
        $teks = match (true) {
            $nilai === null => '-',
            is_bool($nilai) => $nilai ? 'Ya' : 'Tidak',
            is_scalar($nilai) => (string) $nilai,
            default => (string) json_encode($nilai, JSON_UNESCAPED_UNICODE),
        };

        return self::satuBaris($teks, 120);
    }

    /**
     * Teks tampilan SATU baris: karakter kontrol/format (baris baru, tab, RLO/LRO dan kendali bidi lain, zero-width) dan
     * spasi beruntun menjadi satu spasi, lalu dipotong. Mencegah nilai dari DB/model memalsukan baris layar konfirmasi.
     */
    public static function satuBaris(string $teks, int $maks = 200): string
    {
        $teks = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $teks));

        return mb_strlen($teks) > $maks ? rtrim(mb_substr($teks, 0, $maks - 1)).'…' : $teks;
    }
}

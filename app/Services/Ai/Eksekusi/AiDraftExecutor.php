<?php

namespace App\Services\Ai\Eksekusi;

use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Catalog\DraftToolResolver;
use App\Services\Ai\Drafts\GalatDraf;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use App\Services\Ai\Tools\WriteDraftTool;
use App\Services\Auth\SesiService;
use App\Support\SppgAktif;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menjalankan AiDraft setelah KONFIRMASI MANUSIA lewat tombol Bot (Lanjutkan / "Ya, yakin"). Generik untuk semua
 * peran dan modul: hak SELALU dihitung ulang dari pengguna penekan tombol (bukan dari isi draf atau callback):
 *
 *   1. pengguna dimuat ulang dari basis data; aktif + punya sesi login penuh (tanpa sesi: TANPA_SESI, draf tetap);
 *   2. draf milik pengguna itu, status terbuka sesuai langkah, belum kedaluwarsa (TTL ai.drafts.ttl_minutes);
 *   3. tool pembuat draf (ai_drafts.tool) masih tersedia untuk peran SAAT INI (Rbac + unit relawan + Policy);
 *   4. draf dapat dijalankan dari chat (rute REST, tanpa berkas, tanpa isian berkeyakinan rendah);
 *   5. aksi berbahaya: DUA LANGKAH (pending -> menunggu_konfirmasi_ulang -> dijalankan); lompat langkah ditolak;
 *   6. di dalam transaksi + lockForUpdate baris draf (klik ganda/balapan dengan Mini App diserialkan): status dicek
 *      ulang, sidik target dibandingkan (data berubah -> BERUBAH), Form Request dijalankan ulang (dry-run), lalu SATU
 *      sub-request REST (PermintaanInternal) dengan payload TERSIMPAN yang disaring ke kunci aturan Form Request;
 *      stack REST menjalankan Policy + Form Request + Service lagi. Non-2xx -> rollback, draf `gagal` (kecuali 429).
 *   7. audit `ai_eksekusi_draf` bersumber `ai` (tool, aksi, kode hasil, status HTTP, id hasil) tanpa token/rahasia;
 *      perubahan model di dalam sub-request juga tercatat bersumber `ai` (AuditLog::withSource).
 * Rahasia hasil (token undangan) hanya dikembalikan di HasilEksekusi::$rahasia (memori) untuk pesan langsung.
 */
class AiDraftExecutor
{
    public const LANJUT = 'lanjut';

    public const YAKIN = 'yakin';

    public function __construct(
        private readonly DraftToolResolver $resolver,
        private readonly SesiService $sesi,
        private readonly PermintaanInternal $permintaan,
    ) {}

    /** Tool pembuat draf bila draf ini memakai layar konfirmasi chat (tool punya eksekusi()), selain itu null. */
    public function toolUntuk(AiDraft $draft): ?WriteDraftTool
    {
        $tool = $this->resolver->resolve($draft);

        return $tool?->eksekusi() === null ? null : $tool;
    }

    /** @return list<string> kolom berkeyakinan rendah (mis. salah dengar suara) */
    public function perluDicek(AiDraft $draft): array
    {
        $ambang = (float) config('ai.drafts.low_confidence', 0.7);

        return array_values(array_map('strval', array_keys(array_filter((array) ($draft->confidence ?? []), fn ($c) => is_numeric($c) && (float) $c < $ambang))));
    }

    /**
     * Hambatan menjalankan draf dari chat TANPA efek (null = boleh): TIDAK_BERLAKU, DITOLAK, HANYA_MINIAPP.
     * Dipakai layar konfirmasi (tombol Lanjutkan hanya tampil bila null) dan setiap klik.
     */
    public function hambatan(User $user, AiDraft $draft, ?WriteDraftTool $tool): ?string
    {
        if ((int) $draft->user_id !== (int) $user->getKey() || ! $this->sppgCocok($user, $draft) || ! $draft->bisaDikonfirmasi() || $tool === null) {
            return HasilEksekusi::TIDAK_BERLAKU;
        }

        if (! $user->canAuthenticate() || ! $tool->isAvailableFor($user)) {
            return HasilEksekusi::DITOLAK;
        }

        // Rujukan nama yang masih ambigu (payload `_pilihan`) harus dipilih pengguna lebih dulu, dan draf "perlu
        // diperbaiki" (payload `_galat`: kolom dikosongkan karena masukan salah) hanya diselesaikan di Mini App.
        if (! ($tool->eksekusi()?->dapatDijalankan() ?? false) || $this->perluDicek($draft) !== [] || PilihanReferensiDraf::tertunda($draft) !== []
            || GalatDraf::ada($draft)) {
            return HasilEksekusi::HANYA_MINIAPP;
        }

        return null;
    }

    /**
     * SPPG draf harus sama dengan SPPG pengguna SAAT INI (aturan sama dengan DraftService::ownQuery): Admin = draf tanpa
     * SPPG; peran lain = users.sppg_id sekarang. Pengguna yang dipindah SPPG setelah draf dibuat tidak dapat menjalankannya.
     */
    private function sppgCocok(User $user, AiDraft $draft): bool
    {
        if ($user->isAdmin()) {
            return $draft->sppg_id === null;
        }

        return $user->sppg_id !== null && ! $user->isInvestor() && $draft->sppg_id !== null && (int) $draft->sppg_id === (int) $user->sppg_id;
    }

    /**
     * Satu langkah konfirmasi dari tombol Bot. $langkah = LANJUT (tombol Lanjutkan) atau YAKIN ("Ya, yakin").
     */
    public function langkah(User $penekan, int $draftId, string $langkah): HasilEksekusi
    {
        $user = User::query()->withoutGlobalScopes()->find($penekan->getKey());

        if (! $user instanceof User || ! $user->canAuthenticate()) {
            return new HasilEksekusi(HasilEksekusi::DITOLAK);
        }

        if (! $this->sesi->punyaSesiPenuh($user)) {
            return new HasilEksekusi(HasilEksekusi::TANPA_SESI);
        }

        $draft = AiDraft::query()->withoutGlobalScopes()->find($draftId);

        if ($draft === null || (int) $draft->user_id !== (int) $user->getKey()) {
            return new HasilEksekusi(HasilEksekusi::TIDAK_BERLAKU);
        }

        $tool = $this->toolUntuk($draft);
        $hambatan = $this->hambatan($user, $draft, $tool);

        if ($hambatan !== null) {
            return $this->tolakSebelumJalan($user, $draft, $tool, $hambatan);
        }

        /** @var WriteDraftTool $tool */
        $berbahaya = $tool->berbahaya($draft);

        if ($langkah === self::LANJUT && $berbahaya) {
            $diklaim = AiDraft::query()->withoutGlobalScopes()->whereKey($draft->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', StatusAiDraft::Pending->value)
                ->where('expires_at', '>', now())
                ->update(['status' => StatusAiDraft::MenungguKonfirmasiUlang->value, 'konfirmasi_pertama_at' => now(), 'updated_at' => now()]) === 1;

            return $diklaim
                ? new HasilEksekusi(HasilEksekusi::KONFIRMASI_ULANG, draft: $draft->refresh())
                : new HasilEksekusi(HasilEksekusi::TIDAK_BERLAKU);
        }

        // "Ya, yakin" hanya untuk aksi berbahaya yang SUDAH melewati langkah 1; Lanjutkan hanya untuk aksi biasa.
        if ($langkah === self::YAKIN && ! $berbahaya) {
            return new HasilEksekusi(HasilEksekusi::TIDAK_BERLAKU);
        }

        return $this->jalankan($user, (int) $draft->getKey(), $berbahaya ? StatusAiDraft::MenungguKonfirmasiUlang : StatusAiDraft::Pending);
    }

    /**
     * Cabut hasil aksi yang rahasianya gagal dikirim ke pengguna (mis. undangan yang tautannya tidak sampai). Hanya
     * draf confirmed milik pengguna yang dijalankan lewat bot; aksi pencabutan memakai hak yang sama (tool::cabutHasil)
     * dan dicatat audit `ai`. Tidak pernah menyentuh/menulis rahasia.
     */
    public function cabutHasilRahasia(User $user, AiDraft $draft): bool
    {
        $tool = $this->resolver->resolve($draft);

        if ($tool === null || (int) $draft->user_id !== (int) $user->getKey() || $draft->status !== StatusAiDraft::Confirmed
            || (((array) $draft->hasil)['lewat'] ?? null) !== 'bot') {
            return false;
        }

        try {
            $dicabut = (bool) AuditLog::withSource('ai', fn () => ActingUser::run($user, fn () => $tool->cabutHasil($user, $draft)));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        if ($dicabut) {
            $draft->forceFill(['hasil' => (array) $draft->hasil + ['dicabut_otomatis' => true]])->save();
            $this->audit($user, $draft, 'rahasia_gagal_dikirim_dicabut', null, (array) $draft->hasil);
        }

        return $dicabut;
    }

    /** Batal dari chat: draf terbuka milik pengguna -> cancelled (idempoten). */
    public function batalkan(User $user, int $draftId): bool
    {
        $berhasil = AiDraft::query()->withoutGlobalScopes()->whereKey($draftId)
            ->where('user_id', $user->getKey())
            ->whereIn('status', StatusAiDraft::nilaiTerbuka())
            ->update(['status' => StatusAiDraft::Cancelled->value, 'hasil' => json_encode(['kode' => HasilEksekusi::DIBATALKAN]), 'updated_at' => now()]) === 1;

        if ($berhasil && ($draft = AiDraft::query()->withoutGlobalScopes()->find($draftId)) !== null) {
            $this->audit($user, $draft, HasilEksekusi::DIBATALKAN, null);
        }

        return $berhasil;
    }

    private function jalankan(User $user, int $draftId, StatusAiDraft $statusDiharapkan): HasilEksekusi
    {
        $sesi = $this->sesi->tokenSesiPenuh($user);

        if ($sesi === null) {
            return new HasilEksekusi(HasilEksekusi::TANPA_SESI);
        }

        $draft = null;

        try {
            [$draft, $rahasia, $http] = DB::transaction(function () use ($user, $draftId, $statusDiharapkan, $sesi): array {
                $draft = AiDraft::query()->withoutGlobalScopes()->whereKey($draftId)->lockForUpdate()->first();

                if ($draft === null || (int) $draft->user_id !== (int) $user->getKey() || $draft->status !== $statusDiharapkan || $draft->isKedaluwarsa()) {
                    throw new EksekusiDitolak(HasilEksekusi::TIDAK_BERLAKU);
                }

                $tool = $this->toolUntuk($draft);
                $hambatan = $this->hambatan($user, $draft, $tool);

                if ($hambatan !== null || $tool === null) {
                    throw new EksekusiDitolak($hambatan ?? HasilEksekusi::TIDAK_BERLAKU);
                }

                $spec = $tool->eksekusi();

                // Keadaan target + Form Request dihitung ulang atas nama pengguna penekan (SppgScope + Policy).
                [$sidik, $cek] = ActingUser::run($user, fn () => [$tool->sidikTargetSaatIni($user, $draft), $tool->revalidate($user, $draft)]);

                if ($sidik === false) {
                    throw new EksekusiDitolak(HasilEksekusi::TIDAK_ADA);
                }

                if ($draft->sidik_target !== null && $sidik !== $draft->sidik_target) {
                    throw new EksekusiDitolak(HasilEksekusi::BERUBAH);
                }

                if ($cek !== null && ! $cek->authorized) {
                    throw new EksekusiDitolak(HasilEksekusi::DITOLAK);
                }

                $body = $tool->bodyEksekusi($draft, $cek);
                $header = [];

                if ($spec->kirimAiDraftId) {
                    $body['ai_draft_id'] = $draft->uuid;

                    if ($spec->metode === 'POST') {
                        $header['Idempotency-Key'] = $draft->uuid;
                    }
                }

                $uri = route((string) $spec->rute, $spec->parameterRute === null ? [] : [$spec->parameterRute => (int) $draft->target_id], false);
                $respons = AuditLog::withSource('ai', fn () => $this->permintaan->kirim($user, $sesi, $spec->metode, $uri, $body, $header));

                if (! $respons->sukses()) {
                    throw $this->petakanGalat($respons);
                }

                [$simpan, $rahasia] = $tool->olahHasil($respons->json ?? [], $draft);
                $draft->refresh();
                $draft->forceFill([
                    'status' => StatusAiDraft::Confirmed,
                    'confirmed_at' => $draft->confirmed_at ?? now(),
                    // Hanya kode + id/status: TIDAK PERNAH token/tautan/rahasia.
                    'hasil' => ['kode' => HasilEksekusi::SUKSES, 'http' => $respons->status, 'lewat' => 'bot'] + $simpan,
                ])->save();
                $this->audit($user, $draft, HasilEksekusi::SUKSES, $respons->status, $simpan);

                return [$draft, $rahasia, $respons->status];
            });
        } catch (EksekusiDitolak $e) {
            return $this->tutupGagal($user, $draftId, $e);
        } catch (Throwable $e) {
            report($e);

            return $this->tutupGagal($user, $draftId, new EksekusiDitolak(HasilEksekusi::GAGAL));
        }

        return new HasilEksekusi(HasilEksekusi::SUKSES, null, $draft, $rahasia);
    }

    private function petakanGalat(ResponsInternal $r): EksekusiDitolak
    {
        // 409 SPPG aktif belum dipilih (ResolveSppgAktif) = penolakan konteks, bukan "data berubah" (versi_tidak_cocok).
        $sppgBelumDipilih = ($r->json['alasan'] ?? null) === SppgAktif::BELUM_DIPILIH;

        return match (true) {
            in_array($r->status, [401, 403], true) || $sppgBelumDipilih => new EksekusiDitolak(HasilEksekusi::DITOLAK, null, $r->status),
            $r->status === 404 => new EksekusiDitolak(HasilEksekusi::TIDAK_ADA, null, $r->status),
            $r->status === 409 => new EksekusiDitolak(HasilEksekusi::BERUBAH, null, $r->status),
            $r->status === 422 && in_array('ai_draft_id', $r->kunciGalat(), true) => new EksekusiDitolak(HasilEksekusi::TIDAK_BERLAKU, null, $r->status),
            $r->status === 422 => new EksekusiDitolak(HasilEksekusi::VALIDASI, $r->pesanGalat(), $r->status),
            $r->status === 429 => new EksekusiDitolak(HasilEksekusi::TERLALU_CEPAT, null, $r->status),
            default => new EksekusiDitolak(HasilEksekusi::GAGAL, null, $r->status),
        };
    }

    /** Penolakan sebelum langkah apa pun dijalankan (hak dicabut, draf tidak berlaku, hanya Mini App). */
    private function tolakSebelumJalan(User $user, AiDraft $draft, ?WriteDraftTool $tool, string $kode): HasilEksekusi
    {
        if ($kode === HasilEksekusi::DITOLAK) {
            return $this->tutupGagal($user, (int) $draft->getKey(), new EksekusiDitolak($kode));
        }

        if ($kode === HasilEksekusi::TIDAK_BERLAKU && $draft->status->terbuka() && $draft->isKedaluwarsa()) {
            AiDraft::query()->withoutGlobalScopes()->whereKey($draft->getKey())->whereIn('status', StatusAiDraft::nilaiTerbuka())
                ->update(['status' => StatusAiDraft::Expired->value, 'updated_at' => now()]);
        }

        return new HasilEksekusi($kode, null, $draft);
    }

    /**
     * Draf yang ditolak/gagal ditutup (`gagal`) agar tidak dapat dicoba ulang dari tombol lama; kecuali "terlalu cepat"
     * (429) dan draf yang memang tidak berlaku lagi. Audit tetap ditulis.
     */
    private function tutupGagal(User $user, int $draftId, EksekusiDitolak $e): HasilEksekusi
    {
        $draft = AiDraft::query()->withoutGlobalScopes()->find($draftId);

        if ($draft !== null && (int) $draft->user_id === (int) $user->getKey()
            && ! in_array($e->kode, [HasilEksekusi::TERLALU_CEPAT, HasilEksekusi::TIDAK_BERLAKU, HasilEksekusi::HANYA_MINIAPP], true)) {
            AiDraft::query()->withoutGlobalScopes()->whereKey($draftId)->whereIn('status', StatusAiDraft::nilaiTerbuka())->update([
                'status' => StatusAiDraft::Gagal->value,
                'hasil' => json_encode(array_filter(['kode' => $e->kode, 'http' => $e->http, 'lewat' => 'bot'], fn ($v) => $v !== null)),
                'updated_at' => now(),
            ]);
            $draft->refresh();
            $this->audit($user, $draft, $e->kode, $e->http);
        }

        return new HasilEksekusi($e->kode, $e->pesan, $draft);
    }

    /**
     * Jejak audit eksekusi (sumber `ai`): pengguna, tool, aksi, kode hasil, status HTTP, id hasil. Tanpa payload,
     * token, tautan, atau teks pengguna.
     *
     * @param  array<string, int|string|bool|null>  $hasil
     */
    private function audit(User $user, AiDraft $draft, string $kode, ?int $http, array $hasil = []): void
    {
        $log = new AuditLog;
        $log->forceFill([
            'sppg_id' => $draft->sppg_id,
            'user_id' => (int) $user->getKey(),
            'event' => 'ai_eksekusi_draf',
            'auditable_type' => $draft->getMorphClass(),
            'auditable_id' => (int) $draft->getKey(),
            'old_values' => null,
            'new_values' => array_filter([
                'tool' => $draft->tool,
                'modul' => $draft->module,
                'aksi' => $draft->action->value,
                'target_id' => $draft->target_id === null ? null : (int) $draft->target_id,
                'hasil' => $kode,
                'http' => $http,
                'lewat' => 'bot',
            ] + array_intersect_key($hasil, array_flip(['id', 'undangan_id', 'status'])), fn ($v) => $v !== null),
            'ip' => null,
            'user_agent' => null,
            'sumber' => 'ai',
        ])->save();
    }
}

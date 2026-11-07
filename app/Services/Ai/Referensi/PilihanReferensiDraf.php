<?php

namespace App\Services\Ai\Referensi;

use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Catalog\DraftToolResolver;
use App\Services\Ai\Tools\WriteDraftTool;
use Illuminate\Support\Facades\DB;

/**
 * Pilihan rujukan tertunda pada AiDraft (payload `_pilihan`): rujukan nama yang AMBIGU disimpan sebagai daftar ID
 * kandidat (bukan nama), lalu dipilih pengguna lewat tombol Bot `ai_pilih` (atau langsung di formulir Mini App).
 *
 * Setiap pilihan dihitung ulang server: pengguna dimuat ulang (aktif), draf milik pengguna + SPPG sama + terbuka
 * (pending) + belum kedaluwarsa, dikunci lockForUpdate; tool pembuat masih tersedia untuk peran SAAT INI; token harus
 * untuk slot PERTAMA (kode() = indeks slot + posisi kandidat) dan kandidat masih milik SPPG pengguna
 * (ResolverReferensi::denganId, hak baca dicek).
 * Pilihan hanya MENGUBAH PAYLOAD DRAF; data modul tetap hanya tersimpan setelah konfirmasi (Simpan/Lanjutkan).
 */
class PilihanReferensiDraf
{
    public const KUNCI = '_pilihan';

    /** Basis kode tombol (> ResolverReferensi::MAKS_PILIHAN): kode = indeks slot * KODE_BASIS + posisi kandidat. */
    public const KODE_BASIS = 8;

    public function __construct(
        private readonly DraftToolResolver $tools,
        private readonly ResolverReferensi $resolver,
    ) {}

    /**
     * Slot pilihan tertunda yang berbentuk sah (urutan dipertahankan).
     *
     * @return list<array{jenis: string, kolom: string, indeks: int, dicari: string, jumlah: string, satuan: string, kandidat: list<int>, lebih_banyak: bool}>
     */
    public static function tertunda(AiDraft $draft): array
    {
        $slots = ((array) $draft->payload)[self::KUNCI] ?? null;

        if (! is_array($slots)) {
            return [];
        }

        $sah = [];

        foreach ($slots as $slot) {
            if (! is_array($slot) || ! is_string($slot['jenis'] ?? null) || ! is_string($slot['kolom'] ?? null) || ! is_int($slot['indeks'] ?? null)
                || ! is_array($slot['kandidat'] ?? null)) {
                continue;
            }

            $sah[] = [
                'jenis' => $slot['jenis'],
                'kolom' => $slot['kolom'],
                'indeks' => $slot['indeks'],
                'dicari' => is_string($slot['dicari'] ?? null) ? $slot['dicari'] : '',
                'jumlah' => is_string($slot['jumlah'] ?? null) ? $slot['jumlah'] : '',
                'satuan' => is_string($slot['satuan'] ?? null) ? $slot['satuan'] : '',
                'kandidat' => array_values(array_filter($slot['kandidat'], fn ($id) => is_int($id) && $id > 0)),
                'lebih_banyak' => (bool) ($slot['lebih_banyak'] ?? false),
            ];
        }

        return $sah;
    }

    /** Tool pembuat draf bila mendukung pilihan rujukan. */
    public function tool(AiDraft $draft): (WriteDraftTool&MemakaiPilihanReferensi)|null
    {
        $tool = $this->tools->resolve($draft);

        return $tool instanceof MemakaiPilihanReferensi ? $tool : null;
    }

    /**
     * Slot berikut untuk ditampilkan (kandidat dimuat ulang ber-scope; yang sudah tidak ada dilewati).
     *
     * @return array{slot: array<string, mixed>, jenis: JenisReferensi, kandidat: list<KandidatReferensi>, keterangan: string}|null
     */
    public function berikut(User $user, AiDraft $draft): ?array
    {
        $slot = self::tertunda($draft)[0] ?? null;
        $tool = $this->tool($draft);
        $jenis = JenisReferensi::dari($slot['jenis'] ?? null);

        if ($slot === null || $tool === null || $jenis === null) {
            return null;
        }

        $kandidat = ActingUser::run($user, fn () => array_values($this->resolver->banyakId($user, $jenis, $slot['kandidat'])));

        return ['slot' => $slot, 'jenis' => $jenis, 'kandidat' => $kandidat, 'keterangan' => $tool->keteranganSlot($slot)];
    }

    /**
     * Kontrak Mini App (GET /ai-drafts/{uuid} -> `pilihan_referensi`): slot tertunda + kandidat terkini milik SPPG
     * pengguna. `indeks` = posisi baris placeholder pada payload[kolom].
     *
     * @return list<array<string, mixed>>
     */
    public function untukTampilan(User $user, AiDraft $draft): array
    {
        if ($this->tool($draft) === null) {
            return [];
        }

        $hasil = [];

        foreach (self::tertunda($draft) as $slot) {
            $jenis = JenisReferensi::dari($slot['jenis']);

            if ($jenis === null) {
                continue;
            }

            $kandidat = ActingUser::run($user, fn () => array_values($this->resolver->banyakId($user, $jenis, $slot['kandidat'])));
            $hasil[] = [
                'kolom' => $slot['kolom'],
                'indeks' => $slot['indeks'],
                'jenis' => $jenis->kunci,
                'dicari' => $slot['dicari'],
                'jumlah' => $slot['jumlah'],
                'satuan' => $slot['satuan'],
                'kandidat' => array_map(fn (KandidatReferensi $k) => $k->toArray(), $kandidat),
                'lebih_banyak' => $slot['lebih_banyak'],
            ];
        }

        return $hasil;
    }

    /**
     * Kode tombol `ai_pilih` (bot_aksi.subjek_versi) = indeks slot * KODE_BASIS + posisi kandidat pada daftar slot.
     * Token terikat ke SLOT (indeks baris placeholder) dan POSISI kandidat; ID kandidat diambil ulang dari draf saat
     * ditekan. Null bila kandidat bukan bagian slot.
     *
     * @param  array{indeks: int, kandidat: list<int>}  $slot
     */
    public static function kode(array $slot, int $kandidatId): ?int
    {
        $posisi = array_search($kandidatId, $slot['kandidat'], true);

        return $posisi === false || $posisi >= self::KODE_BASIS || $slot['indeks'] < 0 ? null : $slot['indeks'] * self::KODE_BASIS + $posisi;
    }

    /**
     * Terapkan pilihan pengguna (tombol Bot, $kode dari kode()) ke slot pertama draf. Kode untuk slot lain (layar
     * lama, slot sudah dipilih) atau posisi di luar daftar = TIDAK_BERLAKU. Tidak menulis data modul.
     */
    public function pilih(User $penekan, int $draftId, int $kode): HasilPilihan
    {
        $user = User::query()->withoutGlobalScopes()->find($penekan->getKey());

        if (! $user instanceof User || ! $user->canAuthenticate()) {
            return new HasilPilihan(HasilPilihan::DITOLAK);
        }

        return DB::transaction(function () use ($user, $draftId, $kode): HasilPilihan {
            $draft = AiDraft::query()->withoutGlobalScope(SppgScope::class)->whereKey($draftId)->lockForUpdate()->first();

            if ($draft === null || ! $this->milik($user, $draft) || $draft->status !== StatusAiDraft::Pending || ! $draft->bisaDikonfirmasi()) {
                return new HasilPilihan(HasilPilihan::TIDAK_BERLAKU);
            }

            $tool = $this->tool($draft);
            $slots = self::tertunda($draft);
            $slot = $slots[0] ?? null;
            $jenis = JenisReferensi::dari($slot['jenis'] ?? null);
            $kandidatId = $kode < 0 || $slot === null || intdiv($kode, self::KODE_BASIS) !== $slot['indeks']
                ? null
                : ($slot['kandidat'][$kode % self::KODE_BASIS] ?? null);

            if ($tool === null || $jenis === null || ! is_int($kandidatId)) {
                return new HasilPilihan(HasilPilihan::TIDAK_BERLAKU, $draft);
            }

            if (! $tool->isAvailableFor($user)) {
                return new HasilPilihan(HasilPilihan::DITOLAK, $draft);
            }

            $hasil = ActingUser::run($user, fn () => $this->resolver->denganId($user, $jenis, $kandidatId));

            if ($hasil->status === HasilResolusi::DITOLAK) {
                return new HasilPilihan(HasilPilihan::DITOLAK, $draft);
            }

            if (! $hasil->tepat()) {
                return new HasilPilihan(HasilPilihan::TIDAK_ADA, $draft, 'Pilihan itu sudah tidak tersedia. Pilih yang lain atau lengkapi di Mini App.');
            }

            $payload = ActingUser::run($user, fn () => $tool->terapkanPilihan($user, (array) $draft->payload, $slot, $hasil->item));

            if (is_string($payload)) {
                return new HasilPilihan(HasilPilihan::TIDAK_COCOK, $draft, $payload);
            }

            array_shift($slots);

            if ($slots === []) {
                unset($payload[self::KUNCI]);
            } else {
                $payload[self::KUNCI] = $slots;
            }

            $draft->forceFill(['payload' => $payload]);

            if ($slots === []) {
                $draft->forceFill(['confidence' => $this->keyakinanBaru($user, $draft, $tool, $slot['kolom'])]);
            }

            $draft->save();

            return new HasilPilihan(HasilPilihan::DIPILIH, $draft->refresh());
        });
    }

    /** Keyakinan kolom yang semua rujukannya kini terpilih: bawaan bila lolos Form Request, 0 bila masih bergalat. */
    private function keyakinanBaru(User $user, AiDraft $draft, WriteDraftTool $tool, string $kolom): array
    {
        $keyakinan = (array) ($draft->confidence ?? []);
        $cek = ActingUser::run($user, fn () => $tool->revalidate($user, $draft));
        $galat = $cek !== null && collect(array_keys($cek->errors))->contains(fn ($k) => explode('.', (string) $k)[0] === $kolom);
        $keyakinan[$kolom] = $galat ? 0.0 : round((float) config('ai.drafts.default_confidence', 0.8), 2);

        return $keyakinan;
    }

    /** Pemilik + SPPG draf = SPPG pengguna SAAT INI (sama dengan AiDraftExecutor). Admin tidak memakai pilihan rujukan. */
    private function milik(User $user, AiDraft $draft): bool
    {
        return (int) $draft->user_id === (int) $user->getKey()
            && ! $user->isAdmin() && ! $user->isInvestor()
            && $user->sppg_id !== null && $draft->sppg_id !== null
            && (int) $draft->sppg_id === (int) $user->sppg_id;
    }
}

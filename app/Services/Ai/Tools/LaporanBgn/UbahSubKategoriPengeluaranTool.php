<?php

namespace App\Services\Ai\Tools\LaporanBgn;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Enums\SubKategoriPengeluaran;
use App\Http\Requests\Keuangan\UpdatePengeluaranRequest;
use App\Models\AiDraft;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Ai\Catalog\SppgBound;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Ai\Tools\WriteDraftTool;
use App\Services\LaporanBgn\RingkasanKeuanganService;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;

/**
 * Tool TULIS `ubah_sub_kategori_pengeluaran` (R07-5): DRAF pengisian/perubahan `sub_kategori` (17 baris Biaya Operasional
 * laporan BGN) pada SATU pengeluaran operasional. Tidak pernah menulis data modul: hanya AiDraft (modul `keuangan`,
 * `_resource` `pengeluaran`, aksi update) yang disimpan pengguna di Mini App lewat PATCH /keuangan/pengeluaran/{id}
 * + `ai_draft_id` (layar ubah pengeluaran yang sudah ada; AiDraftConfirmer menandai draf di transaksi yang sama).
 *
 * - Hak: akuntan saja (Rbac U `keuangan` + Policy update per record + aksi `lihat-laporan-bgn`). Admin/kepala (R) dan
 *   investor (baca saja) tidak pernah ditawari; peran tanpa SPPG tidak ditawari (WriteDraftTool::butuhSppg).
 * - Argumen: target_id + sub_kategori (enum 17 nilai) saja; kolom lain (nominal, kategori, uraian, status, versi,
 *   sppg_id) di luar skema -> seluruh panggilan ditolak. `versi` disisipkan SERVER dari record (konfirmasi basi -> 409).
 * - Validasi dry-run dengan UpdatePengeluaranRequest yang sama dengan REST (termasuk aturan "sub-kategori hanya untuk
 *   operasional"); target dicari dengan SppgBound + SppgScope lalu Policy update (SPPG lain = "tidak ditemukan").
 * - Payload draf hanya {sub_kategori, versi}: tanpa teks bebas, sehingga tidak ada isian pengguna yang dapat berperan
 *   sebagai instruksi di layar konfirmasi. Ringkasan dibangun server dari basis data (tanpa uraian).
 * - Eksekusi dari chat TIDAK diaktifkan (eksekusi() = null -> tombol "Periksa & Simpan" Mini App).
 * - Efek simpan (BudgetService::ubahPengeluaran, ADR docs/decisions/2026-11-18-sub-kategori-pengeluaran-tanpa-ajukan-ulang.md):
 *   bila hanya `sub_kategori` yang berubah pada pengeluaran `disetujui`, TIDAK ada pengajuan ulang: status tetap
 *   `disetujui`, tidak ada pengajuan/notifikasi investor baru, pengeluaran tetap dihitung di laporan BGN (angkanya
 *   berpindah ke baris sub-kategori baru), `versi` naik, dan jejak audit (`audit_logs`, event `updated`, sub_kategori
 *   lama/baru) tercatat (ringkasan tanpa peringatan). Pengeluaran `diajukan`/`ditolak` mengikuti alur ubah pengeluaran
 *   biasa (diajukan ulang, investor diberi tahu lagi); ringkasan konfirmasi memuat peringatan sesuai status.
 */
class UbahSubKategoriPengeluaranTool extends WriteDraftTool
{
    protected string $name = 'ubah_sub_kategori_pengeluaran';

    /**
     * Kalimat pertama memuat cara menyebut target (target_id ATAU kata_kunci) karena ToolSelector meringkas deskripsi
     * ke kalimat pertama (temuan P10: petunjuk target_id terpotong sehingga model tidak tahu cara menunjuk pengeluaran).
     */
    protected string $description = 'Siapkan DRAF sub-kategori (baris Biaya Operasional BGN) untuk SATU pengeluaran operasional: isi kata_kunci uraian pengeluaran seperti ditulis pengguna, atau target_id dari cari_pengeluaran. Data BELUM berubah sampai pengguna menyimpan di Mini App.';

    /** Kandidat maksimum yang dikembalikan ke model bila kata_kunci cocok dengan beberapa pengeluaran. */
    private const MAKS_KANDIDAT = 5;

    protected string $module = 'keuangan';

    protected string $action = 'U';

    protected ?string $model = Pengeluaran::class;

    protected function formRequest(): ?string
    {
        return UpdatePengeluaranRequest::class;
    }

    protected function routeParameter(): ?string
    {
        return 'pengeluaran';
    }

    protected function fields(): array
    {
        return [
            'properties' => [
                'sub_kategori' => [
                    'type' => 'string',
                    'enum' => SubKategoriPengeluaran::values(),
                    'description' => 'Baris Biaya Operasional BGN (17 baris baku). Hanya untuk pengeluaran operasional, bukan bahan baku atau insentif fasilitas.',
                ],
            ],
            'required' => ['sub_kategori'],
        ];
    }

    /**
     * target_id TIDAK wajib: model boleh mengirim `kata_kunci` (uraian seperti ditulis pengguna) + `tanggal` opsional yang
     * diselesaikan SERVER (targetDariArgumen). Kolom lain tetap ditolak (additionalProperties false).
     */
    public function parameters(): array
    {
        $parameters = parent::parameters();
        $properties = $parameters['properties'];
        $targetId = $properties['target_id'];
        $targetId['description'] = 'ID pengeluaran dari cari_pengeluaran atau lihat_sub_kategori_laporan_bgn. Kosongkan bila memakai kata_kunci.';
        unset($properties['target_id']);

        $parameters['properties'] = [
            'target_id' => $targetId,
            'kata_kunci' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'description' => 'Uraian pengeluaran persis seperti ditulis pengguna; sistem mencari pengeluarannya. Dipakai bila target_id tidak diketahui.'],
            'tanggal' => ['type' => 'string', 'format' => 'date', 'description' => 'Opsional: tanggal pengeluaran YYYY-MM-DD untuk mempersempit kata_kunci.'],
        ] + $properties;
        $parameters['required'] = ['sub_kategori'];

        return $parameters;
    }

    /**
     * Target dari `target_id`, atau dari `kata_kunci` (+ `tanggal`) yang dicari SERVER: hanya pengeluaran operasional
     * SPPG pengguna (SppgBound + SppgScope), bukan hasil pembelian bahan, lolos Policy update. Satu cocok = target;
     * nol = tidak ditemukan; lebih dari satu = kandidat (id, tanggal, kategori, nominal, sub-kategori; TANPA uraian)
     * dikembalikan ke model untuk dipilih (pengguna tidak diminta menyebut ID).
     */
    protected function targetDariArgumen(User $user, array $arguments): Model|ToolResult|null
    {
        if (isset($arguments['target_id'])) {
            return parent::targetDariArgumen($user, $arguments);
        }

        $kunci = trim((string) ($arguments['kata_kunci'] ?? ''));

        if (mb_strlen($kunci) < 2) {
            return ToolResult::invalid('Sebutkan pengeluaran yang dimaksud: isi kata_kunci (uraian seperti ditulis pengguna) atau target_id dari cari_pengeluaran.');
        }

        $query = SppgBound::apply(Pengeluaran::query(), $user)
            ->whereNull('pembelian_bahan_id')
            ->whereNotIn('kategori', [KategoriPengeluaran::BahanBaku->value, KategoriPengeluaran::InsentifFasilitas->value]);
        $model = $query->getModel();
        $query->whereRaw($query->getQuery()->getGrammar()->wrap($model->qualifyColumn('uraian'))." LIKE ? ESCAPE '!'", [SppgBound::likePattern($kunci)]);

        if (isset($arguments['tanggal'])) {
            $query->whereDate($model->qualifyColumn('tanggal'), (string) $arguments['tanggal']);
        }

        $cocok = $query->orderByDesc($model->qualifyColumn('tanggal'))->orderByDesc($model->getQualifiedKeyName())
            ->limit(self::MAKS_KANDIDAT + 1)->get()
            ->filter(fn (Pengeluaran $p) => $this->allows($user, 'update', $p))->values();

        if ($cocok->count() === 1) {
            return $cocok->first();
        }

        if ($cocok->isEmpty()) {
            return ToolResult::invalid('Pengeluaran operasional dengan kata kunci itu tidak ditemukan. Cari dengan cari_pengeluaran atau lihat_sub_kategori_laporan_bgn, lalu isi target_id.');
        }

        $kandidat = $cocok->take(self::MAKS_KANDIDAT)->map(fn (Pengeluaran $p) => sprintf(
            'target_id %d: %s, %s, %s, sub-kategori %s',
            $p->getKey(),
            (string) $p->tanggal?->toDateString(),
            (string) $p->kategori?->label(),
            self::rupiah(RingkasanKeuanganService::rupiah($p->nominal)),
            $p->sub_kategori?->value ?? '(belum diisi)',
        ))->implode('; ');

        return ToolResult::invalid('Kata kunci cocok dengan lebih dari satu pengeluaran ('.$kandidat.($cocok->count() > self::MAKS_KANDIDAT ? '; dan lainnya' : '')
            .'). Bila pesan pengguna menunjuk salah satunya dengan jelas (tanggal/nominal), panggil ulang dengan target_id itu; bila tidak, minta pengguna menyebut tanggal atau nominalnya (jangan meminta ID).');
    }

    /** Penanda sub-modul sama dengan ubah_pengeluaran: Mini App membuka layar ubah pengeluaran, Confirmer `pengeluaran`. */
    public function draftResource(): ?string
    {
        return 'pengeluaran';
    }

    /** Hanya Mini App (Periksa & Simpan); lihat docblock kelas. */
    public function eksekusi(): ?SpesifikasiEksekusi
    {
        return null;
    }

    public function isAvailableFor(User $user): bool
    {
        return parent::isAvailableFor($user) && Rbac::canAction($user, 'lihat-laporan-bgn');
    }

    /** Hanya sub_kategori dari LLM; kolom lain tidak pernah masuk payload walau lolos ke sini. */
    protected function toPayload(User $user, array $arguments): array
    {
        return array_intersect_key($arguments, ['sub_kategori' => true]);
    }

    /** `versi` dari record target saat draf dibuat (bukan dari LLM). */
    protected function sisipkanDariServer(array $payload, ?Model $target): array
    {
        if ($target instanceof Pengeluaran) {
            $payload['versi'] = (int) $target->versi;
        }

        return $payload;
    }

    protected function findTarget(User $user, int $id): ?Model
    {
        return SppgBound::apply(Pengeluaran::query(), $user)->whereKey($id)->first();
    }

    protected function tolakDraf(User $user, ?Model $target, array $payload): ?string
    {
        if (! $target instanceof Pengeluaran) {
            return 'Data yang dimaksud tidak ditemukan.';
        }

        if ($target->pembelian_bahan_id !== null) {
            return 'Pengeluaran ini berasal dari pembelian bahan terverifikasi dan tidak dapat diubah.';
        }

        if (in_array($target->kategori, [KategoriPengeluaran::BahanBaku, KategoriPengeluaran::InsentifFasilitas], true)) {
            return 'Sub-kategori hanya untuk pengeluaran operasional (bukan bahan baku atau insentif fasilitas).';
        }

        $baru = SubKategoriPengeluaran::tryFrom((string) ($payload['sub_kategori'] ?? ''));

        if ($baru !== null && $target->sub_kategori === $baru) {
            return 'Pengeluaran ini sudah memakai sub-kategori tersebut; tidak ada yang perlu diubah.';
        }

        return null;
    }

    protected function labelSumberDaya(): string
    {
        return 'sub-kategori pengeluaran';
    }

    /** Nama target dari basis data TANPA uraian (teks bebas): tanggal + kategori + nominal. */
    protected function namaTarget(Model $record): string
    {
        if (! $record instanceof Pengeluaran) {
            return '#'.$record->getKey();
        }

        return self::satuBaris(sprintf(
            'Pengeluaran %s, %s, %s',
            (string) $record->tanggal?->toDateString(),
            (string) $record->kategori?->label(),
            self::rupiah(RingkasanKeuanganService::rupiah($record->nominal)),
        ), 100).' (#'.$record->getKey().')';
    }

    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $target = $draft->target_id === null ? null : $this->findTarget($user, (int) $draft->target_id);
        $baru = SubKategoriPengeluaran::tryFrom((string) (((array) $draft->payload)['sub_kategori'] ?? ''));
        $baris = [];

        if ($target instanceof Pengeluaran) {
            $baris[] = 'Sub-kategori saat ini: '.($target->sub_kategori?->label() ?? '(belum diisi, masuk baris '.RingkasanKeuanganService::subOperasional($target)[0]->label().')');
        }

        $baris[] = 'Sub-kategori baru: '.($baru?->label() ?? '(belum dipilih)');

        return new RingkasanDraf(
            'Ubah sub-kategori pengeluaran',
            $baris,
            $target === null ? ($draft->target_id === null ? null : '#'.(int) $draft->target_id.' (tidak ditemukan)') : $this->namaTarget($target),
            $target instanceof Pengeluaran ? self::peringatanStatus($target->status_persetujuan) : null,
        );
    }

    /**
     * Peringatan efek simpan per status, HANYA dari data server (status), tanpa uraian/teks bebas. `disetujui`: tidak ada
     * peringatan (status tetap, ADR 2026-11-18); `diajukan`/`ditolak`: simpan mengikuti alur ubah pengeluaran biasa
     * (BudgetService) = diajukan ulang dan investor diberi tahu lagi.
     */
    private static function peringatanStatus(?StatusPersetujuanPengeluaran $status): ?string
    {
        return match ($status) {
            StatusPersetujuanPengeluaran::Diajukan => 'PERINGATAN: pengeluaran ini masih menunggu persetujuan investor. Menyimpan akan membatalkan pengajuan yang menunggu dan mengajukannya ulang, dan investor akan diberi tahu lagi.',
            StatusPersetujuanPengeluaran::Ditolak => 'PERINGATAN: pengeluaran ini sudah ditolak investor. Menyimpan akan mengajukan ulang pengeluaran ke investor, dan investor akan diberi tahu lagi.',
            default => null,
        };
    }

    /** Mis. "Draf sub-kategori pengeluaran 2026-11-02 Rp200.000: Listrik" (tanpa id internal). */
    public function kalimatDraf(User $user, AiDraft $draft): string
    {
        $target = $draft->target_id === null ? null : $this->findTarget($user, (int) $draft->target_id);
        $baru = SubKategoriPengeluaran::tryFrom((string) (((array) $draft->payload)['sub_kategori'] ?? ''));
        $teks = 'Draf sub-kategori pengeluaran';

        if ($target instanceof Pengeluaran) {
            $teks .= ' '.$target->tanggal?->toDateString().' '.self::rupiah(RingkasanKeuanganService::rupiah($target->nominal));
        }

        return self::satuBaris($teks.': '.($baru?->label() ?? '(belum dipilih)'), 200);
    }

    private static function rupiah(int $nilai): string
    {
        return 'Rp'.number_format($nilai, 0, ',', '.');
    }
}

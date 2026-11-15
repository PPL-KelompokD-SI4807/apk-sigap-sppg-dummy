<?php

namespace App\Services\Ai\Tools\LaporanBgn;

use App\Enums\KategoriPengeluaran;
use App\Enums\SubKategoriPengeluaran;
use App\Http\Requests\Keuangan\UpdatePengeluaranRequest;
use App\Models\AiDraft;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Ai\Catalog\SppgBound;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
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
 * - Eksekusi dari chat TIDAK diaktifkan (eksekusi() = null -> tombol "Periksa & Simpan" Mini App): menyimpan perubahan
 *   memakai alur BudgetService::ubahPengeluaran yang mengajukan ulang pengeluaran ke investor (lihat peringatan).
 */
class UbahSubKategoriPengeluaranTool extends WriteDraftTool
{
    public const PERINGATAN = 'Menyimpan perubahan ini mengajukan ulang pengeluaran (alur ubah pengeluaran biasa). Bila perlu persetujuan investor, pengeluaran tidak masuk laporan BGN sampai disetujui kembali.';

    protected string $name = 'ubah_sub_kategori_pengeluaran';

    protected string $description = 'Siapkan DRAF pengisian atau perubahan sub-kategori (baris Biaya Operasional laporan BGN) untuk SATU pengeluaran operasional. target_id = ID pengeluaran dari tool lihat_sub_kategori_laporan_bgn atau cari_pengeluaran. Data BELUM berubah sampai pengguna menyimpan di Mini App.';

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
            self::PERINGATAN,
        );
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

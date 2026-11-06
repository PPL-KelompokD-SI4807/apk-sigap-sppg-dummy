<?php

namespace App\Services\Ai\Eksekusi;

/**
 * Cara sebuah draf dijalankan lewat tombol konfirmasi Bot: rute REST modul yang SUDAH ada (dipanggil sebagai
 * sub-request internal atas nama pengguna penekan tombol, lihat PermintaanInternal), bukan logika tulis tersendiri.
 *
 * - $rute: nama rute Laravel (mis. 'api.v1.pemasok.store'); null = tidak dapat dijalankan dari chat.
 * - $parameterRute: nama parameter rute untuk id target (ubah/hapus), mis. 'pemasok', 'user'.
 * - $kirimAiDraftId: endpoint menerima `ai_draft_id` (AiDraftConfirmer menandai draf di transaksi yang sama).
 * - $butuhBerkas: aksi memerlukan foto/nota/berkas -> hanya "Ubah" (Mini App), tombol Lanjutkan tidak tampil
 *   dan eksekutor menolak walau token dipalsukan.
 * - $bisaUbah: Mini App punya formulir terisi untuk draf ini (?draft=<uuid>); false -> tombol Ubah disembunyikan.
 * - $responsRahasia: respons memuat rahasia (mis. token undangan) -> tidak pernah disimpan/di-log/diberikan ke LLM;
 *   ditampilkan langsung ke pengguna lewat pesan terlindungi.
 */
final class SpesifikasiEksekusi
{
    private function __construct(
        public readonly ?string $rute,
        public readonly string $metode,
        public readonly ?string $parameterRute,
        public readonly bool $kirimAiDraftId,
        public readonly bool $butuhBerkas,
        public readonly bool $bisaUbah,
        public readonly bool $responsRahasia,
    ) {}

    public static function rest(string $rute, string $metode, ?string $parameterRute = null, bool $kirimAiDraftId = false, bool $bisaUbah = true, bool $responsRahasia = false): self
    {
        return new self($rute, strtoupper($metode), $parameterRute, $kirimAiDraftId, false, $bisaUbah, $responsRahasia);
    }

    /** Aksi yang butuh berkas: hanya dapat diselesaikan di Mini App (Ubah). */
    public static function hanyaMiniApp(): self
    {
        return new self(null, 'POST', null, false, true, true, false);
    }

    public function dapatDijalankan(): bool
    {
        return $this->rute !== null && ! $this->butuhBerkas;
    }
}

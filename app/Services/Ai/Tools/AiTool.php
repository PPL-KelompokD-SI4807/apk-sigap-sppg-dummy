<?php

namespace App\Services\Ai\Tools;

use App\Models\User;

/**
 * Kontrak tool Agen AI. Jangan implementasikan langsung: turunkan dari ReadTool (cari_*/lihat_*)
 * atau WriteDraftTool (buat_*/ubah_*/hapus_*). Lihat docs/api/ai-tools.md.
 */
interface AiTool
{
    /** Nama unik snake_case, mis. cari_titik_layanan. */
    public function name(): string;

    /** Deskripsi untuk model (bahasa Indonesia, ringkas). */
    public function description(): string;

    /**
     * JSON Schema argumen (type object, additionalProperties false). Divalidasi ketat oleh
     * ArgumentValidator sebelum execute().
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /** Slug modul config/rbac.php, mis. 'titik-layanan'. */
    public function module(): string;

    /** Huruf hak akses Tabel 5: C, R, U, atau D. */
    public function action(): string;

    /** True = aksi tulis (hanya menghasilkan AiDraft; tidak pernah menulis data modul). */
    public function isWrite(): bool;

    /** Tool ditawarkan ke model hanya bila pengguna berhak (Rbac + Policy + aturan unit). */
    public function isAvailableFor(User $user): bool;

    /**
     * @param  array<string, mixed>  $arguments  sudah divalidasi skema dan bebas argumen terlindungi
     */
    public function execute(User $user, array $arguments, ToolContext $context): ToolResult;
}

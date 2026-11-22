<?php

namespace App\Services\Telegram\Bot;

use App\Models\User;
use App\Services\Ai\Catalog\CatalogDefinitions;
use App\Services\Ai\Catalog\CatalogReadTool;
use App\Services\Ai\Catalog\ResourceSpec;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\ArgumentValidator;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolResult;
use LogicException;

/**
 * Adapter layar baca Bot -> tool baca Agen AI (R-02 v2, bagian 3.2). Bot hanya penyaji: data diambil lewat tool
 * baca yang sudah menegakkan RBAC (`isAvailableFor`: matriks Tabel 5 + unit relawan + Policy viewAny), Policy
 * `view` per baris, SppgBound/SppgScope (SPPG), dan penyamaran data pribadi (RecordPresenter: inisial, teks
 * tersamar + terpotong, kolom sensitif ditolak saat tool dibangun).
 *
 * - Tool dibangun LANGSUNG dari ResourceSpec katalog (CatalogReadTool), bukan lewat ToolRegistry, sehingga layar
 *   tidak bergantung pada `config('ai.tools.*')` maupun konfigurasi LLM (LLM dimatikan -> layar tetap jalan).
 * - Argumen dibangun server (bukan dari callback_data), dibuang bila melanggar skema tool lewat ArgumentValidator
 *   (sama dengan jalur LLM), dan `sppg_id`/`user_id` tidak pernah ada di dalamnya.
 * - WHITELIST kolom: hanya kolom di `$kolom` yang diteruskan ke penyaji; payload tool TIDAK PERNAH ditampilkan utuh
 *   (mis. `uraian` pengeluaran ada di payload tool tetapi tidak di whitelist layar). Setiap kolom whitelist harus
 *   ada di kolom daftar spec (gagal keras bila salah ketik).
 * - Paginasi sederhana: 5 baris per halaman, maksimum 4 halaman (batas tool 20 baris, tanpa offset). Halaman ke-N
 *   meminta N*5 baris dan mengambil 5 terakhir; `ada_lagi` dari tool.
 */
class BotLayarService
{
    public const PER_HALAMAN = 5;

    /** Halaman maksimum daftar tool baca: batas tool 20 baris / 5 baris per halaman. */
    public const HALAMAN_MAKS = 4;

    /** @var array<string, ResourceSpec>|null */
    private ?array $spec = null;

    public function __construct(private readonly ArgumentValidator $validator) {}

    /** Sumber spec katalog (kunci mis. 'bahan_baku') tersedia untuk pengguna ini? */
    public function tersedia(User $user, string $sumber): bool
    {
        return $this->tool($sumber)->isAvailableFor($user);
    }

    /**
     * Ambil satu halaman daftar. Null bila tool tidak tersedia untuk pengguna (RBAC/Policy/unit) atau gagal.
     *
     * @param  array<string, mixed>  $argumen  filter deklaratif tool (dibangun server; mis. terbuka, dari, sampai, milik_saya)
     * @param  list<string>  $kolom  whitelist kolom tampil (nama/alias kolom daftar spec)
     * @return array{baris: list<array<string, mixed>>, ada_lagi: bool}|null
     */
    public function daftar(User $user, string $sumber, array $argumen, array $kolom, int $halaman = 0): ?array
    {
        $tool = $this->tool($sumber);
        $this->pastikanKolom($sumber, $tool, $kolom);

        if (! $tool->isAvailableFor($user)) {
            return null;
        }

        return $this->jalankan($user, $tool, $argumen, $kolom, $halaman);
    }

    /**
     * Sama seperti daftar() untuk tool baca non-katalog (mis. DaftarPengajuanMenuTool): bentuk hasil `hasil` + `total`.
     * Tool wajib membatasi SPPG lewat SppgBound/SppgScope (diuji untuk admin baca-saja).
     *
     * @param  array<string, mixed>  $argumen
     * @param  list<string>  $kolom
     * @return array{baris: list<array<string, mixed>>, ada_lagi: bool}|null
     */
    public function daftarTool(User $user, AiTool $tool, array $argumen, array $kolom, int $halaman = 0): ?array
    {
        if (! $tool->isAvailableFor($user)) {
            return null;
        }

        return $this->jalankan($user, $tool, $argumen, $kolom, $halaman);
    }

    /**
     * @param  array<string, mixed>  $argumen
     * @param  list<string>  $kolom
     * @return array{baris: list<array<string, mixed>>, ada_lagi: bool}|null
     */
    private function jalankan(User $user, AiTool $tool, array $argumen, array $kolom, int $halaman): ?array
    {
        $halaman = max(0, min($halaman, self::HALAMAN_MAKS - 1));
        $batas = min(CatalogReadTool::MAX_ROWS, self::PER_HALAMAN * ($halaman + 1));
        $argumen = [...$argumen, 'batas' => $batas];

        $galat = $this->validator->validate($tool->parameters(), $argumen);

        if ($galat !== []) {
            throw new LogicException('Argumen layar bot tidak sah untuk '.$tool->name().': '.implode(' ', array_slice($galat, 0, 3)));
        }

        $hasil = $tool->execute($user, $argumen, new ToolContext);

        if ($hasil->status !== ToolResult::OK) {
            return null;
        }

        $semua = array_values((array) ($hasil->data['hasil'] ?? []));
        $halamanIni = array_slice($semua, $halaman * self::PER_HALAMAN, self::PER_HALAMAN);
        $adaLagi = isset($hasil->data['ada_lagi'])
            ? (bool) $hasil->data['ada_lagi']
            : (int) ($hasil->data['total'] ?? count($semua)) > $batas;

        $biar = array_flip($kolom);

        return [
            'baris' => array_map(fn ($baris): array => array_intersect_key((array) $baris, $biar), $halamanIni),
            'ada_lagi' => $adaLagi,
        ];
    }

    private function tool(string $sumber): CatalogReadTool
    {
        $this->spec ??= collect(CatalogDefinitions::all())->keyBy(fn (ResourceSpec $s) => $s->key)->all();

        if (! isset($this->spec[$sumber])) {
            throw new LogicException("Sumber layar bot tidak dikenal: {$sumber}");
        }

        return new CatalogReadTool($this->spec[$sumber]);
    }

    /**
     * @param  list<string>  $kolom
     */
    private function pastikanKolom(string $sumber, CatalogReadTool $tool, array $kolom): void
    {
        $ada = array_map(fn ($alias, $path) => is_string($alias) ? $alias : $path, array_keys($tool->spec()->list), $tool->spec()->list);

        foreach ($kolom as $k) {
            if (! in_array($k, $ada, true)) {
                throw new LogicException("Kolom whitelist layar bot tidak ada di spec {$sumber}: {$k}");
            }
        }
    }
}

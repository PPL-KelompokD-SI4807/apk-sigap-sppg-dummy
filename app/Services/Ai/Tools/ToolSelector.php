<?php

namespace App\Services\Ai\Tools;

use App\Enums\JenisInputAi;
use App\Services\Ai\TokenBudget;

/**
 * Pemilihan tool dinamis berdasar niat (D7) agar definisi tool muat dalam batas token penyedia
 * (Groq gratis: 8K token/menit per model, dan satu pesan bisa memakai 2-4 panggilan).
 *
 * Invarian keamanan: hasil SELALU subset dari $allowed (= ToolRegistry::forUser()); pemilihan tidak
 * pernah menambah tool di luar hak peran. Pemilihan hanya optimasi biaya: pemeriksaan hak tetap di
 * ToolRegistry::resolveFor() + Policy, dan AiAgentService menolak tool yang tidak ditawarkan.
 *
 * Aktif bila mode = on, atau mode = auto dan provider = openai_compat; dan estimasi token definisi
 * tool peran > threshold_tokens. Urutan prioritas: tool tulis sesuai kata kerja (tambah/ubah/hapus)
 * pada modul yang cocok -> tool cari_* modul tsb. + tool cari_* yang dirujuk skemanya -> tool baca
 * modul yang cocok; dipotong max_tools dan max_tool_tokens. Deskripsi diringkas (argumen tetap
 * divalidasi dengan skema lengkap AiTool::parameters()).
 */
class ToolSelector
{
    /** Kata kunci (awal kata, huruf kecil) per slug modul config/rbac.php. */
    private const MODULE_KEYWORDS = [
        'pengguna' => ['pengguna', 'user', 'akun', 'petugas', 'karyawan', 'staf', 'kurir', 'anggota'],
        'notifikasi-perjalanan' => ['perjalanan', 'berangkat', 'tiba', 'sampai', 'otw', 'jalan', 'posisi', 'notifikasi', 'estimasi'],
        'sppg' => ['sppg', 'kapasitas', 'profil', 'alamat dapur'],
        'titik-layanan' => ['titik', 'layanan', 'sekolah', 'sd', 'smp', 'sma', 'smk', 'paud', 'tk', 'posyandu', 'pesantren'],
        'penerima-manfaat' => ['penerima', 'manfaat', 'siswa', 'murid', 'anak', 'balita', 'bumil', 'busui', 'ibu hamil', 'sasaran', 'alergi'],
        'bahan-baku' => ['bahan', 'stok', 'gudang', 'beras', 'sayur', 'telur', 'ayam', 'daging', 'ikan', 'tempe', 'minyak', 'bumbu', 'buah', 'susu', 'kedaluwarsa', 'expired', 'mutasi', 'kg', 'liter', 'belanja', 'pakai'],
        'pemasok' => ['pemasok', 'supplier', 'vendor', 'toko', 'pasar', 'harga'],
        'menu-gizi' => ['menu', 'gizi', 'kalori', 'energi', 'protein', 'karbohidrat', 'lemak', 'resep', 'masakan', 'standar', 'akg'],
        'jadwal' => ['jadwal', 'rencana', 'produksi', 'distribusi', 'pengiriman', 'kirim', 'porsi'],
        'log-produksi' => ['log', 'produksi', 'masak', 'memasak', 'porsi', 'sisa'],
        'mutu' => ['mutu', 'uji', 'organoleptik', 'suhu', 'keamanan pangan', 'sampel', 'pemeriksaan', 'periksa', 'layak', 'cek rasa'],
        'armada' => ['armada', 'mobil', 'kendaraan', 'pengemudi', 'sopir', 'supir', 'driver', 'plat', 'motor', 'pikap'],
        'bukti-distribusi' => ['bukti', 'serah', 'terima', 'diterima', 'surat jalan', 'tanda tangan'],
        'keuangan' => ['keuangan', 'uang', 'dana', 'pencairan', 'pengeluaran', 'belanja', 'biaya', 'nota', 'kuitansi', 'kwitansi', 'struk', 'bayar', 'rp', 'rupiah', 'anggaran', 'saldo'],
        'keluhan' => ['keluhan', 'komplain', 'feedback', 'masukan', 'saran', 'aduan', 'keracunan', 'sakit', 'basi', 'mual'],
        'rekap-harian' => ['rekap', 'ringkasan', 'rangkuman', 'laporan', 'hari ini'],
        'persetujuan-menu' => ['persetujuan', 'pengajuan', 'setuju', 'disetujui', 'approve', 'revisi menu'],
    ];

    /** Kata kerja niat tulis (awal kata, boleh berimbuhan di-/me-/ter-/ber-). */
    private const VERBS = [
        'C' => ['tambah', 'nambah', 'buat', 'bikin', 'catat', 'input', 'masukkan', 'daftarkan', 'rekam', 'simpan', 'baru', 'laporkan', 'ajukan', 'terima'],
        'U' => ['ubah', 'ganti', 'edit', 'perbarui', 'update', 'koreksi', 'revisi', 'ralat', 'tandai', 'atur', 'selesaikan', 'tangani'],
        'D' => ['hapus', 'buang', 'batalkan', 'hilangkan', 'delete'],
    ];

    /** Kata tanya/baca: pesan berangka TANPA kata ini dianggap pencatatan (mis. "log produksi 240 porsi"). */
    private const READ_WORDS = ['berapa', 'apa', 'siapa', 'kapan', 'mana', 'bagaimana', 'cari', 'lihat', 'tampilkan', 'tunjukkan', 'daftar', 'cek', 'ada', 'adakah', 'apakah', 'total', 'rekap'];

    /** Token nama tool yang terlalu umum untuk dijadikan kata kunci. */
    private const GENERIC_TOKENS = ['cari', 'buat', 'ubah', 'hapus', 'lihat', 'catat', 'daftar', 'data', 'baku', 'harian'];

    public function isEnabled(): bool
    {
        return match ((string) config('ai.tool_selection.mode', 'auto')) {
            'on' => true,
            'off' => false,
            default => (string) config('ai.provider') === 'openai_compat',
        };
    }

    /**
     * @param  array<string, AiTool>  $allowed  hasil ToolRegistry::forUser()
     */
    public function select(array $allowed, string $text, JenisInputAi $type = JenisInputAi::Teks): ToolSelection
    {
        $full = self::schemas($allowed, false);
        $fullTokens = TokenBudget::estimate($full);

        if (! $this->isEnabled() || $fullTokens <= max(0, (int) config('ai.tool_selection.threshold_tokens', 2500))) {
            return new ToolSelection($allowed, $full, false, $fullTokens, $fullTokens);
        }

        $maxTools = max(1, (int) config('ai.tool_selection.max_tools', 10));
        $maxTokens = max(1, (int) config('ai.tool_selection.max_tool_tokens', 2500));
        $selected = [];
        $schemas = [];
        $tokens = 0;

        foreach ($this->rank($allowed, $text, $type) as $name) {
            if (count($selected) >= $maxTools) {
                break;
            }

            if (isset($selected[$name]) || ! isset($allowed[$name])) {
                continue;
            }

            $schema = self::schema($allowed[$name], true);
            $cost = TokenBudget::estimate($schema);

            if ($tokens + $cost > $maxTokens) {
                continue;
            }

            $selected[$name] = $allowed[$name];
            $schemas[] = $schema;
            $tokens += $cost;
        }

        $others = [];

        foreach ($allowed as $name => $tool) {
            if (! isset($selected[$name])) {
                $others[$tool->module()] = (string) config('rbac.modules.'.$tool->module().'.label', $tool->module());
            }
        }

        return new ToolSelection($selected, $schemas, true, $fullTokens, $tokens, array_values(array_unique($others)));
    }

    /**
     * Nama tool terurut prioritas (hanya dari $allowed).
     *
     * @param  array<string, AiTool>  $allowed
     * @return list<string>
     */
    public function rank(array $allowed, string $text, JenisInputAi $type = JenisInputAi::Teks): array
    {
        $haystack = ' '.trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text))).' ';

        if ($type === JenisInputAi::Foto) {
            // Foto tanpa keterangan jelas umumnya nota belanja / surat jalan.
            $haystack .= 'nota belanja ';
        }

        $intents = ['R' => true];

        foreach (self::VERBS as $letter => $verbs) {
            foreach ($verbs as $verb) {
                if (self::hasWord($haystack, $verb, true)) {
                    $intents[$letter] = true;
                    break;
                }
            }
        }

        if ($type === JenisInputAi::Foto || (count($intents) === 1 && preg_match('/\d/', $haystack) === 1 && ! self::hasAny($haystack, self::READ_WORDS))) {
            $intents['C'] = true;
        }

        $scores = [];

        foreach ($allowed as $name => $tool) {
            $score = 0;

            foreach (self::MODULE_KEYWORDS[$tool->module()] ?? [] as $keyword) {
                $score += self::hasWord($haystack, $keyword) ? 1 : 0;
            }

            foreach (self::nameTokens($name) as $token) {
                $score += self::hasWord($haystack, $token) ? 2 : 0;
            }

            if ($score > 0) {
                $scores[$name] = $score;
            }
        }

        // Urutan stabil: skor tertinggi dulu, seri mengikuti urutan registry.
        $order = array_flip(array_keys($allowed));
        uksort($scores, fn (string $a, string $b) => [$scores[$b], $order[$a]] <=> [$scores[$a], $order[$b]]);

        $ranked = [];

        foreach (array_keys($scores) as $name) {
            $tool = $allowed[$name];

            if (! $tool->isWrite() || ! self::matchesIntent($tool, $intents)) {
                continue;
            }

            $ranked[] = $name;

            // Tool cari_* untuk modul/sumber daya yang sama (mencari ID target) dan yang dirujuk skema.
            $suffix = (string) preg_replace('/^[a-z]+_/', '', $name);

            if (isset($allowed['cari_'.$suffix])) {
                $ranked[] = 'cari_'.$suffix;
            }

            preg_match_all('/\bcari_[a-z][a-z0-9_]*/', $tool->description().' '.json_encode($tool->parameters()), $m);

            foreach (array_unique($m[0]) as $dependency) {
                if (isset($allowed[$dependency])) {
                    $ranked[] = $dependency;
                }
            }
        }

        foreach (array_keys($scores) as $name) {
            if (! $allowed[$name]->isWrite()) {
                $ranked[] = $name;
            }
        }

        return array_values(array_unique($ranked));
    }

    /**
     * @param  array<string, AiTool>  $tools
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public static function schemas(array $tools, bool $compact): array
    {
        return array_values(array_map(fn (AiTool $tool) => self::schema($tool, $compact), $tools));
    }

    /** @return array{name: string, description: string, input_schema: array<string, mixed>} */
    public static function schema(AiTool $tool, bool $compact): array
    {
        return [
            'name' => $tool->name(),
            'description' => $compact ? self::shorten($tool->description(), 140, 200) : $tool->description(),
            'input_schema' => $compact ? self::compactSchema($tool->parameters()) : $tool->parameters(),
        ];
    }

    /** @param array<string, true> $intents */
    private static function matchesIntent(AiTool $tool, array $intents): bool
    {
        // catat_mutasi_stok (aksi U) dipakai untuk mencatat barang masuk/keluar.
        if ($tool->name() === 'catat_mutasi_stok') {
            return isset($intents['C']) || isset($intents['U']);
        }

        return isset($intents[$tool->action()]);
    }

    /** @return list<string> */
    private static function nameTokens(string $name): array
    {
        return array_values(array_filter(
            array_slice(explode('_', $name), 1),
            fn (string $t) => mb_strlen($t) >= 3 && ! in_array($t, self::GENERIC_TOKENS, true),
        ));
    }

    /** @param list<string> $words kata utuh */
    private static function hasAny(string $haystack, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($haystack, ' '.$word.' ')) {
                return true;
            }
        }

        return false;
    }

    private static function hasWord(string $haystack, string $word, bool $affixes = false): bool
    {
        $prefix = $affixes ? '(?:di|me|men|mem|meng|ter|ber)?' : '';

        return preg_match('/\s'.$prefix.preg_quote($word, '/').'/u', $haystack) === 1;
    }

    /** Potong ke kalimat utuh sejauh <= $soft karakter (minimal satu kalimat, maks $hard). */
    private static function shorten(string $text, int $soft, int $hard): string
    {
        $sentences = preg_split('/(?<=\.)\s+/u', trim($text)) ?: [$text];
        $out = '';

        foreach ($sentences as $sentence) {
            $next = $out === '' ? $sentence : $out.' '.$sentence;

            if ($out !== '' && mb_strlen($next) > $soft) {
                break;
            }

            $out = $next;
        }

        return mb_strlen($out) > $hard ? rtrim(mb_substr($out, 0, $hard - 1)).'…' : $out;
    }

    /**
     * Ringkas deskripsi properti (struktur, tipe, enum, batas, required TIDAK diubah).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function compactSchema(array $schema): array
    {
        foreach ((array) ($schema['properties'] ?? []) as $prop => $definition) {
            if (! is_array($definition) || ! is_string($definition['description'] ?? null)) {
                continue;
            }

            $schema['properties'][$prop]['description'] = $prop === 'keyakinan'
                ? 'Opsional: keyakinan 0-1 per kolom yang diisi.'
                : self::shorten($definition['description'], 80, 100);
        }

        return $schema;
    }
}

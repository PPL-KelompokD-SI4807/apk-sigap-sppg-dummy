<?php

namespace App\Services\Storage;

use App\Models\Lampiran;
use App\Models\User;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Unggahan gambar aman (NFR-06) untuk semua modul (bukti distribusi, keuangan, keluhan, mutu, ...).
 *
 * Alur: siapkan() [validasi + bersihkan, di luar transaksi] -> simpan() [tulis disk privat + baris
 * lampirans, di dalam transaksi modul]. Atau unggah() = keduanya sekaligus.
 *
 * Pemeriksaan siapkan():
 *  1. Unggahan PHP sah (is_uploaded_file/isValid), ukuran AKTUAL 1 B .. maks_byte.
 *  2. Jenis dari ISI: finfo(buffer) + tanda tangan byte (magic) + getimagesizefromstring harus
 *     sepakat dan termasuk allowlist (JPEG/PNG/WEBP). Ekstensi & MIME klien diabaikan.
 *  3. Dimensi > 0, sisi terpanjang <= maks_sisi_input_px, piksel <= maks_piksel.
 *  4. Pola polyglot (kode PHP/HTML/skrip/PDF/ZIP di mana pun dalam berkas) ditolak.
 *  5. Encode ulang via GD (buang EXIF/metadata/payload, perkecil ke 1600 px); tanpa GD -> mode ketat.
 *
 * simpan(): jalur uploads/{sppg_id}/{modul}/{uuid}.{ext} (ekstensi dari hasil deteksi), sha256 dan
 * ukuran berkas TERSIMPAN dicatat. Bila transaksi pemanggil di-rollback, berkas dihapus lagi.
 *
 * Kegagalan validasi -> ValidationException (422) pada nama field yang diberikan pemanggil.
 */
class SecureUploadService
{
    /** Pola berbahaya yang menandakan polyglot / payload tersisip (dicari case-insensitive). */
    private const POLA_POLYGLOT = [
        '/<\?php/i',
        '/<script[\s>\/]/i',
        '/<html[\s>]/i',
        '/<body[\s>]/i',
        '/<iframe[\s>]/i',
        '/<svg[\s>]/i',
        '/<!doctype\s/i',
        '/javascript:/i',
        '/__halt_compiler/i',
        '/%PDF-\d/',
        '/PK\x03\x04[\x0A\x14\x2D]\x00/',
    ];

    private const TANDA_TANGAN = [
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/png' => "\x89PNG\r\n\x1A\n",
    ];

    private readonly PemrosesGambar $pemrosesKetat;

    public function __construct(private readonly ?PemrosesGambar $pemroses = null)
    {
        $this->pemrosesKetat = new KetatPemrosesGambar;
    }

    /** True bila gambar di-encode ulang via GD (bukan mode ketat). */
    public static function modeEncodeUlang(): bool
    {
        return GdPemrosesGambar::tersedia();
    }

    /**
     * Validasi + bersihkan berkas. Panggil SEBELUM membuka transaksi (operasi CPU berat).
     *
     * @throws ValidationException
     */
    public function siapkan(UploadedFile $berkas, string $field = 'berkas'): GambarSiap
    {
        try {
            return $this->periksa($berkas);
        } catch (BerkasDitolak $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }

    /**
     * Tulis gambar ke disk privat dan catat di lampirans (polimorfik ke $pemilik).
     *
     * @param  string  $modul  slug modul dari KODE (bukan input), mis. 'bukti-distribusi'
     * @param  string|null  $kategori  mis. 'foto', 'berita_acara', 'nota', 'bukti'
     */
    public function simpan(GambarSiap $gambar, Model $pemilik, string $modul, ?User $pengunggah, ?string $kategori = null): Lampiran
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $modul) !== 1) {
            throw new InvalidArgumentException('Slug modul unggahan tidak valid.');
        }

        if ($kategori !== null && preg_match('/^[a-z0-9_]{1,30}$/', $kategori) !== 1) {
            throw new InvalidArgumentException('Kategori lampiran tidak valid.');
        }

        $sppgId = (int) $pemilik->getAttribute('sppg_id');

        if ($sppgId < 1 || ! $pemilik->exists) {
            throw new InvalidArgumentException('Pemilik lampiran harus record ber-SPPG yang sudah tersimpan.');
        }

        $path = sprintf('uploads/%d/%s/%s.%s', $sppgId, $modul, (string) Str::uuid(), $gambar->ekstensi);
        $disk = $this->disk();
        $disk->put($path, $gambar->isi);

        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => $this->hapusBerkas($path));
        }

        try {
            $lampiran = new Lampiran;
            $lampiran->forceFill([
                'sppg_id' => $sppgId,
                'lampiranable_type' => $pemilik->getMorphClass(),
                'lampiranable_id' => $pemilik->getKey(),
                'uploaded_by' => $pengunggah?->getKey(),
                'kategori' => $kategori,
                'path' => $path,
                'mime' => $gambar->mime,
                'ukuran' => $gambar->ukuran,
                'sha256' => $gambar->sha256,
            ])->save();
        } catch (\Throwable $e) {
            $this->hapusBerkas($path);

            throw $e;
        }

        return $lampiran;
    }

    /** siapkan() + simpan() sekaligus. */
    public function unggah(UploadedFile $berkas, Model $pemilik, string $modul, ?User $pengunggah, ?string $kategori = null, string $field = 'berkas'): Lampiran
    {
        return $this->simpan($this->siapkan($berkas, $field), $pemilik, $modul, $pengunggah, $kategori);
    }

    /**
     * Baca isi lampiran untuk disajikan. Null bila jalur tidak sah, berkas hilang, MIME di luar
     * allowlist, atau hash tidak cocok (berkas diubah di luar aplikasi).
     */
    public function baca(Lampiran $lampiran): ?string
    {
        $path = (string) $lampiran->path;
        $ext = config('unggahan.mime.'.$lampiran->mime);
        $pola = sprintf('#^uploads/%d/[a-z0-9][a-z0-9-]{0,39}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.%s$#', (int) $lampiran->sppg_id, preg_quote((string) $ext, '#'));

        if (! is_string($ext) || preg_match($pola, $path) !== 1) {
            Log::warning('Lampiran ditolak: jalur/MIME tidak sah.', ['lampiran_id' => $lampiran->id]);

            return null;
        }

        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return null;
        }

        $isi = (string) $disk->get($path);

        if ($lampiran->sha256 !== null && ! hash_equals((string) $lampiran->sha256, hash('sha256', $isi))) {
            Log::warning('Lampiran ditolak: sha256 tidak cocok.', ['lampiran_id' => $lampiran->id]);

            return null;
        }

        return $isi;
    }

    /**
     * Record induk lampiran bila jenisnya ada di allowlist config('unggahan.modul'). Dimuat lewat
     * query model (SppgScope aktif; induk terhapus lunak = null) -> pemanggil memberi 404.
     */
    public function induk(Lampiran $lampiran): ?Model
    {
        $kelas = $this->kelasModul($lampiran->lampiranable_type);

        if ($kelas === null) {
            return null;
        }

        $induk = $kelas::query()->find($lampiran->lampiranable_id);

        return $induk instanceof Model && (int) $induk->getAttribute('sppg_id') === (int) $lampiran->sppg_id ? $induk : null;
    }

    /** Slug modul allowlist untuk kelas/morph type, atau null. */
    public function slugModul(string $tipe): ?string
    {
        foreach ((array) config('unggahan.modul', []) as $slug => $def) {
            $kelas = $def['model'] ?? null;

            if (is_string($kelas) && ($kelas === $tipe || (new $kelas)->getMorphClass() === $tipe)) {
                return (string) $slug;
            }
        }

        return null;
    }

    /** @return class-string<Model>|null */
    private function kelasModul(string $tipe): ?string
    {
        $slug = $this->slugModul($tipe);

        return $slug === null ? null : config("unggahan.modul.{$slug}.model");
    }

    public function hapusBerkas(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (\Throwable) {
            // Berkas yatim dibersihkan manual; jangan gagalkan permintaan.
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('unggahan.disk', 'uploads'));
    }

    /** @throws BerkasDitolak */
    private function periksa(UploadedFile $berkas): GambarSiap
    {
        if (! $berkas->isValid()) {
            throw new BerkasDitolak('Berkas gagal diunggah. Coba lagi.');
        }

        $jalur = $berkas->getRealPath();

        if ($jalur === false || ! is_file($jalur) || is_link($jalur)) {
            throw new BerkasDitolak('Berkas gagal diunggah. Coba lagi.');
        }

        $maks = (int) config('unggahan.maks_byte', 5 * 1024 * 1024);
        $ukuran = filesize($jalur);

        if ($ukuran === false || $ukuran === 0) {
            throw new BerkasDitolak('Berkas kosong (0 byte).');
        }

        if ($ukuran > $maks) {
            throw new BerkasDitolak(sprintf('Ukuran berkas maksimal %d MB.', intdiv($maks, 1024 * 1024)));
        }

        $isi = file_get_contents($jalur, false, null, 0, $maks + 1);

        if ($isi === false || $isi === '' || strlen($isi) > $maks) {
            throw new BerkasDitolak('Berkas tidak dapat dibaca.');
        }

        $mime = $this->deteksiMime($isi);
        [$lebar, $tinggi] = $this->periksaDimensi($isi, $mime);
        $this->tolakPolyglot($isi);

        $pemroses = $this->pemroses
            ?? (GdPemrosesGambar::mendukung($mime) ? new GdPemrosesGambar : $this->pemrosesKetat);

        $hasil = $pemroses->proses($isi, $mime, $lebar, $tinggi);

        if ($hasil->ukuran === 0 || $this->deteksiMime($hasil->isi) !== $mime) {
            throw new BerkasDitolak('Gambar gagal diproses ulang.');
        }

        return $hasil;
    }

    /** @throws BerkasDitolak */
    private function deteksiMime(string $isi): string
    {
        $finfo = (new finfo(FILEINFO_MIME_TYPE))->buffer($isi);
        $diizinkan = (array) config('unggahan.mime', []);

        if (! is_string($finfo) || ! array_key_exists($finfo, $diizinkan)) {
            throw new BerkasDitolak('Hanya gambar JPEG, PNG, atau WebP yang diizinkan.');
        }

        $cocok = $finfo === 'image/webp'
            ? strlen($isi) >= 12 && substr($isi, 0, 4) === 'RIFF' && substr($isi, 8, 4) === 'WEBP'
            : str_starts_with($isi, self::TANDA_TANGAN[$finfo]);

        if (! $cocok) {
            throw new BerkasDitolak('Isi berkas tidak sesuai jenis gambar.');
        }

        return $finfo;
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @throws BerkasDitolak
     */
    private function periksaDimensi(string $isi, string $mime): array
    {
        $info = @getimagesizefromstring($isi);

        if ($info === false || ($info['mime'] ?? null) !== $mime) {
            throw new BerkasDitolak('Isi berkas tidak sesuai jenis gambar.');
        }

        $lebar = (int) $info[0];
        $tinggi = (int) $info[1];
        $maksSisi = (int) config('unggahan.maks_sisi_input_px', 8000);

        if ($lebar < 1 || $tinggi < 1) {
            throw new BerkasDitolak('Dimensi gambar tidak valid.');
        }

        if (max($lebar, $tinggi) > $maksSisi) {
            throw new BerkasDitolak("Sisi terpanjang gambar maksimal {$maksSisi} px.");
        }

        if ($lebar * $tinggi > (int) config('unggahan.maks_piksel', 30_000_000)) {
            throw new BerkasDitolak('Resolusi gambar terlalu besar.');
        }

        return [$lebar, $tinggi];
    }

    /** @throws BerkasDitolak */
    private function tolakPolyglot(string $isi): void
    {
        foreach (self::POLA_POLYGLOT as $pola) {
            if (preg_match($pola, $isi) === 1) {
                throw new BerkasDitolak('Berkas memuat konten tersembunyi yang tidak diizinkan.');
            }
        }
    }
}

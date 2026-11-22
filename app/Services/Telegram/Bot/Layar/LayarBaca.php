<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;
use App\Services\Telegram\Bot\BotLayar;

/**
 * Kontrak satu layar BACA-SAJA Bot (R-02 v2), dibuka lewat token `n1` atau perintah pintasan.
 *
 * Pemeriksaan hak TIDAK diserahkan ke layar: BotNavigasi selalu menegakkan sesi penuh, hak RBAC dari
 * hak() + modul() (config/rbac.php), dan keanggotaan SPPG sebelum izinkan()/render() dipanggil.
 * izinkan() hanya pemeriksaan TAMBAHAN (mis. Policy viewAny) dan render() hanya penyaji.
 *
 * Aturan untuk penulis layar (A2/B1):
 * - render() tidak boleh mengubah data dan tidak boleh menerbitkan tombol tulis (`b1`) untuk admin
 *   (jalur admin dijalankan di transaksi yang selalu di-rollback; token `b1` di dalamnya ikut hilang).
 * - Ambil data hanya untuk $sppgId yang diberikan (atau lewat tool baca yang memakai SppgBound/SppgScope).
 * - Semua nilai dinamis di-escape HTML; jangan menampilkan kolom sensitif (telepon, NIK, nama anak, uraian).
 */
interface LayarBaca
{
    /** Butuh Rbac::can(modul, 'R') dan BUKAN self-only (R*). */
    public const HAK_BACA = 'R';

    /** Butuh Rbac::can(modul, 'R'); self-only (R*) diterima (mis. Profil Saya). */
    public const HAK_BACA_MILIK_SENDIRI = 'R*';

    /** Butuh Rbac::canAggregate(modul) (hitungan per kategori tanpa data pribadi). */
    public const HAK_AGREGAT = 'agregat';

    /** Hanya sesi penuh, tanpa modul (beranda, pilih SPPG, Tanya Agen AI, bantuan). Id wajib ada di BotLayarRegistry::TANPA_MODUL. */
    public const HAK_SESI = 'sesi';

    /** Id pendek (pola BotNavCodec: huruf kecil/angka, maks. 8, diawali huruf). */
    public function id(): string;

    /** Modul RBAC (kunci config('rbac.modules')); null hanya untuk HAK_SESI. */
    public function modul(): ?string;

    /** Salah satu konstanta HAK_*. */
    public function hak(): string;

    /**
     * Layar memerlukan SPPG terpilih. Diabaikan (selalu true) untuk hak selain HAK_SESI: layar data
     * tidak pernah dirender tanpa konteks SPPG eksplisit (admin tanpa SPPG = lintas SPPG).
     */
    public function butuhSppg(): bool;

    /** Id layar induk untuk tombol Kembali (null = layar akar/beranda). */
    public function induk(): ?string;

    /** Jeda minimum (detik) antar-pembukaan layar ini per pengguna (layar berat/Segarkan). 0 = tanpa jeda. */
    public function jedaDetik(): int;

    /** Pemeriksaan tambahan (mis. Policy viewAny), dijalankan di dalam konteks ActingUser + SPPG. */
    public function izinkan(User $user, ?int $sppgId): bool;

    /** Penyaji; dipanggil hanya setelah semua pemeriksaan lolos, di dalam konteks yang sama. */
    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar;
}

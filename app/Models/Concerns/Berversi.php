<?php

namespace App\Models\Concerns;

/**
 * Penanda versi (kunci optimistik) untuk deteksi konflik penyuntingan bersamaan (HTTP 409).
 *
 * - Baris baru mulai di versi 1 (juga di memori, sehingga resource respons POST langsung memuat `versi`).
 * - Setiap UPDATE yang mengubah atribut menaikkan `versi` +1 secara otomatis, termasuk perubahan status oleh
 *   alur lain (notifikasi perjalanan, pembatalan jadwal induk, restore). Klien yang masih memegang versi lama
 *   mendapat 409 pada perubahan berikutnya. `touch()` (hanya updated_at) tidak menaikkan versi.
 * - Pembandingan versi dilakukan service pada baris yang dikunci (lockForUpdate) di dalam transaksi
 *   (lihat App\Support\KunciVersi), sehingga dua penyunting bersamaan tidak sama-sama lolos.
 * - `versi` tidak mass-assignable; hanya diubah oleh hook ini.
 */
trait Berversi
{
    public static function bootBerversi(): void
    {
        static::creating(function ($model): void {
            if ($model->getAttribute('versi') === null) {
                $model->setAttribute('versi', 1);
            }
        });

        static::updating(function ($model): void {
            if (! $model->isDirty('versi') && $model->isDirty()) {
                $model->setAttribute('versi', (int) $model->getOriginal('versi') + 1);
            }
        });
    }

    public function initializeBerversi(): void
    {
        $this->mergeCasts(['versi' => 'integer']);
    }
}

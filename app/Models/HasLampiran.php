<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Relasi lampiran (tabel lampirans) untuk model modul. Pakai bersama
 * App\Services\Storage\SecureUploadService (simpan) dan GET /api/v1/lampiran/{id} (sajikan).
 * Model juga perlu didaftarkan di config/unggahan.php 'modul' agar lampirannya dapat disajikan.
 */
trait HasLampiran
{
    public function lampirans(): MorphMany
    {
        return $this->morphMany(Lampiran::class, 'lampiranable')->orderBy('id');
    }

    /** Lampiran (sudah dimuat bila tersedia) dengan jalur tertentu, mis. foto_path. */
    public function lampiranUntukPath(?string $path): ?Lampiran
    {
        if ($path === null || $path === '') {
            return null;
        }

        $daftar = $this->relationLoaded('lampirans') ? $this->getRelation('lampirans') : $this->lampirans()->get();

        return $daftar->firstWhere('path', $path);
    }
}

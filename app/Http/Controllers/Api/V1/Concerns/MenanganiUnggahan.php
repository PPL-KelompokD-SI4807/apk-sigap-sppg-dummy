<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Services\Storage\GambarSiap;
use App\Services\Storage\SecureUploadService;
use Illuminate\Http\Request;

/**
 * Helper controller modul untuk field berkas opsional/wajib (mis. foto, nota, bukti).
 *
 *   $gambar = $this->siapkanGambar($request, ['foto', 'berita_acara']);   // di luar transaksi
 *   DB::transaction(fn () => ... $this->unggahan()->simpan($gambar['foto'], $model, 'modul', $user, 'foto'));
 *
 * Berkas tersimpan otomatis dihapus bila transaksi di-rollback (SecureUploadService::simpan).
 */
trait MenanganiUnggahan
{
    protected function unggahan(): SecureUploadService
    {
        return app(SecureUploadService::class);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, GambarSiap> hanya field yang dikirim
     */
    protected function siapkanGambar(Request $request, array $fields): array
    {
        $hasil = [];

        foreach ($fields as $field) {
            $berkas = $request->file($field);

            if ($berkas !== null && ! is_array($berkas)) {
                $hasil[$field] = $this->unggahan()->siapkan($berkas, $field);
            }
        }

        return $hasil;
    }
}

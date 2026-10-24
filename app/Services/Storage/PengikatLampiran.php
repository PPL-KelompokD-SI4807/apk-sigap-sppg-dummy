<?php

namespace App\Services\Storage;

use App\Models\Lampiran;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Mengikat unggahan tertunda (POST /lampiran tanpa lampiranable_id) ke record induk pada
 * store/update modul lewat `lampiran_ids`. WAJIB dipanggil di dalam transaksi modul (rollback
 * melepas ikatan, sehingga klien dapat mencoba lagi dengan id yang sama).
 *
 * Setiap id harus: milik pengguna yang mengirim (uploaded_by), SPPG sama dengan induk, belum
 * terikat, untuk modul yang sama, dan berkategori sesuai config('unggahan.modul.{modul}.kategori').
 * Total lampiran induk (yang sudah ada + baru) maksimal config 'maks' (default 5).
 * Semua penolakan memakai pesan generik di field lampiran_ids (tidak membocorkan id milik orang lain).
 * Ikatan memakai UPDATE atomik "... WHERE lampiranable_type IS NULL" sehingga dua permintaan
 * bersamaan tidak pernah sama-sama mengikat satu lampiran (ikatan ganda ditolak 422).
 */
class PengikatLampiran
{
    public const MAKS_DEFAULT = 5;

    private const PESAN = 'Lampiran tidak valid, bukan milik Anda, sudah terpakai, atau tidak sesuai modul.';

    /**
     * @param  array<int, mixed>  $ids  lampiran_ids tervalidasi (bentuk: integer unik)
     *
     * @throws ValidationException
     */
    public function ikat(array $ids, Model $induk, string $modul, User $pengguna): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $def = (array) config("unggahan.modul.{$modul}");
        $maks = (int) ($def['maks'] ?? self::MAKS_DEFAULT);
        $kategori = $def['kategori'] ?? null;

        $lampirans = Lampiran::query()->withoutGlobalScope(SppgScope::class)
            ->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

        foreach ($ids as $id) {
            /** @var Lampiran|null $l */
            $l = $lampirans->get($id);

            $sah = $l !== null
                && (int) $l->uploaded_by === (int) $pengguna->getKey()
                && (int) $l->sppg_id === (int) $induk->getAttribute('sppg_id')
                && $l->lampiranable_type === null
                && $l->lampiranable_id === null
                && $l->modul === $modul
                && (! is_array($kategori) || in_array($l->kategori, $kategori, true));

            if (! $sah) {
                throw ValidationException::withMessages(['lampiran_ids' => [self::PESAN]]);
            }
        }

        // Kunci baris induk: dua update bersamaan tidak sama-sama lolos batas jumlah.
        $induk->newQueryWithoutScopes()->whereKey($induk->getKey())->lockForUpdate()->first();

        $ada = Lampiran::query()->withoutGlobalScope(SppgScope::class)
            ->where('lampiranable_type', $induk->getMorphClass())
            ->where('lampiranable_id', $induk->getKey())
            ->count();

        if ($ada + count($ids) > $maks) {
            throw ValidationException::withMessages([
                'lampiran_ids' => ["Jumlah lampiran maksimal {$maks} per data (sudah ada {$ada})."],
            ]);
        }

        $terikat = Lampiran::query()->withoutGlobalScope(SppgScope::class)
            ->whereIn('id', $ids)
            ->whereNull('lampiranable_type')
            ->update([
                'lampiranable_type' => $induk->getMorphClass(),
                'lampiranable_id' => $induk->getKey(),
                'updated_at' => now(),
            ]);

        if ($terikat !== count($ids)) {
            throw ValidationException::withMessages(['lampiran_ids' => [self::PESAN]]);
        }

        $induk->unsetRelation('lampirans');
    }
}

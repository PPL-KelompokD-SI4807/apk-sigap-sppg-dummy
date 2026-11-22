<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * R04-13: lepas username dan no telepon dari baris pengguna yang SUDAH terhapus lunak agar identitas
 * itu dapat dipakai akun baru. Default dry run; perubahan hanya dengan --confirm. Nilai lama tidak
 * disimpan ulang di mana pun, dan keluaran/audit hanya memuat jumlah baris.
 */
class LepasIdentitasTerhapus extends Command
{
    public const EVENT_AUDIT = 'sistem.identitas_terhapus_dilepas';

    protected $signature = 'pengguna:lepas-identitas-terhapus {--confirm : Benar-benar mengubah data (tanpa opsi ini hanya dry run)}';

    protected $description = 'Kosongkan username/no telepon pada pengguna terhapus lunak (dry run kecuali --confirm; tidak dapat dibalik)';

    public function handle(): int
    {
        $terdampak = fn () => DB::table('users')->whereNotNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('username')->orWhereNotNull('phone'));

        if (! $this->option('confirm')) {
            $this->info('DRY RUN: baris terhapus lunak yang akan dilepas identitasnya: '.$terdampak()->count());
            $this->line('Tidak ada data yang diubah. Jalankan ulang dengan --confirm setelah cadangan DB dibuat.');

            return self::SUCCESS;
        }

        $jumlah = DB::transaction(function () use ($terdampak): int {
            $n = $terdampak()->update(['username' => null, 'phone' => null]);

            if ($n > 0) {
                $log = new AuditLog;
                $log->forceFill([
                    'sppg_id' => null,
                    'user_id' => null,
                    'event' => self::EVENT_AUDIT,
                    'auditable_type' => (new User)->getMorphClass(),
                    'auditable_id' => 0,
                    'old_values' => null,
                    'new_values' => ['jumlah_baris' => $n],
                    'sumber' => 'sistem',
                ])->save();
            }

            return $n;
        });

        $this->info("Identitas dilepas pada {$jumlah} baris terhapus lunak.");

        return self::SUCCESS;
    }
}

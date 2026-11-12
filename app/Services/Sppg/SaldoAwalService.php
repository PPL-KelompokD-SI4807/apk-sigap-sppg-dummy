<?php

namespace App\Services\Sppg;

use App\Models\AuditLog;
use App\Models\Sppg;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Saldo awal SPPG (R-07 Q-2): diisi manual oleh Akuntan/Admin. Tiap perubahan (nilai atau tanggal berbeda) dicatat
 * ke audit_logs dengan nilai lama/baru (nominal dan tanggal saja; tanpa data pribadi). Baris dikunci agar dua
 * pengubah serentak tidak saling menimpa tanpa jejak.
 */
class SaldoAwalService
{
    public function ubah(User $pengguna, Sppg $sppg, int $nominal, string $tanggal): Sppg
    {
        return DB::transaction(function () use ($pengguna, $sppg, $nominal, $tanggal): Sppg {
            $kunci = Sppg::query()->whereKey($sppg->getKey())->lockForUpdate()->firstOrFail();

            $lama = [
                'saldo_awal' => $kunci->saldo_awal === null ? null : (int) $kunci->saldo_awal,
                'saldo_awal_tanggal' => $kunci->saldo_awal_tanggal?->toDateString(),
            ];
            $baru = ['saldo_awal' => $nominal, 'saldo_awal_tanggal' => $tanggal];

            if ($lama === $baru) {
                return $kunci;
            }

            $kunci->forceFill($baru)->save();

            $request = App::runningInConsole() && ! App::runningUnitTests() ? null : request();
            $log = new AuditLog;
            $log->forceFill([
                'sppg_id' => $kunci->getKey(),
                'user_id' => $pengguna->getKey(),
                'event' => 'saldo_awal_diubah',
                'auditable_type' => $kunci->getMorphClass(),
                'auditable_id' => $kunci->getKey(),
                'old_values' => $lama,
                'new_values' => $baru,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
                'sumber' => AuditLog::currentSource() ?? ($request ? 'web' : 'sistem'),
            ]);
            $log->save();

            return $kunci;
        });
    }
}

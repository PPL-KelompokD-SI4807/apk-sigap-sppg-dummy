<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Auth\KredensialService;
use Illuminate\Console\Command;

/**
 * Reset password Admin dari server (R-03): jalan keluar bila Admin lupa password dan tidak ada
 * Admin lain. Password dikosongkan, penguncian dibuka, semua sesi dicabut; Admin lalu mengatur
 * password baru sendiri lewat Mini App (aktivasi + token bot). Password tidak pernah dicetak.
 */
class ResetPasswordAdmin extends Command
{
    protected $signature = 'sigap:reset-password-admin {telegram_id : ID numerik akun Telegram Admin} {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Reset password akun Admin (dikosongkan + semua sesi dicabut); Admin mengatur ulang sendiri lewat Mini App';

    public function handle(KredensialService $kredensial): int
    {
        $telegramId = (string) $this->argument('telegram_id');

        if (preg_match('/^[1-9]\d{0,17}$/', $telegramId) !== 1) {
            $this->error('telegram_id harus berupa bilangan bulat positif.');

            return self::FAILURE;
        }

        $admin = User::query()->withoutGlobalScope(SppgScope::class)
            ->where('telegram_id', (int) $telegramId)->first();

        // Hanya Admin aktif; pengguna lain direset oleh Admin lewat Mini App.
        if ($admin === null || $admin->role !== Role::Admin || ! $admin->is_active) {
            $this->error('Admin aktif dengan telegram_id tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Reset password Admin \"{$admin->name}\" dan cabut semua sesinya?")) {
            $this->line('Dibatalkan.');

            return self::FAILURE;
        }

        $kredensial->resetPassword($admin);
        $this->info('Password Admin direset dan semua sesi dicabut.');
        $this->line('Saat membuka Mini App, Admin wajib mengatur password baru (token dikirim bot).');

        return self::SUCCESS;
    }
}

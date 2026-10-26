<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Console\Command;

class BuatAdmin extends Command
{
    protected $signature = 'sigap:buat-admin {telegram_id : ID numerik akun Telegram} {nama : Nama tampilan (maks 120 karakter)}';

    protected $description = 'Buat pengguna Admin untuk telegram_id tertentu (idempoten; hanya nama dan telegram_id, tanpa data pribadi lain)';

    public function handle(): int
    {
        $telegramId = (string) $this->argument('telegram_id');
        $nama = trim((string) $this->argument('nama'));

        if (preg_match('/^[1-9]\d{0,17}$/', $telegramId) !== 1) {
            $this->error('telegram_id harus berupa bilangan bulat positif.');

            return self::FAILURE;
        }

        if ($nama === '' || mb_strlen($nama) > 120) {
            $this->error('nama wajib diisi dan maksimal 120 karakter.');

            return self::FAILURE;
        }

        $ada = User::query()->withoutGlobalScope(SppgScope::class)->withTrashed()
            ->where('telegram_id', (int) $telegramId)->first();

        if ($ada === null) {
            User::query()->create([
                'telegram_id' => (int) $telegramId,
                'name' => $nama,
                'role' => Role::Admin,
                'sppg_id' => null,
                'is_active' => true,
            ]);
            $this->info('Admin dibuat.');
            // R-03: tanpa password; wajib aktivasi (username + password + token bot) saat Mini App pertama dibuka.
            $this->line('Saat membuka Mini App pertama kali, admin wajib mengatur username dan password (token dikirim bot).');

            return self::SUCCESS;
        }

        // Jangan menaikkan hak akses, mengaktifkan, atau memulihkan akun yang sudah ada secara diam-diam.
        if ($ada->role === Role::Admin && $ada->is_active && ! $ada->trashed()) {
            $this->info('Admin sudah ada; tidak ada perubahan.');

            return self::SUCCESS;
        }

        $this->error('telegram_id sudah terdaftar sebagai akun lain (non-admin, nonaktif, atau terhapus). Ubah lewat Admin di Mini App.');

        return self::FAILURE;
    }
}

<?php

use App\Support\Telepon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * R-03 login kredensial (aditif). Kolom baru: username (unik, huruf kecil), password (hash, null =
 * belum diatur), password_changed_at, failed_login_attempts, locked_until.
 *
 * users.phone yang sudah ada dipakai sebagai no telepon login. Nilai lama hanya diubah formatnya
 * ke E.164 (Telepon::normalisasi, nomornya sama) bila hasilnya unik; nilai yang tidak dikenali atau
 * bentrok dibiarkan apa adanya. Indeks unik phone hanya dibuat bila tidak ada duplikat tersisa
 * (bila ada, migrasi tetap lanjut, peringatan dicatat tanpa nomor, dan validasi aplikasi menjaga
 * keunikan data baru). down() tidak mengembalikan format nomor (isi nomor tidak berubah).
 */
return new class extends Migration
{
    private const PHONE_UNIQUE = 'users_phone_unique';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->unique()->after('name');
            $table->string('password')->nullable()->after('phone');
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('password_changed_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
        });

        $this->normalisasiTelepon();

        $duplikat = DB::table('users')->whereNotNull('phone')
            ->select('phone')->groupBy('phone')->havingRaw('COUNT(*) > 1')->get()->count();

        if ($duplikat === 0) {
            Schema::table('users', fn (Blueprint $table) => $table->unique('phone', self::PHONE_UNIQUE));
        } else {
            Log::warning('migrasi.users.phone: indeks unik dilewati karena ada nomor ganda.', ['jumlah_nomor_ganda' => $duplikat]);
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('users', self::PHONE_UNIQUE)) {
            Schema::table('users', fn (Blueprint $table) => $table->dropUnique(self::PHONE_UNIQUE));
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'password', 'password_changed_at', 'failed_login_attempts', 'locked_until']);
        });
    }

    private function normalisasiTelepon(): void
    {
        $baris = DB::table('users')->whereNotNull('phone')->pluck('phone', 'id');
        $kelompok = [];

        foreach ($baris as $id => $phone) {
            $kelompok[Telepon::normalisasi((string) $phone) ?? 'mentah:'.$phone][] = $id;
        }

        foreach ($kelompok as $normal => $ids) {
            // Bentrok (dua akun dengan nomor yang sama) atau tak dikenali: jangan disentuh.
            if (count($ids) !== 1 || str_starts_with((string) $normal, 'mentah:')) {
                continue;
            }

            if ($baris[$ids[0]] !== $normal) {
                DB::table('users')->where('id', $ids[0])->update(['phone' => $normal]);
            }
        }
    }
};

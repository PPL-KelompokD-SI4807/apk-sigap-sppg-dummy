<?php

namespace App\Console\Commands;

use App\Models\Lampiran;
use App\Services\Storage\SecureUploadService;
use Illuminate\Console\Command;

class BersihkanLampiranTertunda extends Command
{
    protected $signature = 'sigap:bersihkan-lampiran-tertunda';

    protected $description = 'Hapus unggahan tertunda (tidak pernah diikat lewat lampiran_ids) yang lebih tua dari config unggahan.tertunda_ttl_jam, beserta berkasnya';

    public function handle(SecureUploadService $unggahan): int
    {
        $batas = now()->subHours(max(1, (int) config('unggahan.tertunda_ttl_jam', 24)));
        $jumlah = 0;

        Lampiran::query()->withoutGlobalScopes()
            ->whereNull('lampiranable_type')
            ->where('created_at', '<', $batas)
            ->chunkById(200, function ($lampirans) use ($unggahan, &$jumlah): void {
                foreach ($lampirans as $lampiran) {
                    // Hapus baris secara atomik dulu: bila tepat saat ini diikat (PengikatLampiran),
                    // baris tidak lagi cocok dan berkasnya dibiarkan.
                    $terhapus = Lampiran::query()->withoutGlobalScopes()
                        ->whereKey($lampiran->getKey())
                        ->whereNull('lampiranable_type')
                        ->delete();

                    if ($terhapus === 1) {
                        $unggahan->hapusBerkas((string) $lampiran->path);
                        $jumlah++;
                    }
                }
            });

        $this->info("Unggahan tertunda dihapus: {$jumlah}");

        return self::SUCCESS;
    }
}

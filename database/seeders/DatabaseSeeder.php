<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeder lain dipanggil hanya bila kelasnya ada (ditambahkan agen modul).
     * Seluruh data contoh fiktif.
     *
     * @var list<class-string<Seeder>>
     */
    protected array $optionalSeeders = [
        'Database\\Seeders\\DemoSeeder',
    ];

    public function run(): void
    {
        foreach ($this->optionalSeeders as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}

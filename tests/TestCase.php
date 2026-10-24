<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bahasa produk = Indonesia; dipaksa agar tes tidak bergantung pada APP_LOCALE lokal (.env).
        $this->app->setLocale('id');
    }
}

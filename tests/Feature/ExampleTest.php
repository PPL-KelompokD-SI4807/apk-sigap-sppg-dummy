<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * "/" dilayani nginx dari klien web (public/web); Laravel tidak lagi punya rute akar.
     */
    public function test_rute_akar_laravel_dipensiunkan(): void
    {
        $response = $this->get('/');

        $response->assertNotFound();
    }
}

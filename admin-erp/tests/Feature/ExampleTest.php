<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * This is an admin panel with no public landing page: "/" intentionally
     * sends visitors to the sign-in screen.
     */
    public function test_the_root_url_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_url_sends_visitors_to_the_console(): void
    {
        // Veyra has no public marketing page; / is a shortcut to the console.
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_the_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_url_is_the_public_website(): void
    {
        $this->get('/')->assertOk()->assertSee(route('member.register'));
    }

    public function test_the_console_is_behind_its_own_sign_in(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}

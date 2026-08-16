<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaShellTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_spa_entry_point_returns_the_isolated_react_shell(): void
    {
        $this->get('/app')
            ->assertOk()
            ->assertSee('id="root"', false)
            ->assertSee('name="theme-color"', false);
    }

    public function test_spa_deep_links_return_the_same_shell(): void
    {
        $this->get('/app/projects/123/edit')
            ->assertOk()
            ->assertSee('id="root"', false);
    }

    public function test_spa_fallback_does_not_replace_legacy_routes(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Log in');

        $this->get('/dashboard')
            ->assertRedirect('/login');
    }
}

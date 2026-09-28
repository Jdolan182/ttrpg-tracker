<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_visit_the_encounters_page()
    {
        $this->get('/')->assertInertia(fn ($page) => $page->component('Encounters/Index')->where('auth.user', null));
    }

    public function test_guests_can_visit_the_compendium_page()
    {
        $this->get('/compendium')->assertInertia(fn ($page) => $page->component('Compendium/Index')->where('auth.user', null));
    }

    public function test_authenticated_users_can_visit_the_encounters_page()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertInertia(fn ($page) => $page->component('Encounters/Index'));
    }

    public function test_authenticated_users_can_visit_the_compendium_page()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/compendium')->assertInertia(fn ($page) => $page->component('Compendium/Index'));
    }
}

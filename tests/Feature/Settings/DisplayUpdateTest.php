<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_page_is_displayed()
    {
        $this->actingAs(User::factory()->create())->get('/settings/display')->assertOk();
    }

    public function test_guests_cannot_change_display_settings()
    {
        $this->get('/settings/display')->assertRedirect('/login');
        $this->patch('/settings/display', ['stat_display' => 'score'])->assertRedirect('/login');
    }

    public function test_new_users_default_to_score_with_modifier()
    {
        $user = User::factory()->create();

        $this->assertSame('score_modifier', $user->fresh()->stat_display);
        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('auth.user.stat_display', 'score_modifier'));
    }

    public function test_stat_display_can_be_updated()
    {
        $user = User::factory()->create();

        foreach (['modifier_score', 'score', 'score_modifier'] as $display) {
            $this->actingAs($user)
                ->from('/settings/display')
                ->patch('/settings/display', ['stat_display' => $display])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/settings/display');

            $this->assertSame($display, $user->fresh()->stat_display);
        }
    }

    public function test_stat_display_must_be_a_known_option()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/display', ['stat_display' => 'emoji'])
            ->assertSessionHasErrors('stat_display');

        $this->assertSame('score_modifier', $user->fresh()->stat_display);
    }
}

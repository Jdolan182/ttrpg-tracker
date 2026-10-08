<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What search engines and link previews get in the first HTML response, which they read without
 * running the app's JavaScript.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_describe_themselves_and_can_be_indexed()
    {
        config()->set('app.name', 'Turnkeeper');

        $this->get('/')->assertOk()
            ->assertSee('<title inertia>Encounter &amp; Initiative Tracker for D&amp;D and Any TTRPG - Turnkeeper</title>', false)
            ->assertSee('<meta name="description" content="Free encounter and combat tracker for D&amp;D 5e and any other tabletop RPG', false)
            ->assertSee('<meta property="og:image" content="'.asset('og-image.png').'"', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'"', false)
            ->assertSee('"@type":"WebApplication"', false)
            ->assertDontSee('noindex');

        // A selected monster is the same page as far as search engines go.
        $this->get('/compendium?creature=12')->assertOk()
            ->assertSee('<title inertia>Compendium - Turnkeeper</title>', false)
            ->assertSee('<link rel="canonical" href="'.url('/compendium').'"', false);
    }

    public function test_private_pages_ask_not_to_be_indexed()
    {
        $this->get('/login')->assertSee('<meta name="robots" content="noindex">', false);
        $this->actingAs(User::factory()->create())->get('/settings/profile')
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertDontSee('rel="canonical"', false);
    }

    public function test_robots_and_the_sitemap_point_at_the_public_pages()
    {
        $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /settings')
            ->assertSee('Sitemap: '.url('sitemap.xml'));

        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->getContent();
        $urls = simplexml_load_string($sitemap);
        $this->assertSame(
            [url('/'), url('/compendium'), url('/register'), url('/privacy')],
            array_map(fn ($url) => (string) $url->loc, iterator_to_array($urls->url, false)),
        );
    }
}

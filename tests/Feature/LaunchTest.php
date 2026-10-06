<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The bits that matter once real people use it: the privacy note, the feedback link, and the
 * account emails' wording and links.
 */
class LaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_note_is_public_and_only_mentions_error_tracking_when_its_on()
    {
        config()->set('app.contact_email', 'privacy@example.test');
        config()->set('sentry.dsn', null);

        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Privacy')
            ->where('contactEmail', 'privacy@example.test')
            ->where('errorTracking', false));

        config()->set('sentry.dsn', 'https://key@o0.ingest.sentry.io/0');
        $this->get('/privacy')->assertInertia(fn (Assert $page) => $page->where('errorTracking', true));
    }

    public function test_the_feedback_link_comes_from_config_or_else_emails_the_contact_address()
    {
        config()->set('app.contact_email', null);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('feedbackUrl', null));

        config()->set('app.name', 'Turnkeeper');
        config()->set('app.contact_email', 'hello@example.test');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('feedbackUrl', 'mailto:hello@example.test?subject=Turnkeeper%20feedback'));

        config()->set('app.feedback_url', 'https://forms.example.test/turnkeeper');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('feedbackUrl', 'https://forms.example.test/turnkeeper'));
    }

    public function test_the_account_emails_use_the_apps_words_and_the_real_address()
    {
        config()->set('app.name', 'Initiative');
        config()->set('app.url', 'https://initiative.example');
        AppServiceProvider::pinLinksToAppUrl();
        $user = User::factory()->unverified()->create(['name' => 'Aria']);

        $verify = (new VerifyEmail)->toMail($user);
        $this->assertSame('Confirm your email for Initiative', $verify->subject);
        $this->assertSame('Hi Aria,', $verify->greeting);
        $this->assertStringStartsWith('https://initiative.example/verify-email/', $verify->actionUrl);

        $reset = (new ResetPassword('a-token'))->toMail($user);
        $this->assertSame('Reset your Initiative password', $reset->subject);
        $this->assertStringStartsWith('https://initiative.example/reset-password/a-token', $reset->actionUrl);

        // Both say how long their link lasts the same way.
        $this->assertStringContainsString('The link works for 1 hour.', implode(' ', $verify->outroLines));
        config()->set('auth.passwords.users.expire', 90);
        $this->assertStringContainsString('The link works for 90 minutes.', implode(' ', (new ResetPassword('a-token'))->toMail($user)->outroLines));
    }

    public function test_seeding_a_real_server_makes_no_test_account()
    {
        $this->app['env'] = 'production';
        // Run directly: db:seed would stop to ask "are you sure?" in production.
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('creatures', ['user_id' => null]);
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Proxies whose X-Forwarded-* headers are believed, for a load balancer in front of the app one
        // day. Set here rather than in bootstrap/app.php because .env hasn't been read yet when that
        // file runs. Unset (as on the server, where Caddy talks to PHP directly) means trust none.
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        self::pinLinksToAppUrl();
        $this->wordEmails();
        $this->limitRequests();

        // The admin page: only accounts listed in ADMIN_EMAILS, and only once that email is confirmed, so
        // nobody gets in by signing up with the address before its owner does.
        Gate::define('viewAdmin', fn (User $user) => $user->hasVerifiedEmail()
            && in_array(strtolower($user->email), config('app.admin_emails'), true));
    }

    /**
     * Rate limits on top of the plan limits, which cap how much an account can have but not how
     * fast it acts. Sign-ups and reset requests each send an email, so a script hammering them would
     * use up the mail provider's allowance and hurt the domain's reputation.
     */
    private function limitRequests(): void
    {
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Every change a signed-in account makes, far above how fast anyone clicks. Applied to the
        // whole web group, so reading pages is left alone, and the live player view has its own
        // higher limit because a busy fight sends many small updates.
        RateLimiter::for('writes', function (Request $request) {
            if ($request->isMethodSafe() || ! $request->user() || $request->routeIs('campaigns.combat.update')) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->user()->id);
        });
    }

    /**
     * With a real https address (production), every generated link uses it: emails, invites and
     * redirects then never come out as http:// or with the server's internal host, whatever the
     * proxy in front sends. Local http://localhost is left alone.
     */
    public static function pinLinksToAppUrl(): void
    {
        $appUrl = (string) config('app.url');
        if (! str_starts_with($appUrl, 'https://')) {
            return;
        }

        URL::forceRootUrl($appUrl);
        URL::forceScheme('https');
    }

    /**
     * The account emails in the app's own words rather than the framework's defaults. Their links
     * come from APP_URL, so it has to be the real address in production.
     */
    private function wordEmails(): void
    {
        // The app's name is read when an email is sent, not when the app boots.
        VerifyEmail::toMailUsing(fn (User $user, string $url) => (new MailMessage)
            ->subject('Confirm your email for '.config('app.name'))
            ->greeting("Hi {$user->name},")
            ->line('Thanks for signing up to '.config('app.name').". Confirm this is your email address and you're all set: you'll be able to save encounters, make your own creatures and join your group's campaigns.")
            ->action('Confirm my email', $url)
            ->line('The link works for '.self::lifetime(config('auth.verification.expire', 60)).". If you didn't sign up, you can ignore this email and nothing will happen.")
            ->salutation(self::signOff()));

        ResetPassword::toMailUsing(fn (User $user, string $token) => (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->greeting("Hi {$user->name},")
            ->line('Someone (hopefully you) asked to reset the password for your account.')
            ->action('Choose a new password', route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]))
            ->line('The link works for '.self::lifetime(config('auth.passwords.users.expire', 60)).". If you didn't ask for this, ignore this email: your password stays the same.")
            ->salutation(self::signOff()));
    }

    // How long a link lasts, from its expiry in minutes: "1 hour", "2 hours", or "90 minutes".
    private static function lifetime(int $minutes): string
    {
        if ($minutes % 60 !== 0) {
            return "{$minutes} minutes";
        }
        $hours = intdiv($minutes, 60);

        return $hours === 1 ? '1 hour' : "{$hours} hours";
    }

    // Two trailing spaces are a Markdown line break, so the name goes on its own line.
    private static function signOff(): string
    {
        return "See you at the table,  \n".config('app.name');
    }
}

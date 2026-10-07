<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counts visits for the admin page, as daily totals only: no cookies beyond the session the site
 * already uses, no stored IP addresses, nothing that follows anyone. The privacy page says so; keep
 * it true when this changes.
 */
class Visits
{
    // Crawlers, link previews, uptime checks and scripts aren't people.
    private const NOT_PEOPLE = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|headless|lighthouse|monitor|uptime|curl|wget|python|httpclient|okhttp|go-http|java\/|powershell|^$/i';

    // Requests that aren't someone looking at a page.
    private const IGNORED_PATHS = ['up', 'robots.txt', 'sitemap.xml'];

    /**
     * Counts this request's visitor (and their account) once a day. Called after every successful GET.
     */
    public static function record(Request $request): void
    {
        if (preg_match(self::NOT_PEOPLE, (string) $request->userAgent()) || in_array($request->path(), self::IGNORED_PATHS, true)) {
            return;
        }

        $day = today()->toDateString();
        $user = $request->user();

        // Most requests stop here: this session has already been counted today.
        $seen = $request->session()->get('visit', []);
        if (($seen['day'] ?? null) !== $day) {
            $seen = ['day' => $day, 'visitor' => false, 'account' => false];
        }
        $countVisitor = ! $seen['visitor'];
        $countAccount = $user !== null && ! $seen['account'];
        if (! $countVisitor && ! $countAccount) {
            return;
        }
        $request->session()->put('visit', ['day' => $day, 'visitor' => true, 'account' => $seen['account'] || $user !== null]);

        // A new session (another tab after cookies were cleared, a second browser) isn't a new person, so
        // check the day's codes too. The same device and browser makes the same code all day.
        $newVisitor = $countVisitor && self::firstToday($day, 'v|'.$request->ip().'|'.$request->userAgent());
        $newAccount = $countAccount && self::firstToday($day, 'a|'.$user->id);

        if ($newVisitor || $newAccount) {
            DB::statement(
                'INSERT INTO daily_stats (day, visitors, accounts) VALUES (?, ?, ?)
                 ON CONFLICT (day) DO UPDATE SET visitors = daily_stats.visitors + excluded.visitors, accounts = daily_stats.accounts + excluded.accounts',
                [$day, (int) $newVisitor, (int) $newAccount],
            );
        }
        if ($countAccount) {
            self::markActive($user);
        }
    }

    /**
     * Notes the most campaigns in live combat at the same time today. Called whenever a DM's tracker
     * sends a live fight, so busy moments are caught as they happen.
     */
    public static function recordLiveTables(): void
    {
        // A fight counts as live while its tracker has sent it recently; one left open overnight doesn't.
        $live = Campaign::whereNotNull('live')->where('live_updated_at', '>=', now()->subMinutes(10))->count();

        DB::statement(
            'INSERT INTO daily_stats (day, peak_live_tables) VALUES (?, ?)
             ON CONFLICT (day) DO UPDATE SET peak_live_tables = GREATEST(daily_stats.peak_live_tables, excluded.peak_live_tables)',
            [today()->toDateString(), $live],
        );
    }

    /** True the first time `$who` is seen on `$day`. */
    private static function firstToday(string $day, string $who): bool
    {
        // Keyed with the app's secret and the date, so the code changes every day and can't be reversed.
        $key = hash_hmac('sha256', $who, config('app.key').'|'.$day);
        $inserted = DB::table('visit_keys')->insertOrIgnore(['day' => $day, 'key' => $key]) > 0;

        // Yesterday's codes are only needed until today starts filling in.
        if ($inserted && random_int(1, 50) === 1) {
            DB::table('visit_keys')->where('day', '<', today()->subDay()->toDateString())->delete();
        }

        return $inserted;
    }

    private static function markActive(User $user): void
    {
        if ($user->last_active_at === null || $user->last_active_at->lt(today())) {
            // Straight to the table, so it doesn't count as the account itself being edited (updated_at).
            DB::table('users')->where('id', $user->id)->update(['last_active_at' => now()]);
        }
    }
}

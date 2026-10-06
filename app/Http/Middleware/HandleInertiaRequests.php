<?php

namespace App\Http\Middleware;

use App\Support\Limits;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            // For "Send feedback": the configured form, else an email to the contact address. Null hides it.
            'feedbackUrl' => config('app.feedback_url')
                ?: (config('app.contact_email') ? 'mailto:'.config('app.contact_email').'?subject='.rawurlencode(config('app.name').' feedback') : null),
            'auth' => [
                'user' => $request->user(),
            ],
            // Usage against the account's limits, e.g. creatures: {used: 12, limit: 25}. Null for guests.
            'limits' => fn () => $request->user() ? Limits::summary($request->user()) : null,
            'flash' => [
                // Set after saving an encounter so the tracker can switch to the saved copy.
                'savedEncounterId' => fn () => $request->session()->get('savedEncounterId'),
                // What an import added (its own key: the auth pages use "status" for theirs).
                'imported' => fn () => $request->session()->get('imported'),
            ],
        ]);
    }
}

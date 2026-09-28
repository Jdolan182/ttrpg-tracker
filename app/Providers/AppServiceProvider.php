<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Vite;
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
        // Proxies whose X-Forwarded-* headers are believed, e.g. a Cloudflare tunnel ("*") while
        // sharing, so links come out as https://. Set here rather than in bootstrap/app.php because
        // .env hasn't been read yet when that file runs. Unset means trust none.
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // While sharing through a tunnel (scripts/share.sh), serve the built assets even if the Vite
        // dev server is running: visitors can't reach localhost:5173. Pointing Vite at a hot file
        // that never exists does that without touching the dev server's own file.
        if (config('app.share_mode')) {
            Vite::useHotFile(storage_path('framework/vite.hot.disabled-while-sharing'));
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\SiteStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's view of how the site is used: totals and daily counts, never anyone's content.
 */
class AdminController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Not found rather than forbidden: nobody else should learn there's a page here.
        abort_unless(Gate::allows('viewAdmin'), 404);

        return Inertia::render('Admin/Index', [
            'summary' => SiteStats::summary(7),
            'traffic' => SiteStats::traffic(30),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DisplayController extends Controller
{
    /**
     * Show the display settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Display');
    }

    /**
     * Update how the user's stat blocks show stats.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stat_display' => ['required', Rule::in(User::STAT_DISPLAYS)],
        ]);

        $request->user()->update($validated);

        return back();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Encounter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Which of the DM's saved encounters belong to a campaign. */
class CampaignEncounterController extends Controller
{
    public function store(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = $request->validate([
            'encounter_id' => ['required', 'integer', Rule::exists('encounters', 'id')->where('user_id', $request->user()->id)],
        ]);

        Encounter::findOrFail($data['encounter_id'])->campaign()->associate($campaign)->save();

        return back();
    }

    /** Takes an encounter out of the campaign; it stays saved. */
    public function destroy(Campaign $campaign, Encounter $encounter): RedirectResponse
    {
        Gate::authorize('update', $campaign);
        abort_unless($encounter->campaign_id === $campaign->id, 404);

        $encounter->campaign()->dissociate()->save();

        return back();
    }
}

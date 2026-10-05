<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Creature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The party: the DM's player characters in a campaign. Characters live in the DM's compendium;
 * being in a party is just their campaign_id.
 */
class CampaignPartyController extends Controller
{
    /** Puts one of the DM's player characters in this party (moving it out of another campaign if need be). */
    public function store(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $data = $request->validate([
            'creature_id' => ['required', 'integer', Rule::exists('creatures', 'id')->where('user_id', $request->user()->id)->where('kind', 'player')],
        ]);

        $creature = Creature::findOrFail($data['creature_id']);
        if ($creature->campaign_id && $creature->campaign_id !== $campaign->id) {
            self::releaseClaims($creature);
        }
        $creature->campaign()->associate($campaign)->save();

        return back();
    }

    /** Takes a character out of the party. It stays in the DM's compendium. */
    public function destroy(Campaign $campaign, Creature $creature): RedirectResponse
    {
        Gate::authorize('update', $campaign);
        abort_unless($creature->campaign_id === $campaign->id, 404);

        self::releaseClaims($creature);
        $creature->campaign()->dissociate()->save();

        return back();
    }

    /** A character leaving a party is no longer anyone's there. */
    private static function releaseClaims(Creature $creature): void
    {
        DB::table('campaign_user')->where('character_id', $creature->id)->update(['character_id' => null]);
    }
}

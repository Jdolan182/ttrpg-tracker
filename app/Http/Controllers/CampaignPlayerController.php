<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\User;
use App\Support\Limits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Joining through an invite link, claiming a character, leaving, and the DM removing players. */
class CampaignPlayerController extends Controller
{
    /**
     * The invite page. Guests are asked to log in or sign up first, then come straight back here.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $campaign = Campaign::where('invite_token', $token)->with('owner:id,name')->firstOrFail();
        $user = $request->user();

        if ($campaign->isRunBy($user) || $campaign->hasPlayer($user)) {
            return to_route('campaigns.show', $campaign);
        }
        if (! $user) {
            $request->session()->put('url.intended', $request->url());
        }

        return Inertia::render('Campaigns/Join', [
            'token' => $token,
            'campaign' => ['name' => $campaign->name, 'description' => $campaign->description, 'dm' => $campaign->owner->name],
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $campaign = Campaign::where('invite_token', $token)->firstOrFail();
        $user = $request->user();

        if ($campaign->isRunBy($user) || $campaign->hasPlayer($user)) {
            return to_route('campaigns.show', $campaign);
        }

        Limits::ensureCanCreate($user, 'campaigns_joined');
        Limits::ensureCampaignHasRoom($campaign);

        $campaign->players()->attach($user->id);

        return to_route('campaigns.show', $campaign);
    }

    /** A player picks which party character is theirs, or clears it with null. */
    public function claim(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('claim', $campaign);

        $data = $request->validate([
            'character_id' => ['nullable', 'integer', Rule::exists('creatures', 'id')->where('campaign_id', $campaign->id)],
        ]);
        $characterId = $data['character_id'] ?? null;

        $takenBy = $characterId === null ? null : $campaign->players()->wherePivot('character_id', $characterId)->first();
        if ($takenBy && $takenBy->id !== $request->user()->id) {
            throw ValidationException::withMessages(['character_id' => "{$takenBy->name} is already playing that character."]);
        }

        $campaign->players()->updateExistingPivot($request->user()->id, ['character_id' => $characterId]);

        return back();
    }

    /** A player leaving, or the DM removing them. */
    public function destroy(Request $request, Campaign $campaign, User $player): RedirectResponse
    {
        $isSelf = $request->user()->id === $player->id;
        abort_unless($isSelf || $campaign->isRunBy($request->user()), 403);
        abort_unless($campaign->hasPlayer($player), 404);

        $campaign->players()->detach($player->id);

        return $isSelf ? to_route('campaigns.index') : back();
    }
}

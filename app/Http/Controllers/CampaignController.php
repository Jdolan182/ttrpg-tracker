<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\User;
use App\Support\Limits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    /**
     * Campaigns you run, and campaigns you play in.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Campaigns/Index', [
            'running' => $user->campaigns()->withCount(['players', 'party', 'encounters'])->latest('updated_at')->get()
                ->map(fn (Campaign $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'players' => $c->players_count,
                    'party' => $c->party_count,
                    'encounters' => $c->encounters_count,
                ]),
            'playing' => $user->joinedCampaigns()->with('owner:id,name')->latest('campaign_user.created_at')->get()
                ->map(fn (Campaign $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'dm' => $c->owner->name,
                    'character' => $c->pivot->character_id ? Creature::find($c->pivot->character_id)?->name : null,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        Limits::ensureCanCreate($request->user(), 'campaigns');

        $campaign = $request->user()->campaigns()->create($data);

        return to_route('campaigns.show', $campaign);
    }

    /**
     * The campaign page. The DM gets everything needed to run it; players get the details and party.
     */
    public function show(Request $request, Campaign $campaign): Response
    {
        Gate::authorize('view', $campaign);

        $user = $request->user();
        $isDm = $campaign->isRunBy($user);
        $players = $campaign->players()->orderBy('name')->get(['users.id', 'users.name']);
        $claimedBy = $players->filter(fn (User $p) => $p->pivot->character_id)->mapWithKeys(fn (User $p) => [$p->pivot->character_id => $p->name]);

        return Inertia::render('Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'enemyHp' => $campaign->enemy_hp,
                'dm' => $campaign->owner->name,
                'isDm' => $isDm,
                'inviteUrl' => $isDm ? route('campaigns.join', $campaign->invite_token) : null,
                'playerLimit' => Limits::limit($campaign->owner, 'campaign_players'),
            ],
            'party' => $campaign->party()->orderBy('name')->get()->map(fn (Creature $c) => [
                ...$c->toFrontend(),
                'claimedBy' => $claimedBy[$c->id] ?? null,
            ]),
            'players' => $players->map(fn (User $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'characterId' => $p->pivot->character_id,
            ]),
            // The current player's own claim, so they can change it.
            'myCharacterId' => $isDm ? null : $players->firstWhere('id', $user->id)?->pivot->character_id,
            // DM-only lists for running the campaign.
            'encounters' => $isDm ? $campaign->encounters()->latest('updated_at')->get(['id', 'name', 'round', 'updated_at'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'round' => $e->round, 'updatedAt' => $e->updated_at->toIso8601String()]) : [],
            'availableEncounters' => $isDm ? $user->encounters()->whereNull('campaign_id')->orderBy('name')->get(['id', 'name']) : [],
            'availableCharacters' => $isDm ? $user->creatures()->where('kind', 'player')
                ->where(fn ($q) => $q->whereNull('campaign_id')->orWhere('campaign_id', '!=', $campaign->id))
                ->orderBy('name')->get(['id', 'name', 'campaign_id']) : [],
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $campaign->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'enemy_hp' => ['required', Rule::in(Campaign::ENEMY_HP)],
        ]));

        return back();
    }

    /**
     * Deleting a campaign keeps its characters and encounters; they just leave the campaign.
     */
    public function destroy(Campaign $campaign): RedirectResponse
    {
        Gate::authorize('delete', $campaign);

        $campaign->delete();

        return to_route('campaigns.index');
    }

    /** A new invite link; the old one stops working. */
    public function resetInvite(Campaign $campaign): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $campaign->forceFill(['invite_token' => Campaign::newInviteToken()])->save();

        return back();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCreatureRequest;
use App\Models\Creature;
use App\Support\Limits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CreatureController extends Controller
{
    /**
     * The compendium: SRD creatures for everyone, plus the signed-in user's own.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Compendium/Index', [
            'creatures' => Creature::visibleTo($request->user())->orderBy('name')->get()->map->toFrontend(),
            'selectedId' => $request->integer('creature') ?: null,
        ]);
    }

    /**
     * New creature form. `?from={id}` pre-fills it with a copy of a creature the user can see;
     * `?campaign={id}` makes it a player character for that campaign's party.
     */
    public function create(Request $request): Response
    {
        $from = $request->integer('from')
            ? Creature::visibleTo($request->user())->find($request->integer('from'))
            : null;
        $campaign = $request->integer('campaign') ? $request->user()->campaigns()->find($request->integer('campaign')) : null;

        return Inertia::render('Compendium/CreatureForm', [
            'creature' => null,
            'template' => $from?->toFrontend(),
            'campaign' => $campaign ? ['id' => $campaign->id, 'name' => $campaign->name] : null,
        ]);
    }

    public function store(SaveCreatureRequest $request): RedirectResponse
    {
        Limits::ensureCanCreate($request->user(), 'creatures');

        $campaignId = $request->integer('campaign_id') ?: null;
        $campaign = $campaignId ? $request->user()->campaigns()->findOrFail($campaignId) : null;

        $attributes = $request->toAttributes();
        // A party member is always a player character.
        if ($campaign) {
            $attributes['kind'] = 'player';
        }

        $creature = $request->user()->creatures()->make($attributes);
        $creature->campaign()->associate($campaign);
        $creature->save();

        return $campaign
            ? to_route('campaigns.show', $campaign)
            : to_route('compendium.index', ['creature' => $creature->id]);
    }

    public function edit(Creature $creature): Response
    {
        Gate::authorize('update', $creature);

        return Inertia::render('Compendium/CreatureForm', [
            'creature' => $creature->toFrontend(),
            'template' => null,
        ]);
    }

    public function update(SaveCreatureRequest $request, Creature $creature): RedirectResponse
    {
        Gate::authorize('update', $creature);

        $creature->update($request->toAttributes());

        return to_route('compendium.index', ['creature' => $creature->id]);
    }

    public function destroy(Creature $creature): RedirectResponse
    {
        Gate::authorize('delete', $creature);

        $creature->delete();

        return to_route('compendium.index');
    }
}

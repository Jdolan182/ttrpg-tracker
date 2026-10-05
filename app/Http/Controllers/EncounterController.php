<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveEncounterRequest;
use App\Models\Campaign;
use App\Models\Creature;
use App\Models\Encounter;
use App\Support\EncounterPayload;
use App\Support\Limits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EncounterController extends Controller
{
    /**
     * Show the encounter tracker. Guests can run encounters with SRD creatures but can't save them.
     *
     * Saved encounters are listed by name only; just one is sent in full (combatants and history):
     * the one asked for with ?encounter={id}, or else the most recently updated. Props are closures
     * so switching encounters can reload only `openEncounter`.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Encounters/Index', [
            'savedEncounters' => fn () => $user
                ? $user->encounters()->latest('updated_at')->get(['id', 'name'])->map(fn (Encounter $e) => ['id' => $e->id, 'name' => $e->name])
                : [],
            'openEncounter' => function () use ($request, $user) {
                if (! $user) {
                    return null;
                }

                $encounters = $user->encounters();
                $encounter = $request->filled('encounter')
                    ? $encounters->find($request->integer('encounter'))
                    : $encounters->latest('updated_at')->first();

                return $encounter?->toFrontend();
            },
            'creatures' => fn () => Creature::visibleTo($user)->orderBy('name')->get()->map->toFrontend(),
            // Campaigns the user runs, with their party, for the campaign picker and "Add party",
            // and their enemy HP setting for the DM's own player view.
            'campaigns' => fn () => $user
                ? $user->campaigns()->with('party:id,campaign_id')->orderBy('name')->get(['id', 'name', 'enemy_hp'])
                    ->map(fn (Campaign $c) => ['id' => $c->id, 'name' => $c->name, 'enemyHp' => $c->enemy_hp, 'partyIds' => $c->party->pluck('id')])
                : [],
            // From a campaign's "New encounter" button: start a fresh encounter in that campaign.
            'newInCampaign' => $user && $request->filled('new_in_campaign')
                ? $user->campaigns()->whereKey($request->integer('new_in_campaign'))->value('id')
                : null,
        ]);
    }

    /**
     * All your saved encounters at a glance, to open, export or tidy up.
     */
    public function list(Request $request): Response
    {
        return Inertia::render('Encounters/List', [
            'encounters' => $request->user()->encounters()->with('campaign:id,name')->latest('updated_at')->get()
                ->map(fn (Encounter $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'campaign' => $e->campaign ? ['id' => $e->campaign->id, 'name' => $e->campaign->name] : null,
                    'round' => $e->round,
                    'combatants' => array_map(fn (array $c) => ['name' => $c['name'], 'side' => $c['side'] ?? 'enemy'], $e->combatants),
                    'updatedAt' => $e->updated_at->toIso8601String(),
                ]),
        ]);
    }

    public function store(SaveEncounterRequest $request): RedirectResponse
    {
        Limits::ensureCanCreate($request->user(), 'encounters');

        $encounter = $request->user()->encounters()->create(EncounterPayload::toAttributes($request->validated(), $request->user()));

        return to_route('encounters.index')->with('savedEncounterId', $encounter->id);
    }

    public function update(SaveEncounterRequest $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize('update', $encounter);

        $encounter->update(EncounterPayload::toAttributes($request->validated(), $request->user()));

        return to_route('encounters.index')->with('savedEncounterId', $encounter->id);
    }

    public function destroy(Encounter $encounter): RedirectResponse
    {
        Gate::authorize('delete', $encounter);

        $encounter->delete();

        // Deleting happens from the tracker and from the list; stay where you were.
        return back();
    }
}

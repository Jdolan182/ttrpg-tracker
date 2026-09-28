<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveEncounterRequest;
use App\Models\Creature;
use App\Models\Encounter;
use App\Support\EncounterPayload;
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
        ]);
    }

    public function store(SaveEncounterRequest $request): RedirectResponse
    {
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

        return to_route('encounters.index');
    }
}

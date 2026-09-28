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
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Encounters/Index', [
            'savedEncounters' => $user
                ? $user->encounters()->latest('updated_at')->get()->map->toFrontend()
                : [],
            'creatures' => Creature::visibleTo($user)->orderBy('name')->get()->map->toFrontend(),
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

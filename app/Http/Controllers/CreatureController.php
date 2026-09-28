<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCreatureRequest;
use App\Models\Creature;
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
     * New creature form. `?from={id}` pre-fills it with a copy of a creature the user can see.
     */
    public function create(Request $request): Response
    {
        $from = $request->integer('from')
            ? Creature::visibleTo($request->user())->find($request->integer('from'))
            : null;

        return Inertia::render('Compendium/CreatureForm', [
            'creature' => null,
            'template' => $from?->toFrontend(),
        ]);
    }

    public function store(SaveCreatureRequest $request): RedirectResponse
    {
        $creature = $request->user()->creatures()->create($request->toAttributes());

        return to_route('compendium.index', ['creature' => $creature->id]);
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

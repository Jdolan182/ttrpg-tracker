<?php

namespace App\Http\Controllers;

use App\Events\CampaignCombatChanged;
use App\Models\Campaign;
use App\Support\EncounterPayload;
use App\Support\PlayerView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The fight players see. The DM's tracker sends it here as it changes; players (and the DM's own
 * second screen) read it back filtered through PlayerView.
 */
class CampaignCombatController extends Controller
{
    /**
     * Full-screen player view (e.g. for a TV), or just the data when asked for JSON, which is how
     * viewers refresh after a broadcast ping.
     */
    public function show(Request $request, Campaign $campaign): Response|JsonResponse
    {
        Gate::authorize('view', $campaign);

        if ($request->wantsJson()) {
            return response()->json(['combat' => $campaign->playerView()]);
        }

        return Inertia::render('Campaigns/Combat', [
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name, 'isDm' => $campaign->isRunBy($request->user())],
            'combat' => $campaign->playerView(),
        ]);
    }

    /**
     * The tracker's current fight. Called a lot (debounced on every change), so it only pings
     * viewers when what they'd see actually changed.
     */
    public function update(Request $request, Campaign $campaign): HttpResponse
    {
        Gate::authorize('update', $campaign);

        $rules = collect(EncounterPayload::rules())
            ->filter(fn ($rule, string $key) => in_array(explode('.', $key)[0], ['name', 'round', 'activeIndex', 'combatants', 'log'], true))
            ->all();
        // Only the latest part of the history is for players, so that's all the tracker sends.
        $rules['log'] = ['nullable', 'array', 'max:'.PlayerView::HISTORY_LIMIT];
        $data = $request->validate($rules);

        if ($problems = EncounterPayload::problems($data, $request->user())) {
            throw ValidationException::withMessages($problems);
        }

        $before = $campaign->playerView();

        $attributes = EncounterPayload::toAttributes($data, $request->user());
        $campaign->forceFill([
            // Setup isn't shown to players, so a fight back at round 0 is the same as no fight.
            'live' => $data['round'] < 1 ? null : [
                'name' => $data['name'],
                'round' => $data['round'],
                'activeIndex' => $data['activeIndex'],
                'combatants' => $attributes['combatants'],
                'log' => $attributes['log'],
            ],
            'live_updated_at' => now(),
        ])->save();

        $this->pingIfChanged($campaign, $before);

        return response()->noContent();
    }

    /** End combat: players stop seeing the fight. */
    public function destroy(Request $request, Campaign $campaign): HttpResponse|\Illuminate\Http\RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $before = $campaign->playerView();
        $campaign->forceFill(['live' => null, 'live_updated_at' => now()])->save();
        $this->pingIfChanged($campaign, $before);

        return $request->wantsJson() ? response()->noContent() : back();
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function pingIfChanged(Campaign $campaign, ?array $before): void
    {
        $after = $campaign->playerView();
        // The timestamp always moves, so leave it out of the comparison.
        unset($before['updatedAt'], $after['updatedAt']);

        if ($before !== $after) {
            CampaignCombatChanged::for($campaign);
        }
    }
}

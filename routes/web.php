<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\CampaignCombatController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignEncounterController;
use App\Http\Controllers\CampaignPartyController;
use App\Http\Controllers\CampaignPlayerController;
use App\Http\Controllers\CreatureController;
use App\Http\Controllers\EncounterController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Guests can run encounters and browse the compendium; saving anything requires an account.
Route::get('/', [EncounterController::class, 'index'])->name('encounters.index');
Route::get('compendium', [CreatureController::class, 'index'])->name('compendium.index');

// What's kept about you and why, in plain words. Mentions error tracking only when it's switched on.
Route::get('privacy', fn () => Inertia::render('Privacy', [
    'contactEmail' => config('app.contact_email'),
    'errorTracking' => (bool) config('sentry.dsn'),
]))->name('privacy');

// Invite links work for guests too: the page asks them to log in or sign up, then brings them back.
Route::get('join/{token}', [CampaignPlayerController::class, 'show'])->name('campaigns.join');

// Player view in a second window on the same computer, fed by the tracker through browser storage,
// so it works for guests and for fights outside a campaign.
Route::inertia('player-view', 'PlayerView')->name('player-view');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('encounters', [EncounterController::class, 'list'])->name('encounters.list');
    Route::post('encounters', [EncounterController::class, 'store'])->name('encounters.store');
    Route::get('encounters/{encounter}/export', [BackupController::class, 'exportEncounter'])->name('encounters.export');

    Route::get('backup', [BackupController::class, 'export'])->name('backup.export');
    Route::post('backup', [BackupController::class, 'import'])->middleware('throttle:10,1')->name('backup.import');
    Route::put('encounters/{encounter}', [EncounterController::class, 'update'])->name('encounters.update');
    Route::delete('encounters/{encounter}', [EncounterController::class, 'destroy'])->name('encounters.destroy');

    Route::get('compendium/create', [CreatureController::class, 'create'])->name('creatures.create');
    Route::post('compendium', [CreatureController::class, 'store'])->name('creatures.store');
    Route::get('compendium/{creature}/edit', [CreatureController::class, 'edit'])->name('creatures.edit');
    Route::put('compendium/{creature}', [CreatureController::class, 'update'])->name('creatures.update');
    Route::delete('compendium/{creature}', [CreatureController::class, 'destroy'])->name('creatures.destroy');

    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    Route::post('campaigns/{campaign}/invite', [CampaignController::class, 'resetInvite'])->name('campaigns.invite.reset');

    Route::post('campaigns/{campaign}/party', [CampaignPartyController::class, 'store'])->name('campaigns.party.store');
    Route::delete('campaigns/{campaign}/party/{creature}', [CampaignPartyController::class, 'destroy'])->name('campaigns.party.destroy');

    Route::post('campaigns/{campaign}/encounters', [CampaignEncounterController::class, 'store'])->name('campaigns.encounters.store');
    Route::delete('campaigns/{campaign}/encounters/{encounter}', [CampaignEncounterController::class, 'destroy'])->name('campaigns.encounters.destroy');

    Route::get('campaigns/{campaign}/combat', [CampaignCombatController::class, 'show'])->name('campaigns.combat');
    // The tracker sends every change (debounced), so this allows plenty.
    Route::put('campaigns/{campaign}/combat', [CampaignCombatController::class, 'update'])->middleware('throttle:300,1')->name('campaigns.combat.update');
    Route::delete('campaigns/{campaign}/combat', [CampaignCombatController::class, 'destroy'])->name('campaigns.combat.destroy');

    Route::post('join/{token}',[CampaignPlayerController::class, 'store'])->name('campaigns.join.store');
    Route::put('campaigns/{campaign}/character', [CampaignPlayerController::class, 'claim'])->name('campaigns.character.claim');
    Route::delete('campaigns/{campaign}/players/{player}', [CampaignPlayerController::class, 'destroy'])->name('campaigns.players.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

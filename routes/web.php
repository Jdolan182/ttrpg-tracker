<?php

use App\Http\Controllers\CreatureController;
use App\Http\Controllers\EncounterController;
use Illuminate\Support\Facades\Route;

// Guests can run encounters and browse the compendium; saving anything requires an account.
Route::get('/', [EncounterController::class, 'index'])->name('encounters.index');
Route::get('compendium', [CreatureController::class, 'index'])->name('compendium.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('encounters', [EncounterController::class, 'store'])->name('encounters.store');
    Route::put('encounters/{encounter}', [EncounterController::class, 'update'])->name('encounters.update');
    Route::delete('encounters/{encounter}', [EncounterController::class, 'destroy'])->name('encounters.destroy');

    Route::get('compendium/create', [CreatureController::class, 'create'])->name('creatures.create');
    Route::post('compendium', [CreatureController::class, 'store'])->name('creatures.store');
    Route::get('compendium/{creature}/edit', [CreatureController::class, 'edit'])->name('creatures.edit');
    Route::put('compendium/{creature}', [CreatureController::class, 'update'])->name('creatures.update');
    Route::delete('compendium/{creature}', [CreatureController::class, 'destroy'])->name('creatures.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

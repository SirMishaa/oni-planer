<?php

declare(strict_types=1);

use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CodexController;
use App\Http\Controllers\ColonyController;
use App\Http\Controllers\CritterController;
use App\Http\Controllers\ElementController;
use App\Http\Controllers\RanchController;
use App\Http\Controllers\ZoneController;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/appspecific/com.chrome.devtools.json', function () {
    if (app()->environment('local')) {
        return redirect()->away('http://localhost:5173/.well-known/appspecific/com.chrome.devtools.json', 307);
    }

    abort(404);
})->name('devtools');

Route::get('/', [ElementController::class, 'index'])->name('elements.index');
Route::get('/elements/{element:element_id}', [ElementController::class, 'show'])->name('elements.show');

Route::get('/buildings', [BuildingController::class, 'index'])->name('buildings.index');
Route::get('/buildings/{building:building_id}', [BuildingController::class, 'show'])->name('buildings.show');

Route::get('/codex', [CodexController::class, 'index'])->name('codex.index');
Route::get('/codex/{codexEntry:codex_id}', [CodexController::class, 'show'])->name('codex.show');

Route::get('/critters', [CritterController::class, 'index'])->name('critters.index');
Route::get('/critters/{family}', [CritterController::class, 'show'])->name('critters.show');
Route::get('/ranch', [RanchController::class, 'index'])->name('ranch.index');

Route::get('/colony/viability', [ColonyController::class, 'viability'])->name('colony.viability');
Route::get('/colony/uplink', [ColonyController::class, 'uplink'])->name('colony.uplink');

Route::get('/zones', [ZoneController::class, 'index'])->name('zones.index');
Route::get('/zones/{zone}', [ZoneController::class, 'show'])->name('zones.show');

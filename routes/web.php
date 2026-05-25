<?php

declare(strict_types=1);

use App\Http\Controllers\ElementController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ElementController::class, 'index'])->name('elements.index');
Route::get('/elements/{element}', [ElementController::class, 'show'])->name('elements.show');

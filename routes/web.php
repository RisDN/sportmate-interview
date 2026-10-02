<?php

use App\Http\Controllers\GitSourceController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Home')->name('home');

Route::get('/api/git-sources', [GitSourceController::class, 'index'])->name('git-sources.index');
Route::post('/api/git-sources', [GitSourceController::class, 'store'])
    ->middleware(HandlePrecognitiveRequests::class)
    ->name('git-sources.store');

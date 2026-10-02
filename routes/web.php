<?php

use App\Http\Controllers\GitSourceController;
use App\Http\Controllers\GitSourceSyncController;
use App\Http\Controllers\RemoteRepositoryController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Home')->name('home');

Route::get('/api/git-sources', [GitSourceController::class, 'index'])->name('git-sources.index');
Route::post('/api/git-sources', [GitSourceController::class, 'store'])
    ->middleware(HandlePrecognitiveRequests::class)
    ->name('git-sources.store');

Route::get('/api/git-sources/{gitSource}', [GitSourceController::class, 'show'])->name('git-sources.show');
Route::delete('/api/git-sources/{gitSource}', [GitSourceController::class, 'destroy'])->name('git-sources.destroy');
Route::post('/api/git-sources/{gitSource}/sync', [GitSourceSyncController::class, 'store'])->name('git-sources.sync');
Route::get('/api/git-sources/{gitSource}/repositories', [RemoteRepositoryController::class, 'index'])->name('git-sources.repositories.index');

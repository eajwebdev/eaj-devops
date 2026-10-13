<?php

use App\Http\Controllers\Api\GithubWebhookController;
use App\Http\Controllers\Api\Runner\RunnerApiController;
use App\Http\Middleware\AuthenticateRunner;
use Illuminate\Support\Facades\Route;

/*
| EAJ Local Runner API (Plan §89.2). Token-authenticated, no session/CSRF.
| The runner always connects outbound; the Studio never calls a developer PC.
*/

Route::prefix('runner/v1')->name('runner.')->group(function () {
    // Pairing codes are short-lived and single-use; throttle guessing anyway.
    Route::post('pair', [RunnerApiController::class, 'pair'])->middleware('throttle:10,1')->name('pair');

    Route::middleware([AuthenticateRunner::class, 'throttle:600,1'])->group(function () {
        Route::post('heartbeat', [RunnerApiController::class, 'heartbeat'])->name('heartbeat');
        Route::post('commands/claim', [RunnerApiController::class, 'claim'])->name('commands.claim');
        Route::post('commands/{command}/progress', [RunnerApiController::class, 'progress'])->name('commands.progress');
        Route::post('commands/{command}/index-chunk', [RunnerApiController::class, 'indexChunk'])->name('commands.index-chunk');
        Route::post('commands/{command}/complete', [RunnerApiController::class, 'complete'])->name('commands.complete');
    });
});

Route::post('github/webhook', GithubWebhookController::class)->middleware('throttle:300,1')->name('github.webhook');

<?php

use Illuminate\Support\Facades\Route;
use Plugin\RewardClaim\Controllers\RewardClaimController;

// PluginManager loads plugin API routes from the application root, unlike the
// built-in RouteServiceProvider API groups. Keep the full API prefix here.
Route::prefix('api/v1/reward-claim')
    ->middleware('user')
    ->group(function () {
        Route::get('/status', [RewardClaimController::class, 'status']);
        Route::post('/claim', [RewardClaimController::class, 'claim']);
    });

<?php

use Illuminate\Support\Facades\Route;
use Plugin\InviteTrafficReward\Controllers\InviteTrafficRewardController;

Route::prefix('api/v1/invite-traffic-reward')
    ->middleware('user')
    ->group(function () {
        Route::get('/status', [InviteTrafficRewardController::class, 'status']);
        Route::post('/claim', [InviteTrafficRewardController::class, 'claim']);
    });

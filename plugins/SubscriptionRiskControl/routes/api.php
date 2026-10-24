<?php

use Illuminate\Support\Facades\Route;
use Plugin\SubscriptionRiskControl\Controllers\SubscriptionRiskController;

$adminPath = trim((string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), '/');

Route::prefix('api/v2/' . $adminPath . '/subscription-risk-control')
    ->middleware('admin')
    ->group(function () {
        Route::get('/summary', [SubscriptionRiskController::class, 'summary']);
        Route::get('/watchlist', [SubscriptionRiskController::class, 'watchlist']);
        Route::get('/banlist', [SubscriptionRiskController::class, 'banlist']);
        Route::get('/pulls', [SubscriptionRiskController::class, 'pulls']);
        Route::get('/pull-logs', [SubscriptionRiskController::class, 'pullLogs']);
        Route::get('/events', [SubscriptionRiskController::class, 'events']);
        Route::post('/release', [SubscriptionRiskController::class, 'release']);
        Route::post('/ban', [SubscriptionRiskController::class, 'ban']);
        Route::post('/unban', [SubscriptionRiskController::class, 'unban']);
    });

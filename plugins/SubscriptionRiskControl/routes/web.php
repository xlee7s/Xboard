<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

$adminPath = trim((string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), '/');

Route::get('/' . $adminPath . '/subscription-risk-control', function (Request $request) use ($adminPath) {
    return view('SubscriptionRiskControl::dashboard', [
        'title' => (string) admin_setting('app_name', 'XBoard') . ' · 订阅封控',
        'admin_path' => $adminPath,
        'embedded' => $request->boolean('embedded'),
    ]);
});

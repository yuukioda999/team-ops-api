<?php

use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Teams\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// ログイン(ADR-0008)。リンクの要求は回数を制限する(AC-001-05)
Route::post('/auth/login-links', [LoginLinkController::class, 'store'])->middleware('throttle:login-link');
Route::post('/auth/login-links/consume', [LoginLinkController::class, 'consume'])->middleware('throttle:token-consume');
Route::post('/me/email-changes/consume', [EmailChangeController::class, 'consume'])->middleware('throttle:token-consume');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [SessionController::class, 'destroy']);
    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me', [MeController::class, 'update']);
    Route::post('/me/email-changes', [EmailChangeController::class, 'store'])->middleware('throttle:email-change');

    Route::post('/teams', [TeamController::class, 'store']);

    // チームのデータはすべてこの下に置く。{team} は所属しているチームからだけ探す(ADR-0009)。
    // 新しいルートをこの外に置くと、tests/Tenancy のルートの検査が落ちる
    Route::prefix('/teams/{team}')->group(function () {
        Route::get('/', [TeamController::class, 'show']);
    });
});

<?php

use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Invitations\InvitationController as JoinController;
use App\Http\Controllers\Teams\InvitationController;
use App\Http\Controllers\Teams\JoinRequestController;
use App\Http\Controllers\Teams\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// ログイン(ADR-0008)。リンクの要求は回数を制限する(AC-001-05)
Route::post('/auth/login-links', [LoginLinkController::class, 'store'])->middleware('throttle:login-link');
Route::post('/auth/login-links/consume', [LoginLinkController::class, 'consume'])->middleware('throttle:token-consume');

// 招待リンクのチーム名(ログイン不要、AC-002-05・07)。チームの外に置き、チーム名と競技だけを返す
Route::post('/invitations/preview', [JoinController::class, 'preview'])->middleware('throttle:invitation-preview');

Route::middleware('auth:sanctum')->group(function () {
    // 招待リンクからの参加申請(AC-002-06〜10)。申請者はまだメンバーではないので、チームの外に置く
    Route::post('/invitations/applications', [JoinController::class, 'apply'])->middleware('throttle:join-request');

    Route::post('/auth/logout', [SessionController::class, 'destroy']);
    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me', [MeController::class, 'update']);
    Route::post('/me/email-changes', [EmailChangeController::class, 'store'])->middleware('throttle:email-change');
    Route::post('/me/email-changes/consume', [EmailChangeController::class, 'consume'])->middleware('throttle:token-consume');

    Route::post('/teams', [TeamController::class, 'store']);

    // チームのデータはすべてこの下に置く。{team} は所属しているチームからだけ探す(ADR-0009)。
    // 新しいルートをこの外に置くと、tests/Tenancy のルートの検査が落ちる
    Route::prefix('/teams/{team}')->group(function () {
        Route::get('/', [TeamController::class, 'show']);

        // 招待リンクと参加申請の承認(002)。権限は TeamPolicy(ADR-0011)
        Route::get('/invitation', [InvitationController::class, 'show']);
        Route::post('/invitation', [InvitationController::class, 'store']);
        Route::delete('/invitation', [InvitationController::class, 'destroy']);
        Route::get('/join-requests', [JoinRequestController::class, 'index']);
        Route::post('/join-requests/{joinRequest}/approve', [JoinRequestController::class, 'approve'])->whereNumber('joinRequest');
        Route::post('/join-requests/{joinRequest}/reject', [JoinRequestController::class, 'reject'])->whereNumber('joinRequest');
    });
});

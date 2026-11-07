<?php

use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Invitations\InvitationController as JoinController;
use App\Http\Controllers\Teams\InvitationController;
use App\Http\Controllers\Teams\JoinRequestController;
use App\Http\Controllers\Teams\PlayerController;
use App\Http\Controllers\Teams\PlayerImportController;
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
        // ID は PHP の整数に収まる18桁まで。それより長いものは、存在しない申請と同じ404にする(AC-000-01)
        Route::post('/join-requests/{joinRequest}/approve', [JoinRequestController::class, 'approve'])->where('joinRequest', '[0-9]{1,18}');
        Route::post('/join-requests/{joinRequest}/reject', [JoinRequestController::class, 'reject'])->where('joinRequest', '[0-9]{1,18}');

        // 名簿(003)。閲覧の範囲と権限は TeamPolicy と RosterScope(ADR-0013)
        Route::get('/players', [PlayerController::class, 'index']);
        Route::post('/players', [PlayerController::class, 'store']);
        Route::get('/players/import/template', [PlayerImportController::class, 'template']);
        Route::post('/players/import/preview', [PlayerImportController::class, 'preview'])->middleware('throttle:player-import');
        Route::post('/players/import', [PlayerImportController::class, 'store'])->middleware('throttle:player-import')->name('players.import');
        Route::get('/players/{player}', [PlayerController::class, 'show'])->where('player', '[0-9]{1,18}');
        Route::patch('/players/{player}', [PlayerController::class, 'update'])->where('player', '[0-9]{1,18}');
        Route::delete('/players/{player}', [PlayerController::class, 'destroy'])->where('player', '[0-9]{1,18}');
        Route::put('/players/{player}/guardians', [PlayerController::class, 'guardians'])->where('player', '[0-9]{1,18}');
        Route::get('/guardian-candidates', [PlayerController::class, 'candidates']);
    });
});

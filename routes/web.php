<?php

use Illuminate\Support\Facades\Route;

// This application is API-only. The web UI lives in team-ops-web.
Route::get('/', fn () => response()->json(['name' => 'team-ops-api']));

<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Resources\Demo\DemoMailResource;
use App\Models\Team;
use App\UseCases\Demo\ListDemoMails;
use Illuminate\Http\JsonResponse;

/**
 * デモチームから送られたメール(AC-007-12)。チームのメンバーなら誰でも見られる(デモのデータだけ)。
 */
final class DemoMailController extends Controller
{
    public function index(Team $team, ListDemoMails $list): JsonResponse
    {
        return new JsonResponse(['mails' => array_map(fn (array $mail) => (new DemoMailResource($mail))->resolve(), $list($team))]);
    }
}

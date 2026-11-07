<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notices\NoticeRequest;
use App\Http\Requests\Notices\RecipientCountRequest;
use App\Http\Resources\NoticeResource;
use App\Models\Team;
use App\Models\User;
use App\UseCases\Notices\CancelScheduledNotice;
use App\UseCases\Notices\ListMyNotices;
use App\UseCases\Notices\ListScheduledNotices;
use App\UseCases\Notices\ResolveRecipients;
use App\UseCases\Notices\SendNotice;
use App\UseCases\Notices\ShowNotice;
use App\UseCases\Notices\UpdateScheduledNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * お知らせ(005)。作成は代表・役員・コーチ、閲覧は自分が宛先のものだけ(ADR-0017)。
 */
final class NoticeController extends Controller
{
    public function index(Request $request, Team $team, ListMyNotices $list): JsonResponse
    {
        Gate::authorize('viewNotices', $team);

        return new JsonResponse(['notices' => NoticeResource::collection($list($this->actor($request), $team))->resolve()]);
    }

    public function show(Request $request, Team $team, int $notice, ShowNotice $show): JsonResponse
    {
        Gate::authorize('viewNotices', $team);

        return new JsonResponse(['notice' => (new NoticeResource($show($this->actor($request), $team, $notice)))->resolve()]);
    }

    public function count(RecipientCountRequest $request, Team $team, ResolveRecipients $resolve): JsonResponse
    {
        return new JsonResponse(['count' => count($resolve($team, $request->audience(), $this->actor($request)->id))]);
    }

    public function store(NoticeRequest $request, Team $team, SendNotice $send): JsonResponse
    {
        $notice = $send($this->actor($request), $team, $request->notice());

        return new JsonResponse(['notice' => (new NoticeResource($notice->load('author')))->resolve()], 201);
    }

    public function scheduled(Request $request, Team $team, ListScheduledNotices $list): JsonResponse
    {
        Gate::authorize('createNotices', $team);

        return new JsonResponse(['notices' => NoticeResource::collection($list($this->actor($request), $team))->resolve()]);
    }

    public function update(NoticeRequest $request, Team $team, int $notice, UpdateScheduledNotice $update): JsonResponse
    {
        $updated = $update($this->actor($request), $team, $notice, $request->changes());

        return new JsonResponse(['notice' => (new NoticeResource($updated->load('author')))->resolve()]);
    }

    public function destroy(Request $request, Team $team, int $notice, CancelScheduledNotice $cancel): Response
    {
        Gate::authorize('createNotices', $team);
        $cancel($this->actor($request), $team, $notice);

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

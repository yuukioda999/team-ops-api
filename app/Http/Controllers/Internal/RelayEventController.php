<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\RelayEventRequest;
use App\UseCases\Deliveries\ReceiveRelayEvent;
use Illuminate\Http\Response;

/**
 * notify-relay からの webhook の受け口(ADR-0019)。署名は VerifyRelaySignature で確かめる。
 * 同じイベントが2回届いても 204 を返す。
 */
final class RelayEventController extends Controller
{
    public function store(RelayEventRequest $request, ReceiveRelayEvent $receive): Response
    {
        $receive($request->event());

        return response()->noContent();
    }
}

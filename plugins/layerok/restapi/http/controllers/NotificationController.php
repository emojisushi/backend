<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\FcmToken;
use October\Rain\Exception\ValidationException;
use OFFLINE\Mall\Models\WishlistItem;
use Validator;

class NotificationController extends Controller
{
    public function register(): JsonResponse
    {
        $v = Validator::make(input('params', []), [
            'fcm_token' => 'required|string',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        FcmToken::updateOrCreate(
            ['fcm_token' => input('params.fcm_token')],
            ['platform' => input('params.platform')]
        );

        return response()->json(['success' => true]);
    }
}

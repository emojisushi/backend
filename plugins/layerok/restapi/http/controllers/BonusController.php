<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\PendingBonus;
use Layerok\PosterPos\Models\User;
use Layerok\RestApi\Models\Settings;

class BonusController extends Controller
{
    public function fetch(): JsonResponse
    {

        $secret = input('secret');
        $order_id = input('order_id');

        if (!isset($secret)) {
            return response()->json(null, 403);
        }
        if ($secret !== env('BONUSES_SECRET')) {
            return response()->json(null, 403);
        }

        $order = PendingBonus::where('order_id', $order_id)->first();
        if (!$order || $order->pending == false) {
            return response()->json(null, 404);
        }
        $to_use = $order->use_bonus_amount;
        $to_receive = $order->receive_bonus_amount;

        $order->pending = false;
        $user = User::where('id', $order->user_id)->first();
        $user->bonus_amount += $to_receive;
        $order->save();
        $user->save();
        return response()->json(['to_use' => $to_use, 'to_receive' => $to_receive]);
    }
    public function history(): JsonResponse
    {
        $jwtGuard = app('JWTGuard');
        /** @var User $user */
        $user = $jwtGuard->user();
        $userId = $user->id;
        $bonuses = PendingBonus::where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($bonus) { //Пока заказ не подтвержден не показываем в истории что бонусы были получены
                if ($bonus->pending) {
                    $bonus->receive_bonus_amount = 0;
                }
                return $bonus;
            });
        // $bonuses = PendingBonus::where('user_id', $userId)
        //     ->where('pending', false)
        //     ->orderBy('updated_at', 'desc')
        //     ->get();

        return response()->json($bonuses);
    }
    public function options(): JsonResponse
    {
        $enabled = (bool) Settings::get('bonus_enabled');
        $rate = Settings::get('bonus_rate') / 100;
        $max = Settings::get('max_bonus') / 100;
        $bonus = (bool) Settings::get('get_bonus_from_used_bonus');

        return response()->json(['bonus_enabled' => $enabled, 'bonus_rate' => $rate, 'max_bonus' => $max, 'get_bonus_from_used_bonus' => $bonus]);
    }
}
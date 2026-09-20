<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RainLab\User\Models\User;
use Layerok\RestApi\Models\BonusTransaction;
use Layerok\RestApi\Models\Settings;
use Layerok\Restapi\Services\BonusService;

class BonusController extends Controller
{
    /**
     * Read for the register app: how much of this online order is paid by points.
     *
     * Only answers for orders whose points actually left the client's Poster
     * balance, so a failed write-off never turns into a discount on the bill.
     * Purely a read, so the register may retry it freely.
     */
    public function fetch(): JsonResponse
    {
        $secret = input('secret');
        $order_id = input('order_id');

        if (!isset($secret) || $secret !== env('BONUSES_SECRET')) {
            return response()->json(null, 403);
        }

        $row = BonusTransaction::where('incoming_order_id', $order_id)
            ->whereIn('status', BonusTransaction::SPENT_STATUSES)
            ->first();

        if (!$row) {
            return response()->json(null, 404);
        }

        return response()->json(['to_use' => $row->amount]);
    }

    /**
     * The client's balance. Points are written off at placement, so the number
     * Poster reports already excludes orders in flight.
     */
    public function balance(): JsonResponse
    {
        /** @var User $user */
        $user = app('JWTGuard')->user();
        $service = new BonusService();

        if (!$service->enabled()) {
            return response()->json([
                'enabled' => false,
                'balance' => 0,
                'reserved' => 0,
                'available' => 0,
            ]);
        }

        $balance = $service->balanceFor($user);

        return response()->json([
            'enabled' => true,
            'balance' => $balance,
            // Kept for the clients that still read them; nothing is held back now.
            'reserved' => 0,
            'available' => $balance,
        ]);
    }

    /**
     * Movements caused by this client's online orders, newest first.
     * Rows where nothing left the balance (pending, failed) are not shown.
     */
    public function history(): JsonResponse
    {
        /** @var User $user */
        $user = app('JWTGuard')->user();

        $visible = array_merge(
            BonusTransaction::SPENT_STATUSES,
            [BonusTransaction::STATUS_REFUNDED, BonusTransaction::STATUS_EXTERNAL]
        );

        $rows = BonusTransaction::forUser($user->id)
            ->whereIn('status', $visible)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn(BonusTransaction $row) => [
                'id' => $row->id,
                'date' => optional($row->created_at)->toIso8601String(),
                'order_id' => $row->incoming_order_id ?? $row->online_order_id,
                'delta' => $row->delta ?? (-1 * $row->amount),
                'balance_after' => $row->balance_after,
                'status' => $row->status,
                'refunded' => $row->status === BonusTransaction::STATUS_REFUNDED,
            ]);

        return response()->json($rows);
    }

    /**
     * Public bonus configuration. The frontend computes the spendable cap itself,
     * so it needs both the percentage and the categories that do not count towards
     * it — the backend re-checks the same rule when the order is placed.
     */
    public function options(): JsonResponse
    {
        $service = new BonusService();

        return response()->json([
            'bonus_enabled' => $service->enabled(),
            'max_bonus' => (int) Settings::get('max_bonus'),
            'excluded_category_ids' => $service->excludedCategoryIds()->all(),
        ]);
    }
}

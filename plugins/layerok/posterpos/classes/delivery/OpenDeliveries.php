<?php

namespace Layerok\PosterPos\Classes\Delivery;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Layerok\PosterPos\Models\Courier;
use poster\src\PosterApi;

/**
 * Delivery receipts and online orders as Poster reports them, shared by the
 * delivery map and the operator overview.
 */
class OpenDeliveries
{
    /**
     * Poster returns local wall-clock times with no zone attached, while the app
     * runs in UTC. Every comparison with "now" has to happen in this zone.
     */
    public const TIMEZONE = 'Europe/Kyiv';

    public const SERVICE_MODE_DELIVERY = 3;

    // dash.getTransactions status filter
    public const STATUS_OPEN = 1;
    public const STATUS_CLOSED = 2;

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /**
     * Open delivery receipts between two dates (Y-m-d, inclusive). Without a spot
     * every spot of the account is returned, each receipt carrying its spot_id.
     */
    public function openTransactions(string $dateFrom, string $dateTo, ?int $spotPosterId = null): Collection
    {
        return $this->deliveryTransactions(self::STATUS_OPEN, $dateFrom, $dateTo, $spotPosterId);
    }

    /**
     * Closed delivery receipts. Poster filters closed receipts by their local
     * close date, so a receipt opened at 23:50 and closed at 00:20 belongs to the
     * next day.
     */
    public function closedTransactions(string $dateFrom, string $dateTo, ?int $spotPosterId = null): Collection
    {
        return $this->deliveryTransactions(self::STATUS_CLOSED, $dateFrom, $dateTo, $spotPosterId);
    }

    private function deliveryTransactions(int $status, string $dateFrom, string $dateTo, ?int $spotPosterId): Collection
    {
        $params = [
            'dateFrom' => date('Ymd', strtotime($dateFrom)),
            'dateTo' => date('Ymd', strtotime($dateTo)),
            'status' => $status,
            'service_mode' => self::SERVICE_MODE_DELIVERY,
            'include_delivery' => 'true',
        ];

        if ($spotPosterId) {
            $params['type'] = 'spots';
            $params['id'] = $spotPosterId;
        }

        return collect($this->request(fn() => PosterApi::dash()->getTransactions($params)) ?: [])
            ->map(fn($transaction) => (object) $transaction)
            // Poster applies service_mode itself; checked again here so a
            // takeaway or dine-in receipt can never count as a delivery.
            ->filter(fn($transaction) => !isset($transaction->service_mode)
                || (int) $transaction->service_mode === self::SERVICE_MODE_DELIVERY)
            ->values();
    }

    /**
     * Online delivery orders the register has not accepted yet. They are not
     * transactions, so dash.getTransactions cannot see them at all.
     */
    public function unacceptedOrders(string $dateFrom, string $dateTo, ?int $spotPosterId = null): Collection
    {
        $rows = collect($this->request(fn() => PosterApi::incomingOrders()->getIncomingOrders([
            'status' => 0,                 // new, not accepted
            'date_from' => $dateFrom . ' 00:00:00',
            'date_to' => $dateTo . ' 23:59:59',
        ])) ?: []);

        return $rows
            ->map(fn($order) => (object) $order)
            ->filter(function ($order) use ($spotPosterId) {
                if ((int) ($order->service_mode ?? 0) !== self::SERVICE_MODE_DELIVERY) {
                    return false;
                }

                return !$spotPosterId || (int) ($order->spot_id ?? 0) === $spotPosterId;
            })
            ->values();
    }

    /**
     * One receipt with its delivery record, or null if Poster no longer has it.
     */
    public function transaction(int $transactionId): ?object
    {
        $row = collect($this->request(fn() => PosterApi::dash()->getTransaction([
            'transaction_id' => $transactionId,
            'include_delivery' => 'true',
        ])) ?: [])->first();

        return $row ? (object) $row : null;
    }

    public function incomingOrder(int $incomingOrderId): ?object
    {
        $row = $this->request(fn() => PosterApi::incomingOrders()->getIncomingOrder([
            'incoming_order_id' => $incomingOrderId,
        ]));

        return $row ? (object) $row : null;
    }

    /**
     * Couriers come from our own registry rather than Poster, because Poster has
     * no way to say which employees are couriers. An id with no registry entry is
     * shown as a bare id, which is the signal that a mapping is missing.
     */
    public function courierNames(): array
    {
        return Courier::whereNotNull('poster_user_id')
            ->get(['poster_user_id', 'name'])
            ->mapWithKeys(fn(Courier $courier) => [(int) $courier->poster_user_id => $courier->name])
            ->all();
    }

    public static function courierLabel(?int $courierId, array $names): ?string
    {
        return $courierId ? ($names[$courierId] ?? ('ID ' . $courierId)) : null;
    }

    public static function courierIdOf(object $transaction): ?int
    {
        $delivery = (object) ($transaction->delivery ?? []);

        return !empty($delivery->courier_id) ? (int) $delivery->courier_id : null;
    }

    /**
     * The promised delivery time, in the Poster account's zone.
     */
    public static function dueAt(object $transaction): ?Carbon
    {
        $delivery = (object) ($transaction->delivery ?? []);

        return self::localTime($delivery->delivery_time ?? null);
    }

    /**
     * A receipt time from dash.getTransactions, in the account's zone. date_start
     * and date_close come as Unix time in milliseconds, the *_date fields as local
     * "Y-m-d H:i:s"; both are accepted. Null for an empty or zero value.
     */
    public static function posterTime($value): ?Carbon
    {
        if (is_numeric($value)) {
            $number = (int) $value;

            if ($number <= 0) {
                return null;
            }

            $time = $number > 9999999999
                ? Carbon::createFromTimestampMs($number)
                : Carbon::createFromTimestamp($number);

            return $time->setTimezone(self::TIMEZONE);
        }

        return self::localTime($value);
    }

    public static function localTime($value): ?Carbon
    {
        if (empty($value) || str_starts_with((string) $value, '0000')) {
            return null;
        }

        return Carbon::parse($value, self::TIMEZONE);
    }

    public static function addressOf(object $transaction): string
    {
        $delivery = (object) ($transaction->delivery ?? []);

        $address = collect([$delivery->address1 ?? null, $delivery->address2 ?? null])
            ->filter(fn($part) => trim((string) $part) !== '')
            ->join(', ');

        return $address ?: (string) ($delivery->comment ?? '');
    }

    public static function clientName($firstName, $lastName): string
    {
        return collect([$firstName, $lastName])
            ->filter(fn($part) => trim((string) $part) !== '')
            ->join(' ') ?: '—';
    }

    /**
     * Receipt sums come in kopecks.
     */
    public static function amountOf(object $transaction): int
    {
        return (int) round(((int) ($transaction->sum ?? 0)) / 100);
    }

    public function request(callable $request)
    {
        PosterApi::init(config('poster'));

        $result = (object) $request();

        if (isset($result->error)) {
            throw new \RuntimeException('Poster: ' . ($result->message ?? $result->error));
        }

        return $result->response ?? [];
    }
}

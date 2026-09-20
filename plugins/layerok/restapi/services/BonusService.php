<?php

namespace Layerok\Restapi\Services;

use Illuminate\Support\Facades\Log;
use RainLab\User\Models\User;
use Layerok\RestApi\Models\BonusTransaction;
use Layerok\RestApi\Models\Settings;
use poster\src\PosterApi;

/**
 * Bonus points live in Poster; this service is the only place that talks to them.
 *
 * Points are written off in Poster as soon as the order is placed, so the balance
 * Poster reports is already correct everywhere — in this app, at the register, and
 * on a second order. The ledger records each write-off so it can be given back if
 * the order is later rejected.
 *
 * All amounts are in minor units (kopecks), matching Poster's clients.bonus.
 */
class BonusService
{
    public function enabled(): bool
    {
        return (bool) Settings::get('bonus_enabled');
    }

    /**
     * Poster's client id for a user, resolved by phone on every call.
     * Poster accepts a range of phone formats, so the stored value is passed as is.
     */
    public function findClientId(?string $phone): ?int
    {
        if (empty($phone)) {
            return null;
        }

        $response = $this->call(
            fn() => PosterApi::clients()->getClients(['phone' => $phone]),
            'clients.getClients'
        );

        $client = collect($response)->first();
        $clientId = is_object($client) ? ($client->client_id ?? null) : ($client['client_id'] ?? null);

        return $clientId ? (int) $clientId : null;
    }

    /**
     * What the client may spend. Write-offs happen at placement, so Poster's
     * balance already excludes points committed to orders in flight.
     */
    public function balanceFor(User $user, ?int $clientId = null): int
    {
        $clientId = $clientId ?? $this->findClientId($user->phone);

        return $this->posterBalance($clientId);
    }

    /**
     * The whole client record, or null when Poster has no such client.
     */
    public function getClient(?int $clientId)
    {
        if (!$clientId) {
            return null;
        }

        $response = $this->call(
            fn() => PosterApi::clients()->getClient(['client_id' => $clientId]),
            'clients.getClient'
        );

        $client = collect($response)->first() ?? $response;

        return is_array($client) ? (object) $client : $client;
    }

    public function posterBalance(?int $clientId): int
    {
        return (int) (optional($this->getClient($clientId))->bonus ?? 0);
    }

    /**
     * Order value that bonuses may be measured against. Products whose category is
     * excluded in the settings do not count, so an order of one eligible item worth
     * 100 and one excluded item worth 100 allows 20% of 100, not of 200.
     */
    public function eligibleTotal($products, array $cart): int
    {
        $excluded = $this->excludedCategoryIds();

        return (int) collect($products)->reduce(function ($acc, $product) use ($cart, $excluded) {
            $item = collect($cart['items'])->first(fn($item) => $item['id'] === (string) $product->id);

            if (!$item) {
                return $acc;
            }

            if ($excluded->isNotEmpty() && $product->categories->pluck('id')->intersect($excluded)->isNotEmpty()) {
                return $acc;
            }

            return $acc + $product->prices[0]->price * $item['quantity'];
        }, 0);
    }

    /**
     * The configured share of the eligible total that bonuses may cover.
     */
    public function maxSpendable(int $eligibleTotal): int
    {
        $percent = (int) Settings::get('max_bonus');

        // Floor to a whole hryvnia: Poster only accepts whole units on write.
        return (int) floor($eligibleTotal * $percent / 100 / 100) * 100;
    }

    public function excludedCategoryIds()
    {
        return collect(Settings::get('bonus_excluded_categories') ?: [])
            ->map(fn($id) => (int) $id)
            ->filter()
            ->values();
    }

    /**
     * Records the intent to spend points, without touching Poster yet.
     *
     * Cash and card-on-delivery orders apply immediately; orders paid online sit
     * in `pending` until WayForPay confirms the payment, because until then there
     * is no order to spend against.
     */
    public function reserve(
        User $user,
        int $amount,
        ?int $onlineOrderId = null,
        ?int $incomingOrderId = null,
        ?int $clientId = null
    ): BonusTransaction {
        return BonusTransaction::create([
            'user_id' => $user->id,
            'poster_client_id' => $clientId ?? $this->findClientId($user->phone),
            'online_order_id' => $onlineOrderId,
            'incoming_order_id' => $incomingOrderId,
            'amount' => $amount,
            'status' => BonusTransaction::STATUS_PENDING,
        ]);
    }

    /**
     * Writes a pending row's points off in Poster.
     *
     * A row left in `failed` means nothing was taken, and the register is not told
     * to discount the bill.
     */
    public function applyRow(BonusTransaction $row, ?int $incomingOrderId = null): bool
    {
        if ($row->status !== BonusTransaction::STATUS_PENDING) {
            return false;
        }

        if ($incomingOrderId) {
            $row->incoming_order_id = $incomingOrderId;
            $row->save();
        }

        if (!$row->poster_client_id || $row->amount <= 0) {
            $this->markFailed($row, 'No Poster client for this phone');

            return false;
        }

        try {
            $response = $this->changeBonus($row->poster_client_id, -1 * $row->amount);
        } catch (\Throwable $e) {
            Log::error('Bonus write-off failed', [
                'bonus_transaction_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($row, $e->getMessage());

            return false;
        }

        $row->delta = -1 * $row->amount;
        $row->balance_after = is_numeric($response) ? (int) $response : null;
        $row->status = BonusTransaction::STATUS_APPLIED;
        $row->save();

        return true;
    }

    /**
     * Reserve and write off in one step, for orders that are real on placement.
     */
    public function apply(User $user, int $amount, int $incomingOrderId, ?int $clientId = null): BonusTransaction
    {
        $row = $this->reserve($user, $amount, null, $incomingOrderId, $clientId);
        $this->applyRow($row);

        return $row;
    }

    /**
     * The outstanding reservation for an online order, if any.
     */
    public function pendingForOnlineOrder(int $onlineOrderId): ?BonusTransaction
    {
        return BonusTransaction::where('online_order_id', $onlineOrderId)
            ->where('status', BonusTransaction::STATUS_PENDING)
            ->first();
    }

    /**
     * The write-off already made for an online order, if it has not been settled.
     */
    public function appliedForOnlineOrder(int $onlineOrderId): ?BonusTransaction
    {
        return BonusTransaction::where('online_order_id', $onlineOrderId)
            ->where('status', BonusTransaction::STATUS_APPLIED)
            ->first();
    }

    /**
     * Drops a reservation whose payment never completed. Nothing was taken from
     * Poster, so there is nothing to give back.
     */
    public function abandon(BonusTransaction $row, ?string $note = null): bool
    {
        if ($row->status !== BonusTransaction::STATUS_PENDING) {
            return false;
        }

        $this->markFailed($row, $note ?? 'Payment not completed');

        return true;
    }

    /**
     * Gives the points back when the order is rejected or removed.
     * Only an applied row can be refunded, so a retried webhook cannot pay twice.
     */
    public function refund(BonusTransaction $row, ?string $note = null): bool
    {
        if ($row->status !== BonusTransaction::STATUS_APPLIED) {
            return false;
        }

        if (!$row->poster_client_id || $row->amount <= 0) {
            return false;
        }

        $response = $this->changeBonus($row->poster_client_id, $row->amount);

        $row->balance_after = is_numeric($response) ? (int) $response : null;
        $row->status = BonusTransaction::STATUS_REFUNDED;
        $row->note = $note;
        $row->save();

        return true;
    }

    /**
     * Marks the write-off final once the bill is closed. Nothing is sent to
     * Poster — the points left the balance at placement.
     */
    public function settle(BonusTransaction $row): bool
    {
        if ($row->status !== BonusTransaction::STATUS_APPLIED) {
            return false;
        }

        $row->status = BonusTransaction::STATUS_SETTLED;
        $row->save();

        return true;
    }

    /**
     * Records a balance change Poster made on its own — points spent at the till,
     * an accrual when a bill closes, or a manual edit in the Poster dashboard.
     *
     * The client webhook reports that a client changed but not by how much, so the
     * delta is measured against the last balance we recorded for them. The first
     * time we see a client we have no baseline, so their whole balance is recorded
     * as one opening row and the user is resolved from the phone Poster holds.
     */
    public function recordExternalChange(int $clientId): ?BonusTransaction
    {
        $client = $this->getClient($clientId);

        if (!$client) {
            return null;
        }

        $current = (int) ($client->bonus ?? 0);

        $baseline = BonusTransaction::where('poster_client_id', $clientId)
            ->whereNotNull('balance_after')
            ->orderBy('id', 'desc')
            ->first();

        if (!$baseline) {
            return $this->recordOpeningBalance($client, $clientId, $current);
        }

        $delta = $current - (int) $baseline->balance_after;

        if ($delta === 0) {
            return null;
        }

        return BonusTransaction::create([
            'user_id' => $baseline->user_id,
            'poster_client_id' => $clientId,
            'amount' => abs($delta),
            'delta' => $delta,
            'balance_after' => $current,
            'status' => BonusTransaction::STATUS_EXTERNAL,
            'note' => $delta > 0 ? 'Accrued in Poster' : 'Spent outside the app',
        ]);
    }

    /**
     * First sighting of a client: the balance they already hold becomes the
     * opening row, so later webhooks have something to measure against.
     */
    private function recordOpeningBalance($client, int $clientId, int $current): ?BonusTransaction
    {
        if ($current === 0) {
            return null;
        }

        $user = $this->findUserByPhone($client->phone_number ?? $client->phone ?? null);

        if (!$user) {
            return null;
        }

        return BonusTransaction::create([
            'user_id' => $user->id,
            'poster_client_id' => $clientId,
            'amount' => $current,
            'delta' => $current,
            'balance_after' => $current,
            'status' => BonusTransaction::STATUS_EXTERNAL,
            'note' => 'Existing Poster balance',
        ]);
    }

    /**
     * Matches a Poster phone against our users. Poster formats numbers freely, so
     * the comparison is made on digits, falling back to the subscriber number.
     */
    private function findUserByPhone(?string $phone): ?User
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) < 9) {
            return null;
        }

        $tail = substr($digits, -9);

        return User::where('phone', $digits)
            ->orWhere('phone', '+' . $digits)
            ->orWhere('phone', 'like', '%' . $tail)
            ->first();
    }

    private function markFailed(BonusTransaction $row, string $note): BonusTransaction
    {
        $row->status = BonusTransaction::STATUS_FAILED;
        $row->note = $note;
        $row->save();

        return $row;
    }

    /**
     * Poster reports balances in minor units but takes writes in whole currency
     * units, so the kopeck amount the ledger holds is converted here. Spending is
     * capped to whole hryvnia (see maxSpendable), so the division is always exact.
     *
     * Returns the resulting balance converted back to minor units.
     */
    private function changeBonus(int $clientId, int $countInMinorUnits)
    {
        $response = $this->call(
            fn() => PosterApi::clients()->changeClientBonus([
                'client_id' => $clientId,
                'count' => intdiv($countInMinorUnits, 100),
                // We already record our own writes in the ledger; letting them come
                // back through the client webhook would count them twice.
                'block_webhook' => 'true',
            ]),
            'clients.changeClientBonus'
        );

        return is_numeric($response) ? (int) $response * 100 : null;
    }

    private function call(callable $request, string $label)
    {
        PosterApi::init(config('poster'));

        $result = (object) $request();

        if (isset($result->error)) {
            throw new \RuntimeException(
                'Poster ' . $label . ' failed: ' . ($result->message ?? $result->error)
            );
        }

        return $result->response ?? null;
    }
}

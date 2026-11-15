<?php

namespace Layerok\PosterPos\Classes\Promotions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Layerok\PosterPos\Models\PosterAccount;
use Layerok\PosterPos\Models\PosterPromotion;
use Layerok\PosterPos\Models\Spot;
use OFFLINE\Mall\Models\Product;

/**
 * Applies the enabled Poster promotions to an online order.
 *
 * The client never says which promotions it expects: the cart is checked here
 * against the promotions themselves, and only what qualifies is sent to Poster.
 */
class PosterPromotionService
{
    /**
     * Enabled promotions that can be applied right now at this spot.
     *
     * @return Collection<PosterPromotion>
     */
    public function runningFor(PosterAccount $account, ?Spot $spot, ?\DateTimeInterface $now = null): Collection
    {
        $now = $now ?? $this->now();

        return PosterPromotion::enabled()
            ->where('poster_account_id', $account->id)
            ->get()
            ->filter(fn(PosterPromotion $promotion) => $promotion->isImplemented()
                && $promotion->isRunningAt($now)
                && ($spot === null || $promotion->isAvailableAtSpot($spot)))
            ->values();
    }

    /**
     * Promotions the order qualifies for. Promotions do not combine, and a gift
     * comes first: a takeaway order with two pizzas gets the free pizza (2+1) and
     * not the 10% off. The discount (at most one can be enabled per order type, see
     * PosterPromotion::enableProblem) applies only when there is no gift — or when
     * $preferDiscount asks for it, if it applies; otherwise the gifts stay.
     *
     * @param array $lines [['product' => Product, 'quantity' => int, 'poster_id' => int, 'price' => int kopecks], ...]
     * @param string $orderType PosterPromotion::ORDER_TYPES_TAKEAWAY or ORDER_TYPES_COURIER
     * @param bool $preferDiscount the discount instead of the gift when both apply;
     *   only a call centre operator may choose it (OrderControllerV2)
     * @return array[] each with 'kind' => 'gift' | 'discount', 'promotion_id', 'poster' (the Poster
     *   `promotion` entry) and 'name'; a gift adds 'gift_name', 'gift_count', a discount
     *   adds 'percent', 'discount' (kopecks)
     */
    public function applyToOrder(PosterAccount $account, Spot $spot, array $lines, string $orderType, bool $preferDiscount = false): array
    {
        $applied = [];
        $promotions = $this->runningFor($account, $spot)
            ->filter(fn(PosterPromotion $promotion) => $promotion->appliesToOrderType($orderType));

        foreach ($promotions as $promotion) {
            $rule = $promotion->giftRule();

            if (!$rule) {
                continue;
            }

            // By the product's Poster category, as Poster itself counts it — our own
            // categories are ignored, a combo may well be under «Піца» here.
            $qualifyingIds = array_flip($promotion->qualifyingPosterProductIds());

            if (!$qualifyingIds) {
                continue;
            }

            $involved = [];
            $qualifying = 0;

            foreach ($lines as $line) {
                $posterId = (int) ($line['poster_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($quantity < 1 || $posterId < 1 || $posterId === $rule->giftPosterId) {
                    continue;
                }

                if (!isset($qualifyingIds[$posterId])) {
                    continue;
                }

                $involved[] = ['id' => $posterId, 'count' => $quantity];
                $qualifying += $quantity;
            }

            $gifts = $rule->giftsFor($qualifying);

            if ($gifts < 1) {
                continue;
            }

            $gift = $promotion->giftName() ? null : $this->localProduct($account, $rule->giftPosterId);

            $applied[] = [
                'kind' => 'gift',
                'promotion_id' => (int) $promotion->id,
                'poster' => [
                    'id' => (int) $promotion->poster_promotion_id,
                    'involved_products' => $involved,
                    'result_products' => [['id' => $rule->giftPosterId, 'count' => $gifts]],
                ],
                'name' => $promotion->name,
                'gift_name' => $promotion->giftName() ?? ($gift ? $gift->name : ('Poster #' . $rule->giftPosterId)),
                'gift_count' => $gifts,
            ];
        }

        // Only one kind of promotion per order: the gifts, unless the discount was
        // chosen and applies.
        if ($applied && !$preferDiscount) {
            return $applied;
        }

        foreach ($promotions->filter(fn(PosterPromotion $promotion) => $promotion->isDiscount()) as $promotion) {
            $discount = $this->discountFor($promotion, $lines);

            if ($discount) {
                return [$discount];
            }
        }

        return $applied;
    }

    /**
     * The discount entry for these lines, or null when there is nothing to discount.
     * It covers the whole cart: Poster applies its promotions to «без скидок»
     * products too (checked on prod), so none are left out.
     */
    private function discountFor(PosterPromotion $promotion, array $lines): ?array
    {
        $rule = $promotion->discountRule();

        if (!$rule) {
            return null;
        }

        $involved = [];
        $orderSum = 0;

        foreach ($lines as $line) {
            $posterId = (int) ($line['poster_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity < 1 || $posterId < 1) {
                continue;
            }

            $involved[] = ['id' => $posterId, 'count' => $quantity];
            $orderSum += (int) ($line['price'] ?? 0) * $quantity;
        }

        // Poster's "order sum from" condition, on the whole order
        if ($orderSum < $rule->minSum) {
            return null;
        }

        $discount = $rule->discountOn($orderSum);

        if ($discount < 1) {
            return null;
        }

        return [
            'kind' => 'discount',
            'promotion_id' => (int) $promotion->id,
            'poster' => [
                'id' => (int) $promotion->poster_promotion_id,
                'involved_products' => $involved,
            ],
            'name' => $promotion->name,
            'percent' => $rule->percent,
            'discount' => $discount,
        ];
    }

    /**
     * The total discount in kopecks of applied promotions.
     */
    public static function discountTotal(array $applied): int
    {
        return (int) array_sum(array_map(
            fn($entry) => ($entry['kind'] ?? 'gift') === 'discount' ? (int) ($entry['discount'] ?? 0) : 0,
            $applied
        ));
    }

    /**
     * Only the gift entries of applied promotions. Entries stored before discounts
     * existed have no kind and are gifts.
     */
    public static function gifts(array $applied): array
    {
        return array_values(array_filter($applied, fn($entry) => ($entry['kind'] ?? 'gift') === 'gift'));
    }

    /**
     * Only the discount entries of applied promotions.
     */
    public static function discounts(array $applied): array
    {
        return array_values(array_filter($applied, fn($entry) => ($entry['kind'] ?? 'gift') === 'discount'));
    }

    /**
     * Our product ids for these Poster product ids of the account, for the apps.
     */
    public function localProductIds(PosterAccount $account, array $posterProductIds): array
    {
        if (!$posterProductIds) {
            return [];
        }

        return Product::whereHas('poster_accounts', fn($query) => $query
            ->where('poster_account_id', $account->id)
            ->whereIn('poster_id', $posterProductIds))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    public function localProduct(PosterAccount $account, int $posterProductId): ?Product
    {
        return Product::whereHas('poster_accounts', fn($query) => $query->where([
            ['poster_id', $posterProductId],
            ['poster_account_id', $account->id],
        ]))->first();
    }

    /**
     * Poster keeps promotion schedules in the account's own timezone, while the
     * server runs UTC.
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(Config::get('layerok.restapi::order.timezone')));
    }
}

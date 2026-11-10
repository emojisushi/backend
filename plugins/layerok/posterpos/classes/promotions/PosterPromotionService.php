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
     * Promotions the order qualifies for.
     *
     * @param array $lines [['product' => Product, 'quantity' => int, 'poster_id' => int], ...]
     * @return array[] each ['poster' => <Poster `promotion` entry>, 'name' => string, 'gift_name' => string, 'gift_count' => int]
     */
    public function applyToOrder(PosterAccount $account, Spot $spot, array $lines): array
    {
        $applied = [];

        foreach ($this->runningFor($account, $spot) as $promotion) {
            $rule = $promotion->rule();

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

        return $applied;
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

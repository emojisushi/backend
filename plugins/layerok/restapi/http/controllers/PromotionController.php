<?php
declare(strict_types=1);

namespace Layerok\Restapi\Http\Controllers;


use Illuminate\Http\JsonResponse;
use Layerok\PosterPos\Classes\Promotions\PosterPromotionService;
use Layerok\PosterPos\Models\PosterPromotion;
use Layerok\PosterPos\Models\Promotion;
use OFFLINE\Mall\Classes\Utils\Money;
use OFFLINE\Mall\Models\Currency;
use poster\src\PosterApi;

/**
 *
 */
class PromotionController extends Controller
{
    /**
     * Marketing promotions authored in the backend, newest first.
     */
    public function all(): JsonResponse
    {
        $promotions = Promotion::with('image')
            ->where('published', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $promotions->toArray(),
        ]);
    }

    /**
     * The Poster promotions the backend applies to online orders today, with what
     * the apps need to show the gift in the cart. The apps apply active_from /
     * active_to themselves. Display only: the order is checked again on placement,
     * and that is what decides the gift.
     */
    public function posterActive(): JsonResponse
    {
        $service = new PosterPromotionService();
        $now = $service->now();

        $promotions = PosterPromotion::enabled()
            ->with('poster_account')
            ->get()
            ->filter(fn(PosterPromotion $promotion) => $promotion->poster_account
                && $promotion->isImplemented()
                && $promotion->isScheduledInPosterAt($now))
            ->map(function (PosterPromotion $promotion) use ($service) {
                $rule = $promotion->rule();
                $account = $promotion->poster_account;
                // Our ids of the products whose Poster category counts, so the apps
                // count exactly what the order will.
                $productIds = $service->localProductIds($account, $promotion->qualifyingPosterProductIds());

                if (!$productIds) {
                    return null;
                }

                $gift = $this->giftFor($promotion, $service);

                if (!$gift) {
                    return null;
                }

                return [
                    'id' => $promotion->id,
                    'name' => $promotion->name,
                    'product_ids' => $productIds,
                    'category_name' => $promotion->category_name,
                    'required_count' => $rule->requiredCount,
                    'gift_count' => $rule->giftCount,
                    'accumulate' => $rule->accumulate,
                    // Kyiv time; null when the promotion runs all day
                    'active_from' => $promotion->hasWindow() ? $promotion->active_from : null,
                    'active_to' => $promotion->hasWindow() ? $promotion->active_to : null,
                    // ISO week days (1 = Monday) in Kyiv; [] for every day
                    'active_days' => $promotion->activeDays(),
                    'spot_ids' => $promotion->spots()->pluck('id')->map(fn($id) => (int) $id)->values(),
                    'gift' => $gift,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $promotions]);
    }

    /**
     * {name, image, price_formatted} of the gift: from the Poster snapshot taken on
     * import, or from our product when the snapshot is missing. Gift products are
     * usually out of the menu, so ours may not exist at all.
     */
    private function giftFor(PosterPromotion $promotion, PosterPromotionService $service): ?array
    {
        $money = app()->make(Money::class);
        $snapshot = (array) $promotion->gift;

        if ($promotion->giftName()) {
            $price = $promotion->giftPrice();

            return [
                'name' => $promotion->giftName(),
                'image' => $snapshot['photo'] ?? null,
                'price_formatted' => $price ? $money->format($price, null, Currency::$defaultCurrency) : null,
            ];
        }

        $product = $service->localProduct($promotion->poster_account, $promotion->rule()->giftPosterId);

        if (!$product) {
            return null;
        }

        $product->load(['image_sets.images', 'prices']);
        $image = optional(optional($product->image_sets->first())->images)->first();
        $price = optional($product->prices->first())->price;

        return [
            'name' => $product->name,
            'image' => $image ? $image->path : null,
            'price_formatted' => $price ? $money->format($price, null, Currency::$defaultCurrency) : null,
        ];
    }

    /**
     * Poster's own loyalty promotions, a different thing entirely.
     */
    public function list(): JsonResponse
    {
        PosterApi::init(config('poster'));
        $result = (object)PosterApi::makeApiRequest('clients.getPromotions', 'get');
        return response()->json($result->response);
    }
}

<?php

namespace Layerok\PosterPos\Classes\Promotions;

use Carbon\Carbon;
use Layerok\PosterPos\Models\PosterAccount;
use Layerok\PosterPos\Models\PosterPromotion;
use poster\src\PosterApi;

/**
 * Copies the promotions of every Poster account in (clients.getPromotions).
 *
 * Read-only towards Poster. The enabled flag survives a re-import, but a promotion
 * whose params no longer have a supported shape, or which Poster no longer returns,
 * is switched off rather than applied by guesswork.
 */
class PosterPromotionImporter
{
    /**
     * @return array{imported: int, removed: int, errors: string[]}
     */
    public function import(): array
    {
        $result = ['imported' => 0, 'removed' => 0, 'errors' => []];

        foreach (PosterAccount::all() as $account) {
            try {
                $promotions = $this->fetch($account);
            } catch (\Throwable $e) {
                $result['errors'][] = $account->account_name . ': ' . $e->getMessage();
                continue;
            }

            $seen = [];
            $now = Carbon::now();

            foreach ($promotions as $raw) {
                $raw = json_decode(json_encode($raw), true);
                $posterId = (int) ($raw['promotion_id'] ?? 0);

                if ($posterId < 1) {
                    continue;
                }

                $seen[] = $posterId;

                $model = PosterPromotion::firstOrNew([
                    'poster_account_id' => $account->id,
                    'poster_promotion_id' => $posterId,
                ]);

                $model->fill([
                    'name' => trim((string) ($raw['name'] ?? '')) ?: ('#' . $posterId),
                    'params' => (array) ($raw['params'] ?? []),
                    'date_start' => PromotionSchedule::normalizeDate($raw['date_start'] ?? null),
                    'date_end' => PromotionSchedule::normalizeDate($raw['date_end'] ?? null),
                    'auto_apply' => (string) ($raw['auto_apply'] ?? '0') === '1',
                    'removed_in_poster' => false,
                    'imported_at' => $now,
                ]);

                if (!$model->exists) {
                    $model->enabled = false;
                }

                if (!$model->isImplemented()) {
                    $model->enabled = false;
                } else {
                    $this->snapshot($model);
                }

                $model->save();
                $result['imported']++;
            }

            $gone = PosterPromotion::where('poster_account_id', $account->id)
                ->where('removed_in_poster', false)
                ->when($seen, fn($query) => $query->whereNotIn('poster_promotion_id', $seen))
                ->get();

            foreach ($gone as $model) {
                $model->removed_in_poster = true;
                $model->enabled = false;
                $model->save();
                $result['removed']++;
            }
        }

        return $result;
    }

    /**
     * The gift and the category as Poster has them. Gift products are usually kept
     * out of the menu, so they may never have been synced here. A failed request
     * keeps the previous snapshot. Expects PosterApi to be initialised for the
     * promotion's account, which fetch() has just done.
     */
    private function snapshot(PosterPromotion $model): void
    {
        $rule = $model->rule();

        try {
            $product = json_decode(json_encode(
                ((object) PosterApi::makeApiRequest('menu.getProduct', 'get', ['product_id' => $rule->giftPosterId]))->response ?? null
            ), true);

            if (is_array($product) && !empty($product['product_name'])) {
                $photo = (string) ($product['photo_origin'] ?? '') ?: (string) ($product['photo'] ?? '');

                $model->gift = [
                    'poster_id' => $rule->giftPosterId,
                    'name' => trim((string) $product['product_name']),
                    'photo' => $photo !== '' ? (str_starts_with($photo, 'http') ? $photo : 'https://joinposter.com' . $photo) : null,
                    // Poster spot id => price in kopecks
                    'prices' => array_map('intval', (array) ($product['price'] ?? [])),
                ];
            }
        } catch (\Throwable $e) {
        }

        try {
            $category = json_decode(json_encode(
                ((object) PosterApi::makeApiRequest('menu.getCategory', 'get', ['category_id' => $rule->categoryPosterId]))->response ?? null
            ), true);

            if (is_array($category) && !empty($category['category_name'])) {
                $model->category_name = trim((string) $category['category_name']);
            }
        } catch (\Throwable $e) {
        }
    }

    private function fetch(PosterAccount $account): array
    {
        PosterApi::init([
            'account_name' => $account->account_name,
            'application_id' => $account->application_id,
            'application_secrete' => $account->application_secret,
            'access_token' => $account->access_token,
        ]);

        $response = (object) PosterApi::makeApiRequest('clients.getPromotions', 'get');

        if (isset($response->error)) {
            throw new \RuntimeException((string) ($response->message ?? $response->error));
        }

        if (!isset($response->response) || !is_array($response->response)) {
            throw new \RuntimeException('Poster returned no promotions list');
        }

        return $response->response;
    }
}

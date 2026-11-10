<?php

namespace Layerok\PosterPos\Classes\Promotions;

/**
 * "Buy N from a category, get a product for free". In Poster, the 2+1 pizza promotion
 * is configured like this:
 *
 *   conditions:  [{type: 1 (category), id: 18, pcs: 2}]
 *   result_type: 1 (bonus product)
 *   bonus_products: [{type: 2 (product), id: 1077}], bonus_products_pcs: 1
 *   bonus_products_condition_type: 1, bonus_products_condition_value: 100  (100% off)
 *   bonus_products_accumulation: 0  (one gift per order, however many pizzas)
 *
 * Any promotion whose params differ from this shape is not supported, so it cannot
 * be enabled. That way a promotion edited in Poster is never applied by guesswork.
 *
 * Has no framework dependencies so it can be checked without booting October.
 */
final class CategoryGiftRule
{
    public const KEY = 'category_gift';

    private const CONDITION_CATEGORY = 1;
    private const RESULT_BONUS_PRODUCT = 1;
    private const BONUS_ITEM_PRODUCT = 2;
    private const BONUS_PRICE_PERCENT = 1;
    private const CLIENTS_ALL = 1;

    public function __construct(
        public int $categoryPosterId,
        public int $requiredCount,
        public int $giftPosterId,
        public int $giftCount,
        public bool $accumulate,
    ) {
    }

    /**
     * The rule described by these params, or null when the params do not have
     * exactly the supported shape.
     */
    public static function fromParams(array $params): ?self
    {
        return self::unsupportedReason($params) === null ? new self(
            (int) $params['conditions'][0]['id'],
            (int) $params['conditions'][0]['pcs'],
            (int) $params['bonus_products'][0]['id'],
            (int) $params['bonus_products_pcs'],
            (string) ($params['bonus_products_accumulation'] ?? '0') === '1',
        ) : null;
    }

    /**
     * Why the params are not supported, for the backend list; null when they are.
     */
    public static function unsupportedReason(array $params): ?string
    {
        $conditions = $params['conditions'] ?? [];
        $bonus = $params['bonus_products'] ?? [];

        if ((int) ($params['result_type'] ?? 0) !== self::RESULT_BONUS_PRODUCT) {
            return 'The result is not a gift product';
        }

        if (!is_array($conditions) || count($conditions) !== 1) {
            return 'It has more than one condition';
        }

        $condition = $conditions[0];

        if ((int) ($condition['type'] ?? -1) !== self::CONDITION_CATEGORY) {
            return 'The condition is not "N items from a category"';
        }

        if ((int) ($condition['pcs'] ?? 0) < 1 || (float) ($condition['g'] ?? 0) > 0 || (int) ($condition['sum'] ?? 0) > 0) {
            return 'The condition is not a number of items';
        }

        if ((int) ($params['conditions_exactly'] ?? 0) !== 0) {
            return 'The condition requires an exact quantity';
        }

        if ((int) ($params['accumulation_checks_count'] ?? 0) !== 0) {
            return 'The promotion accumulates over several orders';
        }

        if ((int) ($params['clients_type'] ?? 0) !== self::CLIENTS_ALL) {
            return 'It is limited to client groups';
        }

        if (!is_array($bonus) || count($bonus) !== 1) {
            return 'The gift is a choice of several products';
        }

        if ((int) ($bonus[0]['type'] ?? 0) !== self::BONUS_ITEM_PRODUCT || (int) ($bonus[0]['id'] ?? 0) < 1) {
            return 'The gift is not a single product';
        }

        if ((int) ($params['bonus_products_pcs'] ?? 0) < 1 || (float) ($params['bonus_products_g'] ?? 0) > 0) {
            return 'The gift is not a number of items';
        }

        if (
            (int) ($params['bonus_products_condition_type'] ?? 0) !== self::BONUS_PRICE_PERCENT
            || (int) ($params['bonus_products_condition_value'] ?? 0) !== 100
        ) {
            return 'The gift is not 100% free';
        }

        return null;
    }

    /**
     * How many gift items an order gets for this many qualifying items.
     */
    public function giftsFor(int $qualifyingCount): int
    {
        if ($qualifyingCount < $this->requiredCount) {
            return 0;
        }

        if (!$this->accumulate) {
            return $this->giftCount;
        }

        return intdiv($qualifyingCount, $this->requiredCount) * $this->giftCount;
    }
}

<?php

namespace Layerok\PosterPos\Classes\Promotions;

/**
 * "N% off the order". In Poster, «Скидка самовывоз 10%» is configured like this:
 *
 *   conditions:  [{type: 0 (any product), id: 0, pcs: 1}]
 *   result_type: 3 (percentage discount), discount_value: 10
 *
 * The condition may instead be an order sum ({type: 0, pcs: 0, sum: 100} is "from
 * 1 ₴"), in which case the order has to reach that sum.
 *
 * Poster has no condition on how the order is served, so «самовывоз» lives only in
 * the name, and 10%, 15%, 30%, 50% coupons have exactly the same shape. Which
 * orders such a promotion is for is therefore never guessed: it is chosen in the
 * backend (PosterPromotion::$order_types), and it cannot be enabled before that.
 *
 * It covers the whole cart: Poster applies promotions to products marked «без
 * скидок» (nodiscount) as well, so they are discounted too.
 *
 * Has no framework dependencies so it can be checked without booting October.
 */
final class OrderDiscountRule
{
    public const KEY = 'order_discount';

    private const CONDITION_ANY_PRODUCT = 0;
    private const RESULT_PERCENT = 3;
    private const CLIENTS_ALL = 1;

    /**
     * @param int $percent
     * @param int $minSum the order sum (kopecks) it starts from, 0 for any order;
     *   Poster's "order sum from" condition, e.g. 100 for «від 1 ₴»
     */
    public function __construct(public int $percent, public int $minSum = 0)
    {
    }

    public static function fromParams(array $params): ?self
    {
        return self::unsupportedReason($params) === null
            ? new self((int) $params['discount_value'], max(0, (int) ($params['conditions'][0]['sum'] ?? 0)))
            : null;
    }

    public static function unsupportedReason(array $params): ?string
    {
        $conditions = $params['conditions'] ?? [];

        if ((int) ($params['result_type'] ?? 0) !== self::RESULT_PERCENT) {
            return 'The result is not a percentage discount';
        }

        $percent = (float) ($params['discount_value'] ?? 0);

        if ($percent <= 0 || $percent >= 100 || $percent != (int) $percent) {
            return 'The discount is not a whole percentage between 1 and 99';
        }

        if (!empty($params['discount_prices'])) {
            return 'It sets special prices';
        }

        if (!is_array($conditions) || count($conditions) !== 1) {
            return 'It has more than one condition';
        }

        $condition = $conditions[0];

        if ((int) ($condition['type'] ?? -1) !== self::CONDITION_ANY_PRODUCT || (int) ($condition['id'] ?? 0) !== 0) {
            return 'The condition is not "any product"';
        }

        // "any product, 1 pc" or "order sum from X": both just start the discount
        if ((int) ($condition['pcs'] ?? 0) > 1 || (float) ($condition['g'] ?? 0) > 0) {
            return 'The condition needs several items or a weight';
        }

        if ((int) ($params['accumulation_checks_count'] ?? 0) !== 0) {
            return 'The promotion accumulates over several orders';
        }

        if ((int) ($params['clients_type'] ?? 0) !== self::CLIENTS_ALL) {
            return 'It is limited to client groups';
        }

        return null;
    }

    /**
     * The discount in kopecks on this discountable sum (kopecks). Rounded to whole
     * kopecks; whether Poster rounds the same way is to be checked on a real bill.
     */
    public function discountOn(int $discountableSum): int
    {
        return $discountableSum > 0 ? (int) round($discountableSum * $this->percent / 100) : 0;
    }
}

<?php

namespace Layerok\RestApi\Models;

use Model;

/**
 * One row per bonus movement caused by an online order.
 *
 * Poster owns the balance and the points are written off there the moment the
 * order is placed, so Poster's number is always correct on its own — this table
 * is the record of what we did, and what has to be given back if the order is
 * rejected. Amounts are in minor units (kopecks), matching clients.bonus.
 *
 * Lifecycle: pending -> applied  (points written off in Poster)
 *            applied -> settled  (order closed, write-off is final)
 *            applied -> refunded (order rejected, points given back)
 *            pending -> failed   (write-off did not go through; nothing was taken)
 *
 * Rows with status external are not part of that lifecycle: they record balance
 * movements Poster made on its own, discovered through the client webhook.
 */
class BonusTransaction extends Model
{
    public $table = 'bonus_transactions';

    const STATUS_PENDING = 'pending';
    const STATUS_APPLIED = 'applied';
    const STATUS_SETTLED = 'settled';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_FAILED = 'failed';
    /** A change Poster made that we did not cause: till spend, accrual, manual edit. */
    const STATUS_EXTERNAL = 'external';

    /** States in which the points are actually gone from the client's Poster balance. */
    const SPENT_STATUSES = [self::STATUS_APPLIED, self::STATUS_SETTLED];

    protected $fillable = [
        'user_id',
        'poster_client_id',
        'online_order_id',
        'incoming_order_id',
        'transaction_id',
        'amount',
        'delta',
        'balance_after',
        'status',
        'note',
    ];

    protected $casts = [
        'amount' => 'integer',
        'delta' => 'integer',
        'balance_after' => 'integer',
    ];

    public function scopeSpent($query)
    {
        return $query->whereIn('status', self::SPENT_STATUSES);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}

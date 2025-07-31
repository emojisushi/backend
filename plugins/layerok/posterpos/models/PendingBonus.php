<?php
namespace Layerok\PosterPos\Models;

use Illuminate\Database\Eloquent\Model;

class PendingBonus extends Model
{

    protected $fillable = [
        'order_id',
        'user_id',
        'use_bonus_amount',
        'receive_bonus_amount',
        'pending',
    ];
}
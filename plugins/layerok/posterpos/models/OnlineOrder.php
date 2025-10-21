<?php
namespace Layerok\PosterPos\Models;

use Model;

class OnlineOrder extends Model
{
    protected $table = 'online_orders';

    protected $fillable = [
        'status',
        'online_payment_id',
        'poster_id',
        'products',
        'total',
        'cart',
        'phone',
        'comment',
        'first_name',
        'last_name',
        'service_mode',
        'address',
        'spot_id',
        'delivery_price',
    ];

}

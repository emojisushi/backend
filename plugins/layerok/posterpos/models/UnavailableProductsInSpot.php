<?php namespace Layerok\PosterPos\Models;

use Layerok\PosterPos\Controllers\Spot;
use October\Rain\Database\Model;
use OFFLINE\Mall\Models\Product;

class UnavailableProductsInSpot extends Model
{
    protected $table = 'layerok_posterpos_unavailable_products_in_spot';

    protected $primaryKey = 'id';
    public $timestamps = false;

    public $fillable = ['product_id', 'spot_id'];

    public $belongsTo = [
        'product' => Product::class,
        'spot' => Spot::class,
    ];
}

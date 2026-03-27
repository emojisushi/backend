<?php namespace Layerok\PosterPos\Models;

use Layerok\PosterPos\Controllers\Spot;
use October\Rain\Database\Model;
use OFFLINE\Mall\Models\Category;

class UnavailableCategoryInSpot extends Model
{
    protected $table = 'layerok_posterpos_unavailable_categories_in_spot';

    protected $primaryKey = 'id';
    public $timestamps = false;

    public $fillable = ['category_id', 'spot_id'];

    public $belongsTo = [
        'category' => Category::class,
        'spot' => Spot::class,
    ];
}

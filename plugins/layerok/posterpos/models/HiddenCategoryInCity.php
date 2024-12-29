<?php namespace Layerok\PosterPos\Models;

use October\Rain\Database\Model;
use OFFLINE\Mall\Models\Category;

class HiddenCategoryInCity extends Model
{
    protected $table = 'layerok_posterpos_hidden_categories_in_city';

    protected $primaryKey = 'id';
    public $timestamps = false;

    public $fillable = ['category_id', 'city_id'];

    public $belongsTo = [
        'category' => Category::class,
        'city' => City::class,
    ];
}

<?php

namespace Layerok\PosterPos\Models;

use Layerok\Telegram\Models\Bot;
use Layerok\Telegram\Models\Chat;
use October\Rain\Database\Model;
use October\Rain\Database\Traits\Sluggable;
use OFFLINE\Mall\Models\Category;
use OFFLINE\Mall\Models\Product;

class RecommendedItem extends Model
{
    use Sluggable;

    protected $table = 'layerok_posterpos_spots';
    protected $primaryKey = 'id';
    public $implement = ['@RainLab.Translate.Behaviors.TranslatableModel'];
    public $translatable = [
        ['slug', 'index' => true],
        'name',
        'address',
        'html_content'
    ];


    public $timestamps = true;
    public $fillable = [
        'name',
        'code',
        'phones',
        'bot_id',
        'chat_id',
        'address',
        'cover',
        'slug',
        'poster_id',
        'city_id',
        'poster_account_id',
        'merchant_account',
        'merchant_secret_key',
        'domain_name',
    ];
    protected $hidden = ['merchant_account', 'merchant_secret_key'];

    public $slugs = [
        'slug' => 'name',
    ];

    public $rules = [
        'slug' => ['regex:/^[a-z0-9\/\:_\-\*\[\]\+\?\|]*$/i', 'unique:layerok_posterpos_spots'],
        'name' => 'required',
        'poster_id' => 'required'
    ];

    public $belongsToMany = [
        'hideProducts'          => [
            Product::class,
            'table'    => 'layerok_posterpos_hide_products_in_spot',
            'key'      => 'spot_id',
            'otherKey' => 'product_id',
        ],
        'hideCategories' => [
            Category::class,
            'table' => 'layerok_posterpos_hide_categories_in_spot',
            'key' => 'spot_id',
            'otherKey' => 'category_id',
        ],
        'unavailable_categories' => [
            Category::class,
            'table' => 'layerok_posterpos_unavailable_categories_in_spot',
            'key' => 'spot_id',
            'otherKey' => 'category_id',
        ],
        'unavailable_products' => [
            Product::class,
            'table' => 'layerok_posterpos_unavailable_products_in_spot',
            'key' => 'spot_id',
            'otherKey' => 'product_id',
        ],
    ];

    public $attachMany = [
        'photos' => \System\Models\File::class
    ];

    public $belongsTo = [
        'chat' => Chat::class,
        'bot' => Bot::class,
        'city' => City::class,
        'tablet' => Tablet::class,
        'posterAccount' => PosterAccount::class,
    ];

    public $hasMany = [
        'districts' => District::class,
        'areas' => \Layerok\PosterPos\Models\Area::class,
    ];

    public function afterDelete()
    {
        $this->hideProducts()->delete();
        $this->hideCategories()->delete();
    }

    public static function findBySlugOrId($slug_or_id)
    {
        $key = is_numeric($slug_or_id) ? 'id' : 'slug';
        return self::where($key, $slug_or_id)->first();
    }

    public static function getMain()
    {
        return self::where('is_main', 1)->first();
    }
}

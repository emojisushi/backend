<?php namespace Layerok\PosterPos\Models;

use Model;
use October\Rain\Database\Traits\Sluggable;

/**
 * City Model
 *
 * @link https://docs.octobercms.com/3.x/extend/system/models.html
 */
class City extends Model
{
    /**
     * @var string table name
     */
    public $table = 'layerok_posterpos_cities';

    use \October\Rain\Database\Traits\Validation;
    use Sluggable;
    public $implement = ['@RainLab.Translate.Behaviors.TranslatableModel'];

    public $translatable = [
        ['slug', 'index' => true],
        'name',
    ];

    public $fillable = [
        'name',
        'slug',
    ];

    public $slugs = [
        'slug' => 'name',
    ];

    public $rules = [
        'slug' => ['regex:/^[a-z0-9\/\:_\-\*\[\]\+\?\|]*$/i', 'unique:layerok_posterpos_spots'],
        'name' => 'required',
    ];

    public $hasMany = [
        'spots' => Spot::class,
        'districts' => District::class,
        'hidden_categories' => HiddenCategoryInCity::class,
    ];

    // todo: extend Model class with this method
    public static function findBySlugOrId($slug_or_id) {
        $key = is_numeric($slug_or_id) ? 'id': 'slug';
        return self::where($key, $slug_or_id)->first();
    }
}

<?php namespace Layerok\PosterPos\Models;

use Model;
use System\Models\File;

/**
 * Marketing promotion shown in the apps. Authored in the backend, unrelated to
 * Poster's loyalty promotions served by clients.getPromotions.
 */
class Promotion extends Model
{
    public $table = 'layerok_posterpos_promotions';

    protected $fillable = [
        'header',
        'text',
        'published',
    ];

    public $attachOne = [
        'image' => File::class,
    ];
}

<?php
namespace Layerok\PosterPos\Models;

use Model;

class Address extends Model
{
    protected $fillable = [
        'id',
        'name_ua',
        'name_ru',
        'lon',
        'lat',
        'suburb_ua',
        'suburb_ru',
    ];
}
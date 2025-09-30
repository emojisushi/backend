<?php
namespace Layerok\PosterPos\Models;

use Model;

class Area extends Model
{
    protected $table = 'areas';

    protected $fillable = [
        'name',
        'description',
        'coords',
        'color',
        'spot_id',
    ];

    protected $casts = [
        'coords' => 'array', // automatically cast JSON to PHP array
    ];
    public $belongsTo = [
        'spot' => Spot::class,
    ];
}

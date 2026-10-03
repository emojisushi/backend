<?php namespace Layerok\PosterPos\Models;

use Model;

/**
 * Cached result of looking a street and house number up with a geocoder.
 * Misses are stored too, so a failing address is not retried on every page load.
 */
class GeocodedAddress extends Model
{
    public $table = 'layerok_posterpos_geocoded_addresses';

    protected $fillable = [
        'street',
        'house',
        'lat',
        'lng',
        'display_name',
        'result_class',
        'found',
        'looked_up_at',
        'distance_m',
    ];

    protected $dates = ['looked_up_at'];

    public function point(): array
    {
        return [
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
        ];
    }
}

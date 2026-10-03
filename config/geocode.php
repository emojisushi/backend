<?php

return [
    /*
     * Look up exact building coordinates for delivery addresses.
     */
    'enabled' => env('GEOCODE_ENABLED', true),

    /*
     * Nominatim requires a User-Agent identifying the application, with a way to
     * get in touch. Generic clients are blocked.
     * https://operations.osmfoundation.org/policies/nominatim/
     */
    'user_agent' => env('GEOCODE_USER_AGENT', 'emojisushi-delivery-map/1.0 (support@emojisushi.com.ua)'),

    /*
     * Lookups are constrained to a bounding box rather than a city, because spots
     * serve both Одеса and Чорноморськ.
     *
     * Format is Nominatim's: left,top,right,bottom — that is
     * min longitude, max latitude, max longitude, min latitude.
     * The default spans Чорноморськ in the south-west to the far side of Одеса.
     */
    'viewbox' => env('GEOCODE_VIEWBOX', '30.50,46.70,31.00,46.20'),

    /*
     * Discard anything outside the box rather than merely preferring what is
     * inside it. Without this Nominatim happily returns another oblast.
     */
    'bounded' => env('GEOCODE_BOUNDED', true),

    'country_codes' => env('GEOCODE_COUNTRY_CODES', 'ua'),
];

<?php

declare(strict_types=1);

namespace Layerok\Restapi\Services;
use Layerok\PosterPos\Models\City;

class AppService {
    public function getCurrentCitySlug(): string|null {
        if($refererParts = explode('//', request()->header('referer'))) {
            if(count($refererParts) > 1) {
                return explode('.', $refererParts[1])[0];
            }
            return null;
        }
        return null;
    }

    public function getCurrentCity(): null|City {
        if($city_slug = $this->getCurrentCitySlug()) {
            return City::where('slug', $city_slug)->first();
        }
        return null;
    }
}

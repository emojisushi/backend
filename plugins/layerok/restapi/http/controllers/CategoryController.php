<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Layerok\PosterPos\Classes\RootCategory;
use Layerok\PosterPos\Models\City;
use OFFLINE\Mall\Models\Category;

class CategoryController extends Controller
{

    // todo: reduce duplication, the same method exists in MySQL class
    public function getCurrentCitySlug(): string|null {
        if($refererParts = explode('//', request()->header('referer'))) {
            if(count($refererParts) > 1) {
                return explode('.', $refererParts[1])[0];
            }
            return null;
        }
        return null;
    }

    // todo: reduce duplication, the same method exists in MySQL class
    public function getCurrentCity(): null|City {
        if($city_slug = $this->getCurrentCitySlug()) {
            return City::where('slug', $city_slug)->first();
        }
        return null;
    }

    public function fetch(): JsonResponse
    {
        $offset = input('offset');
        $limit = input('limit');

        $query = Category::query()->with([ 'image']);
        $query->where('published', '=', '1');

        if($city = $this->getCurrentCity()) {
            $query->whereDoesntHave('hidden_categories_in_city', function($query) use($city) {
                return $query->where('city_id', $city->id);
            });
        }

        if($limit) {
            $query->limit($limit);
        }

        if($offset) {
            $query->offset($offset);
        }

        $records = $query->get();

        return response()->json([
            'data' => $records->toArray(),
            'meta' => [
                'total' => $records->count(),
                'offset' => $offset,
                'limit' => $limit
            ]
        ]);
    }
}

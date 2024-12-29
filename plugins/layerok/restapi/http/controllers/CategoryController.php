<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Layerok\PosterPos\Classes\RootCategory;
use Layerok\PosterPos\Models\City;
use Layerok\Restapi\Services\AppService;
use OFFLINE\Mall\Models\Category;

class CategoryController extends Controller
{

    public function fetch(): JsonResponse
    {
        $offset = input('offset');
        $limit = input('limit');

        $query = Category::query()->with([ 'image']);
        $query->where('published', '=', '1');

        // todo: inject it via container
        $appService = new AppService();

        if($city = $appService->getCurrentCity()) {
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

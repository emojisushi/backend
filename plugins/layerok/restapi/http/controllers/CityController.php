<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\City;

class CityController extends Controller
{
    public function fetch(): JsonResponse
    {
        $offset = input('offset');
        $limit = input('limit');
        $includeSpots = input('includeSpots');
        $includeDistricts = input('includeDistricts');

        $query = City::query();

        if ($includeSpots) {
            $query->with(['spots' => function ($q) {
                $q->where('published', 1);
            }]);
        }

        if ($includeDistricts) {
            $query->with(['districts' => function ($q) {
                $q->whereHas('spot', function ($q) {
                    $q->where('published', 1);
                });
            },'districts.spot']);
        }

        if ($limit) {
            $query->limit($limit);
        }

        if ($offset) {
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

    public function one(): JsonResponse
    {
        $slug_or_id = input('slug_or_id');

        $key = is_numeric($slug_or_id) ? 'id': 'slug';
        $record = City::with(['spots' => function ($q) {
            $q->where('published', 1);
        }, 'districts' => function ($q) {
            $q->with(['spots' => function ($q) {
                // we are sending an order to a first spot of a district
                //$q->where('published', 1);
            }]);
        }])->where($key, $slug_or_id)->first();

        if (!$record) {
            return response()->json(['error' => 'Not Found!'], 404);
        }

        return response()->json($record);
    }

    public function main(): JsonResponse
    {
        $city = City::with(['spots' => function ($q) {
            $q->where('published', 1);
        }, 'districts' => function ($q) {
            $q->with(['spots' => function ($q) {
                // we are sending an order to a first spot of a district
//                $q->where('published', 1);
            }]);
        }])
            ->where('is_main', 1)
            ->first();
        if ($city) {
            return response()->json($city);
        }
        return response()->json(['error' => 'Not Found!'], 404);
    }
}

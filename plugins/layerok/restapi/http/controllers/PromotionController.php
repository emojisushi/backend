<?php
declare(strict_types=1);

namespace Layerok\Restapi\Http\Controllers;


use Illuminate\Http\JsonResponse;
use Layerok\PosterPos\Models\Promotion;
use poster\src\PosterApi;

/**
 *
 */
class PromotionController extends Controller
{
    /**
     * Marketing promotions authored in the backend, newest first.
     */
    public function all(): JsonResponse
    {
        $promotions = Promotion::with('image')
            ->where('published', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $promotions->toArray(),
        ]);
    }

    /**
     * Poster's own loyalty promotions, a different thing entirely.
     */
    public function list(): JsonResponse
    {
        PosterApi::init(config('poster'));
        $result = (object)PosterApi::makeApiRequest('clients.getPromotions', 'get');
        return response()->json($result->response);
    }
}

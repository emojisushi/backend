<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Layerok\PosterPos\Models\Banner;
use Layerok\PosterPos\Models\Wishlist;
use Layerok\Restapi\Services\AppService;
use OFFLINE\Mall\Classes\CategoryFilter\SortOrder\SortOrder;
use OFFLINE\Mall\Classes\Index\MySQL\IndexEntry;
use OFFLINE\Mall\Models\Category;
use OFFLINE\Mall\Models\Product;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => $this->getCategories(),
            'wishlists' => $this->getWishlists(),
            'banners' => $this->getBanners(),
            'products' => $this->getProducts(),
            'sort_options' => collect(SortOrder::options(true))->keys(),
        ]);
    }

    public function getBanners (): array  {
        $banners = Banner::with([
            'image',
            'image_small',
            'product',
            'product.categories'
        ])->where('is_active', true)
            ->orderBy('id', 'desc')
            ->get();

        return $banners->toArray();
}

    public function getWishlists(): array {
        $jwtGuard = app('JWTGuard');
        $user = $jwtGuard->user();

        $wishlists = Wishlist::byUser($user);

        return $wishlists->first() ? [
            $wishlists->first()
        ]: [];
    }

    public function getCategories(): Collection {
        $query = Category::query()->with([ 'image']);
        $query->where('published', '=', '1');

        // todo: inject it via container
        $appService = new AppService();

        if($city = $appService->getCurrentCity()) {
            $query->whereDoesntHave('hidden_categories_in_city', function($query) use($city) {
                return $query->where('city_id', $city->id);
            });
        }

        return $query->get();
    }

    public function getProducts(): Collection
    {
        $sortOrder = SortOrder::fromKey('latest');

        $db = DB::table((new IndexEntry())->table)
            ->select([
                'product_id as id',
                'name'
            ])
            ->where('index', "products")
            ->where('published', true)
            ->orderBy($sortOrder->property(), $sortOrder->direction());

        // todo: inject it via container
        $appService = new AppService();
        $hiddenCategoryIds = [];
        if($city = $appService->getCurrentCity()) {
            $hiddenCategoryIds = $city->hidden_categories()
                ->pluck('category_id')
                ->toArray();
        }

        $categoryIds = Category::where('slug', 'menu')
            ->first()
            ->getAllChildrenAndSelf()
            ->pluck('id')
            ->filter(fn($id) => !in_array($id, $hiddenCategoryIds))
            ->toArray();


        $db->where(function ($q) use ($categoryIds) {
            foreach ($categoryIds as $value) {
                $q->orWhereRaw('JSON_CONTAINS(category_id, ?)', json_encode([(int)$value]));
            }
        });


        $items = $db->get();
        $itemIds = $items->pluck('id')
            ->toArray();

        $unorderedModels = Product::with(
            [
                'variants',
                'variants.property_values',
                'hide_products_in_spot',
                'categories.hide_categories_in_spot',
                'variants.additional_prices',
                'image_sets',
                'prices',
                'additional_prices',
                'property_values' => function($query) {
                    $query->where('value', '!=', '0');
                }
            ]
        )->find($itemIds);

        // preserve order
        return collect($itemIds)->map(function($itemId) use ($unorderedModels) {
            return $unorderedModels->first(function($model) use ($itemId) {
                return $model->id == $itemId;
            });
        });
    }

}

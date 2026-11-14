<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Layerok\PosterPos\Models\Banner;
use Layerok\PosterPos\Models\City;
use Layerok\PosterPos\Models\Wishlist;
use Layerok\Restapi\Classes\CatalogCache;
use Layerok\Restapi\Services\AppService;
use RainLab\Translate\Classes\Translator;
use OFFLINE\Mall\Classes\CategoryFilter\SortOrder\SortOrder;
use OFFLINE\Mall\Classes\Index\MySQL\IndexEntry;
use OFFLINE\Mall\Models\Category;
use OFFLINE\Mall\Models\Product;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        // todo: inject it via container
        $appService = new AppService();
        // Categories honour ?city_slug and fall back to the Referer subdomain;
        // products have always used the Referer only.
        $citySlug = input('city_slug');
        $categoriesCity = $citySlug
            ? $appService->getCurrentCity($citySlug)
            : $appService->getCurrentCity();
        $productsCity = $appService->getCurrentCity();
        $withMobile = !(request()->has('mobile') && !request()->boolean('mobile'));

        // The menu is the same for every visitor of a city, so it is cached
        // (see CatalogCache); wishlists belong to the visitor and stay live.
        // Cities are keyed by id, never by the raw slug or Referer, so random
        // input cannot multiply the cache entries.
        $vary = [
            'locale' => Translator::instance()->getLocale(),
            'host' => request()->getHost(),
        ];

        return response()->json([
            'categories' => CatalogCache::remember(
                'categories',
                $vary + ['city' => $categoriesCity?->id],
                fn () => $this->getCategories($categoriesCity)->toArray()
            ),
            'wishlists' => $this->getWishlists(),
            'banners' => CatalogCache::remember(
                'banners',
                $vary,
                fn () => $this->getBanners()
            ),
            'products' => CatalogCache::remember(
                'products',
                $vary + ['city' => $productsCity?->id, 'mobile' => $withMobile],
                fn () => $this->getProducts($productsCity, $withMobile)->toArray()
            ),
            'sort_options' => collect(SortOrder::options(true))->keys(),
        ])->header('X-Catalog-Cache', CatalogCache::header());
    }

    public function getBanners(): array
    {
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

    public function getWishlists(): array
    {
        $jwtGuard = app('JWTGuard');
        $user = $jwtGuard->user();

        $wishlists = Wishlist::byUser($user);

        return $wishlists->first() ? [
            $wishlists->first()
        ] : [];
    }

    public function getCategories(?City $city): Collection
    {
        $query = Category::query()->with(['image']);
        $query->where('published', '=', '1');

        if ($city) {
            $query->whereDoesntHave('hidden_categories_in_city', function ($query) use ($city) {
                $query->where('city_id', $city->id);
            });
        }

        return $query->get();
    }

    public function getProducts(?City $city, bool $withMobile): Collection
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

        $hiddenCategoryIds = [];
        if ($city) {
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

        if (!empty($hiddenCategoryIds)) {
            $db->where(function ($q) use ($hiddenCategoryIds) {
                foreach ($hiddenCategoryIds as $hiddenId) {
                    $q->whereRaw('NOT JSON_CONTAINS(category_id, ?)', json_encode([(int)$hiddenId]));
                }
            });
        }


        $items = $db->get();
        $itemIds = $items->pluck('id')
            ->toArray();
        $mobileFlags = Product::whereIn('id', $itemIds)
            ->pluck('mobile', 'id'); // [$id => bool]

        if (!$withMobile) {
            $itemIds = array_values(array_filter($itemIds, fn($id) => !$mobileFlags[$id]));
        }
        usort($itemIds, fn($a, $b) => $mobileFlags[$b] <=> $mobileFlags[$a]);
        
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
                'property_values' => function ($query) {
                    $query->where('value', '!=', '0');
                }
            ]
        )->find($itemIds);
        // preserve order
        return collect($itemIds)->map(function ($itemId) use ($unorderedModels) {
            return $unorderedModels->first(function ($model) use ($itemId) {
                return $model->id == $itemId;
            });
        });
    }
}

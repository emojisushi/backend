<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Layerok\PosterPos\Classes\RootCategory;
use Layerok\Restapi\Classes\Index\MySQL\MySQL;
use OFFLINE\Mall\Classes\CategoryFilter\QueryString;
use OFFLINE\Mall\Classes\CategoryFilter\SetFilter;
use OFFLINE\Mall\Classes\CategoryFilter\SortOrder\SortOrder;
use OFFLINE\Mall\Models\Category;
use OFFLINE\Mall\Models\Product;
use OFFLINE\Mall\Models\PropertyGroup;

class ProductController extends Controller
{
    public $categories;
    public $category;
    public $filter;
    public $includeChildren = true;
    public $perPage = 25;
    public $pageNumber = 1;
    public $offset;
    public $limit;
    public $totalCount = 0;

    public function fetch(): JsonResponse
    {
        $this->offset = input('offset') ?? 0;
        $this->limit = input('limit') ?? 25;
        $this->category = $this->getCategory();
        $this->filter = input('filter'); // it can look like 'category_id=1.3.4.6&price=100-200'
        $this->pageNumber = ($this->offset + $this->limit) / $this->limit;
        $this->perPage =  $this->limit;
        /*$this->includeChildren = input('include_children');*/

        if(!$this->category) {
            return response()->json(null, 404);
        }

        if ($this->category) {
            $this->categories = collect([$this->category]);
            if ($this->includeChildren) {
                $this->categories = $this->category->getAllChildrenAndSelf();
            }
        }

        return response()->json([
            'data' => $this->getItems()->toArray(),
            'total' => $this->totalCount,
            'sort_options' => $this->getSortOptions(),
        ]);
    }

    public function getSortOptions() {
        return collect(SortOrder::options(true))->keys();
    }

    protected function getItems()
    {
        $filters   = $this->getFilters();
        $sortOrder = $this->getSortOrder();

        $sortOrder->setFilters(clone $filters);

        $index  = new MySQL();
        $result = $index->fetch(
            "products",
            $filters,
            $sortOrder,
            $this->perPage,
            $this->pageNumber,
        );
        $this->totalCount = $result->totalCount;

        // Every id that is not an int is a "ghosted" variant, with an id like
        // product-1. These ids have to be fetched separately. This enables us to
        // query variants and products that don't have any variants from the same index.
        $itemIds  = array_filter($result->ids, 'is_int');
        $ghostIds = array_diff($result->ids, $itemIds);

        $models = Product::with($this->productIncludes())->find($itemIds);
        $ghosts = $this->getGhosts($ghostIds);

        // Insert the Ghost models back at their old position so the sort order remains.
        $items = collect($result->ids)->map(function ($id) use ($models, $ghosts) {
            return is_int($id)
                ? $models->find($id)
                : $ghosts->find(str_replace('product-', '', $id));
        });

        return $items;

    }

    protected function getCategory()
    {
        if ($this->category) {
            return $this->category;
        }

        $slug = input('category_slug');

        try {
            return Category::where('slug', $slug)->first();
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function getFilters(): Collection
    {
        // $filter = request()->all();
        // the problem with line above is that, we have search query params like 'offset', 'limit', 'session_id', etc.
        // and if some 'Property' will have the same slug, then it will lead to filtering products by that property,
        // which was not intended
        // why that code was here in a first place?, because when I was building REST API, I was copying code from
        // OFFLINE.MALL components, and such code made sense there, but here it doesn't, because special query params
        // and property slugs may collide, which will lead to errors
        $filter = [];
        if ($this->filter) {
            parse_str($this->filter, $filter);
        }

        $filter = array_wrap($filter);

        $filters = (new QueryString())->deserialize($filter, $this->category);
        if ($this->categories && !isset($filters['category_id'])) {
            $filters->put('category_id', new SetFilter('category_id', $this->categories->pluck('id')->toArray()));
        }

        return $filters;
    }

    protected function getSortOrder(): SortOrder
    {
        $key = input('sort', 'latest');

        return SortOrder::fromKey($key);
    }

    protected function productIncludes(): array
    {
        return [
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
            ];
    }

    protected function getGhosts(array $ids)
    {
        if (count($ids) < 1) {
            return collect([]);
        }

        $ids = array_map(function ($id) {
            return (int)str_replace('product-', '', $id);
        }, $ids);

        return Product::with($this->productIncludes())->find($ids);
    }

}

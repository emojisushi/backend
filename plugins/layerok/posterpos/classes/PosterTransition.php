<?php

namespace Layerok\PosterPos\Classes;


use Illuminate\Support\Collection;
use Layerok\PosterPos\Models\HideProduct;
use Layerok\PosterPos\Models\PosterAccount;
use Layerok\PosterPos\Models\Spot;
use OFFLINE\Mall\Classes\Index\Index;
use OFFLINE\Mall\Classes\Observers\ProductObserver;
use OFFLINE\Mall\Models\Category;
use OFFLINE\Mall\Models\Currency;
use OFFLINE\Mall\Models\ImageSet;
use OFFLINE\Mall\Models\Product;
use OFFLINE\Mall\Models\ProductPrice;
use OFFLINE\Mall\Models\Property;
use OFFLINE\Mall\Models\PropertyGroup;
use OFFLINE\Mall\Models\PropertyValue;
use OFFLINE\Mall\Models\Variant;
use poster\src\PosterApi;
use System\Models\File;

class PosterTransition
{
    const EMOJI_BAR_ACCOUNT_NAME = 'emoji-bar2';
    // const EMOJI_BAR_ACCOUNT_NAME = 'demo';

    public function findProductByPosterId($poster_id, $poster_account)
    {
        return Product::whereHas('poster_accounts', function ($query) use ($poster_account, $poster_id) {
            $query->where([
                ['poster_id', $poster_id],
                ['poster_account_id', $poster_account->id],
            ]);
        })->first();
    }
    public function findCategoryByPosterId($poster_id, $poster_account)
    {
        return Category::whereHas('poster_accounts', function ($query) use ($poster_account, $poster_id) {
            $query->where([
                ['poster_id', $poster_id],
                ['poster_account_id', $poster_account->id],
            ]);
        })->first();
    }
    public function findPropertyByPosterId($poster_id, $poster_account)
    {
        return Property::whereHas('poster_accounts', function ($query) use ($poster_account, $poster_id) {
            $query->where([
                ['poster_id', $poster_id],
                ['poster_account_id', $poster_account->id],
            ]);
        })->first();
    }
    public function createProduct($value)
    {
        $emojibar = PosterAccount::where('account_name', self::EMOJI_BAR_ACCOUNT_NAME)->first();
        $product = $this->findProductByPosterId($value->product_id, $emojibar);

        if ($product) {
            // deleting product
            return;
        }

        $product = Product::create([
            'name' => (string)$value->product_name,
            'slug' => str_slug($value->product_name),
            'user_defined_id' => (int)$value->product_id,
            'weight'  => isset($value->out) ? (int)$value->out : 0,
            'allow_out_of_stock_purchases' => 1,
            'published' => (int)$value->hidden === 0 ? 1 : 0,
            'stock' => 9999999,
            'inventory_management_method' => 'single',
            'description_short' => implode(
                ', ',
                array_filter(array_map(fn($item) => $item->ingredient_name ?? null, $value->ingredients))
            )
        ]);

        $product->poster_accounts()->sync([
            $emojibar->id => [
                'poster_id' => (int)$value->product_id
            ]
        ]);

        if (isset($value->spots)) {
            foreach ($value->spots as $spot) {
                //                $spotModel = Spot::where('poster_id', $spot->spot_id)->first();
                //                if(!$spotModel) {
                //                    continue;
                //                }
                //                if(!(int)$spot->visible) {
                //                    HideProduct::create([
                //                        'spot_id' => $spotModel->id,
                //                        'product_id' => $product->id
                //                    ]);
                //                    $product->published = 0;
                //                    $product->save();
                //                } else {
                //                    HideProduct::where([
                //                        'spot_id' => $spotModel->id,
                //                        'product_id' => $product->id
                //                    ])->delete();
                //                }
            }
        }

        $rootCategory = Category::where('slug', RootCategory::SLUG_KEY)->first();

        // После создания товара нужно связать товар с категорией
        // 1. Найдем категорию к которой нужно привязать товар
        $category = $this->findCategoryByPosterId($value->menu_category_id, $emojibar);

        // 2. Привяжем категорию к товару
        if (!empty($category)) {
            $product->categories()->detach([$category['id'], $rootCategory['id']]);
            $product->categories()->sync([
                $category['id'] => ['sort_order' => (int)$value->sort_order],
                $rootCategory['id'] => ['sort_order' => (int)$value->sort_order],
            ]);
        }

        $currency = Currency::where('code', '=', 'UAH')->first();

        if (!$currency) {
            // Если не существует гривневой валюты, то создаем
            \Artisan::call('poster:create-uah-currency');
            $currency = Currency::where('code', '=', 'UAH')->first();
        }


        // Добавим цену товару
        // Нужно учесть две ситуации, когда мы имеем дела с товаров и когда с тех картой
        if (isset($value->modifications)) {
            $group = PropertyGroup::create([
                "name" => 'Модификаторы для товара ' . $value->product_name
            ]);
            // Товар
            $product->inventory_management_method = 'variant';
            $product->save();

            $options = [];

            // Создадим свойства, которые можно будет выбрать при покупке товара
            $property = Property::create([
                'name' => $value->product_name,
                'slug' => str_slug($value->product_name) . "_mod",
                'type' => 'dropdown',
            ]);
            foreach ($value->modifications as $mod) {
                $options[] = [
                    'value' => $mod->modificator_name,
                    'poster_id' => $mod->modificator_id
                ];

                // Создадим вариант для этого свойства
                $variant = Variant::create([
                    'name' => $value->product_name . " " . $mod->modificator_name,
                    'product_id' => $product['id'],
                    'stock' => 99999,
                    'published' => 1,
                    'allow_out_of_stock_purchases' => 1,
                ]);

                $variant->poster_accounts()->sync([
                    $emojibar->id => [
                        'poster_id' => $mod->modificator_id
                    ]
                ]);

                // Создадим цену для варианта
                ProductPrice::create([
                    'price' => substr($mod->spots[0]->price, 0, -2),
                    'product_id' => $product['id'],
                    'variant_id' => $variant['id'],
                    'currency_id' => $currency->id,
                ]);

                // Привяжем к варианту свойство
                PropertyValue::create([
                    'product_id' => $product['id'],
                    'variant_id' => $variant['id'],
                    'property_id' => $property['id'],
                    'value' => $mod->modificator_name
                ]);
            }

            $property->options = $options;
            $property->save();

            //Создадим дочернию категорию, чтобы ограничить кол-во менеямых свойств
            $mod_category = Category::create([
                'name'          => (string)$value->product_name,
                'parent_id'     => $category['id'],
                'slug'          => str_slug($value->product_name) . '_mod',
            ]);
            $product->categories()->attach([$mod_category['id'] => ['sort_order' => (int)$value->sort_order]]);

            //Привяжем группу свойств к категории товаров
            $mod_category->property_groups()->attach($group['id']);

            // Привяжем свойство к группе свойств модификаторов
            $property->property_groups()->attach($group->id, ['use_for_variants' => 1, 'filter_type' => 'set']);
        } else {
            // Тех. карта
            ProductPrice::create([
                'price' => (int)substr($value->price->{'1'}, 0, -2),
                'product_id' => $product['id'],
                'currency_id' => $currency->id,
            ]);

            if (isset($value->ingredients)) {
                foreach ($value->ingredients as $key => $i) {
                    $property = $this->findPropertyByPosterId($i->ingredient_id, $emojibar);


                    $name = $i->ingredient_name;

                    if (!$property) {
                        $group = PropertyGroup::where('name', 'unknown_ingredient_group')->first(); //must be created already

                        $property = Property::create([
                            'type' => 'checkbox',
                            'name' => $i->ingredient_name,
                        ]);

                        $property->poster_accounts()->sync([
                            $emojibar->id => [
                                'poster_id' => $i->ingredient_id
                            ]
                        ]);

                        $property->property_groups()->attach($group->id, ['use_for_variants' => 0, 'filter_type' => 'set']);
                        $category->property_groups()->detach($group->id);
                        $category->property_groups()->attach($group->id);
                    }

                    PropertyValue::create([
                        'product_id' => $product->id,
                        'property_id' => $property->id,
                        'value' => $name
                    ]);
                }

                $product->save();
            }
        }

        /*        if (!empty($value->photo)) {
            try {
                $url = env('POSTER_URL') . (string)$value->photo;

                $image_set = ImageSet::create([
                    'name' => $product['name'],
                    'is_main_set' => 1,
                    'product_id' => $product['id'],
                ]);

                $file = new File();
                $file->fromUrl($url);

                if (!isset($file)) {
                    return;
                }

                $image_set->images()->add($file);
            } catch (\Exception $e) {
                echo 'Caught error', $e->getMessage(), "\n";
            }

        }*/


        // Это какая-то переиндексация, короче если это не сделать, то товара не будет отображен пользователю
        $observer = new ProductObserver(app(Index::class));
        Product::where('id', '=', $product['id'])->orderBy('id')->with([
            'variants.prices.currency',
            'prices.currency',
            'property_values.property',
            'categories',
            'variants.prices.currency',
            'variants.property_values.property',
        ])->chunk(200, function (Collection $products) use ($observer) {
            $products->each(function (Product $product) use ($observer) {
                $observer->created($product);
            });
        });
    }


    public function deleteProduct($id)
    {
        $emojibar = PosterAccount::where('account_name', self::EMOJI_BAR_ACCOUNT_NAME)->first();
        $product = $this->findProductByPosterId($id, $emojibar);
        if ($product) {
            (new ProductObserver(app(Index::class)))->deleted($product);
            $product->delete();
        }
    }

    public function updateProduct($value)
    {
        $emojibar = PosterAccount::where('account_name', self::EMOJI_BAR_ACCOUNT_NAME)->first();
        $product = $this->findProductByPosterId($value->product_id, $emojibar);
        if (!$product) {
            // Если такого товара не существует, то выходим
            return;
        }

        $product->update([
            /*'name' => (string)$value->product_name,*/
            'weight'  => (int)$value->out,
            'description_short' => implode(
                ', ',
                array_filter(array_map(fn($item) => $item->ingredient_name ?? null, $value->ingredients))
            )
            /*'published' => (int)$value->spots[0]->visible,*/
        ]);

        $rootCategory = Category::where('slug', RootCategory::SLUG_KEY)->first();

        // 1. Найдем категорию к которой нужно привязать товар
        $category = $this->findCategoryByPosterId($value->menu_category_id, $emojibar);

        // 2. Привяжем категорию к товару
        if (!empty($category)) {
            $product->categories()->detach();
            $product->categories()->sync([$category['id'], $rootCategory['id']]);
        }


        // Добавим цену товару
        // Нужно учесть две ситуации, когда мы имеем дела с товаров и когда с тех картой
        if (isset($value->modifications)) {
            //todo обновление товара
        } else {
            // Тех. карта
            $price = ProductPrice::where('product_id', '=', $product['id'])->first();
            if ($price) {
                $price->update([
                    'price' => (int)substr($value->price->{'1'}, 0, -2),
                ]);
            }
        }


        /*        $image_sets = ImageSet::where('product_id', '=', $product['id'])->get();
        if ($image_sets) {
            $files = File::whereIn('attachment_id', $image_sets->pluck('id'))->get();
            if ($files) {
                foreach ($files as $file) {
                    $file->delete();
                }
            }
            foreach ($image_sets as $set) {
                $set->delete();
            }

        }*/

        /*        if (!empty($value->photo)) {

            $url = env('POSTER_URL') . (string)$value->photo;

            $image_set = ImageSet::create([
                'name' => $product['name'],
                'is_main_set' => 1,
                'product_id' => $product['id'],
            ]);

            $file = new File;
            $file->fromUrl($url);

            if (!isset($file)) {
                return;
            }

            $image_set->images()->add($file);
        }*/




        // Реиндексация
        $observer = new ProductObserver(app(Index::class));
        Product::where('id', '=', $product['id'])->orderBy('id')->with([
            'variants.prices.currency',
            'prices.currency',
            'property_values.property',
            'categories',
            'variants.prices.currency',
            'variants.property_values.property',
        ])->chunk(200, function (Collection $products) use ($observer) {
            $products->each(function (Product $product) use ($observer) {
                $observer->updated($product);
            });
        });
    }
}

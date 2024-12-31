<?php
namespace Layerok\PosterPos\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Layerok\PosterPos\Classes\RootCategory;
use Layerok\PosterPos\Models\City;
use Layerok\PosterPos\Models\District;
use Layerok\PosterPos\Models\HideCategory;
use Layerok\PosterPos\Models\HideProduct;
use Layerok\PosterPos\Models\PosterAccount;
use Layerok\PosterPos\Models\Spot;
use Layerok\PosterPos\Models\Tablet;
use Layerok\Telegram\Models\Bot;
use Layerok\Telegram\Models\Chat;
use OFFLINE\Mall\Classes\Index\Index;
use OFFLINE\Mall\Classes\Index\Noop;
use OFFLINE\Mall\Classes\Index\ProductEntry;
use OFFLINE\Mall\Classes\Index\VariantEntry;
use OFFLINE\Mall\Models\Address;
use OFFLINE\Mall\Models\Category;
use OFFLINE\Mall\Models\Currency;
use OFFLINE\Mall\Models\Customer;
use OFFLINE\Mall\Models\ImageSet;
use OFFLINE\Mall\Models\PaymentMethod;
use OFFLINE\Mall\Models\Price;
use OFFLINE\Mall\Models\Product;
use OFFLINE\Mall\Models\ProductPrice;
use OFFLINE\Mall\Models\Property;
use OFFLINE\Mall\Models\PropertyGroup;
use OFFLINE\Mall\Models\PropertyValue;
use OFFLINE\Mall\Models\ShippingMethod;
use OFFLINE\Mall\Models\User;
use OFFLINE\Mall\Models\Variant;
use poster\src\PosterApi;
use RainLab\Location\Models\Country;
use Symfony\Component\Console\Input\InputOption;
use DB;
use System\Models\File;

class ImportData extends Command {
    protected $name = 'poster:import';
    protected $description = 'Import Layerok.PosterPos data';

    public function handle()
    {
        $question = 'All existing OFFLINE.Mall data will be erased. Do you want to continue?';
        if ( ! $this->option('force') && ! $this->output->confirm($question, false)) {
            return 0;
        }

        // Use a Noop-Indexer so no unnecessary queries are run during seeding.
        // the index will be re-built once everything is done.
        $originalIndex = app(Index::class);
        app()->bind(Index::class, function () {
            return new Noop();
        });

        $this->output->writeln('Resetting plugin data...');

        // cleanup
        ShippingMethod::truncate();
        PaymentMethod::truncate();
        Price::truncate();
        ShippingMethod::truncate();
        User::truncate();
        Customer::truncate();
        Product::truncate();
        Variant::truncate();
        HideProduct::truncate();
        ProductPrice::truncate();
        Currency::truncate();
        ImageSet::truncate();
        Spot::truncate();
        HideCategory::truncate();
        HideProduct::truncate();
        Tablet::truncate();
        PropertyGroup::truncate();
        Property::truncate();
        PropertyValue::truncate();
        Category::truncate();
        HideCategory::truncate();
        City::truncate();
        Bot::truncate();
        Chat::truncate();
        District::truncate();

        DB::table('offline_mall_prices')->truncate();

        DB::table('system_files')
            ->where('attachment_type', 'LIKE', 'OFFLINE%Mall%')
            ->orWhere('attachment_type', 'LIKE', 'mall.%')
            ->delete();

        DB::table('offline_mall_category_product')
            ->truncate();
        DB::table('offline_mall_category_property_group')
            ->truncate();
        DB::table('offline_mall_property_property_group')
            ->truncate(); // detach properties from property group
        DB::table('offline_mall_category_property_group')
            ->truncate(); // detach categories from property group

        Artisan::call('cache:clear');

        PosterApi::init(config('poster'));

        $index = app(Index::class);
        $index->drop(ProductEntry::INDEX);
        $index->drop(VariantEntry::INDEX);

        $this->output->newLine();
        $this->output->writeln('Creating cities...');
        $this->output->newLine();

        $bot = Bot::create([
            'token' => '5009009410:AAF1mz_UhdBxkgQ4GxTO7G2XVqxBwv-XYTU',
            'name' => "Test bot",
        ]);


        $chat = Chat::create([
            'internal_id' => '-587888839',
            'name' => "Test chat",
        ]);

        $odesaCity = new City([
            'name' => 'Odessa',
            'slug' => 'odesa',
            'phones' => '+38 (093) 366 28 69, +38 (068) 303 45 51'
        ]);
        $odesaCity->save();

        $chornomorskCity = new City([
            'name' => 'Chornomorsk',
            'slug' => 'chorno',
        ]);

        $chornomorskCity->save();


        $this->output->newLine();
        $this->output->writeln('Creating districts...');
        $this->output->newLine();

        $centerDistrict = new District([
            'name' => 'Центр',
            'city_id' => $odesaCity->id
        ]);

        $centerDistrict->save();

        $this->output->newLine();
        $this->output->writeln('Create uah currency...');
        $this->output->newLine();

        $uaCurrency = Currency::create([
            'code'     => 'UAH',
            'format'   => '{{ price|number_format(0, ".", ",") }} {{ currency.symbol }} ',
            'decimals' => 2,
            'is_default' => true,
            'symbol'   => '₴',
            'rate'     => 1,
        ]);

        $this->output->newLine();
        $this->output->writeln('Create payment methods...');
        $this->output->newLine();

        $this->output->progressStart(2);

        (new PaymentMethod([
            'name' => 'Готівкою',
            'payment_provider' => 'offline',
            'sort_order' => 1,
            'code' => 'cash'
        ]))->save();

        $this->output->progressAdvance();

        (new PaymentMethod([
            'name' => 'Картою',
            'payment_provider' => 'offline',
            'sort_order' => 1,
            'code' => 'card'
        ]))->save();

        $this->output->progressAdvance();
        $this->output->progressFinish();

        $this->output->newLine();
        $this->output->writeln('Creating shipping methods...');
        $this->output->newLine();

        $this->output->progressStart(2);

        $method = new ShippingMethod([
            'name' => 'Самовивіз',
            'code' => 'takeaway',
            'sort_order' => 1
        ]);

        $method->save();

        (new Price([
            'price'          => 0,
            'currency_id'    => $uaCurrency->id,
            'priceable_type' => ShippingMethod::MORPH_KEY,
            'priceable_id'   => $method->id,
        ]))->save();
        $this->output->progressAdvance();


        $method = new ShippingMethod([
            'name' => "Кур'єр",
            'code' => 'courier',
            'sort_order' => 1
        ]);

        $method->save();

        (new Price([
            'price'          => 0,
            'currency_id'    => $uaCurrency->id,
            'priceable_type' => ShippingMethod::MORPH_KEY,
            'priceable_id'   => $method->id,
        ]))->save();

        $this->output->progressAdvance();
        $this->output->progressFinish();

        $account = PosterAccount::create(config('poster'));

        $this->output->newLine();
        $this->output->writeln('Creating spots...');
        $this->output->newLine();

        $records = (object)PosterApi::access()->getSpots();

        $count = count($records->response);

        $this->output->progressStart($count);

        foreach ($records->response as $record) {
            $pass = "qweasdqweaasd";

            $user = User::create([
                'name' => '!!',
                'surname' => $record->spot_name,
                'email' => str_slug($record->spot_name) . "@email.com",
                'username' => str_slug($record->spot_name),
                'password' => $pass,
                'password_confirmation' => $pass
            ]);

            $customer = new Customer([
                'firstname' => $user->name,
                'lastname' => $user->surname,
                'user_id' => $user->id
            ]);

            $customer->save();

            $address = new Address([
                'name' => $record->spot_name,
                'lines' => $record->spot_adress || $record->spot_name,
                'customer_id' => $customer->id,
                'zip' => '65125',
                'city' => 'Одеса',
                'country_id' => Country::where('code', 'UA')->first()->id
            ]);

            $address->save();

            // todo: save address as string

            Spot::create([
                'address_id' => $address->id,
                'name' => $record->spot_name,
                'chat_id' => $chat->id,
                'bot_id' => $bot->id,
                'poster_account_id' => $account->id,
                'phones' => '+38 (093) 366 28 69, +38 (068) 303 45 51',
                'poster_id' => $record->spot_id,
                'district_id' => $centerDistrict->id,
                'city_id' => $odesaCity->id,
            ]);

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->output->newLine();
        $this->output->writeln('Creating tablets...');
        $this->output->newLine();

        $records = (object)PosterApi::access()->getTablets();

        $count = count($records->response);

        $this->output->progressStart($count);

        foreach ($records->response as $record) {
            $tablet = Tablet::create([
                'name' => $record->tablet_name,
                'poster_account_id' => $account->id,
                'tablet_id' => $record->tablet_id
            ]);

            $spot = Spot::where('poster_id', $record->spot_id)->first();

            if($spot) {
                $spot->tablet_id = $tablet->id;
                $spot->save();
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();


        $this->output->newLine();
        $this->output->writeln('Creating categories...');
        $this->output->newLine();

        $categories = (object)PosterApi::menu()->getCategories();

        $this->output->progressStart(count($categories->response));

        $root = Category::create([
            'name' => 'Меню',
            'slug'          => RootCategory::SLUG_KEY,
            'sort_order'    => 0,
        ]);
        $root->poster_accounts()->attach($root, [
            'poster_id' => 0
        ]);

        foreach ($categories->response as $category) {
            $poster_id = $category->category_id;
            $slug = $category->category_tag ?? str_slug($category->category_name);

            $parent = Category::whereHas('poster_accounts', function($query) use($category) {
                $query->where('poster_id', $category->parent_category);
            })->first();

            $publish = $category->category_hidden === '0' ? 1: 0;

            $categoryModel = Category::create([
                'name'          => (string)$category->category_name,
                'slug'          => $slug,
                'sort_order'    => (int)$category->sort_order,
                'parent_id'     => $parent->id,
                'published'     => $publish
            ]);

            $categoryModel->poster_accounts()->attach($account, [
                'poster_id' => (int)$poster_id
            ]);


            if(!isset($category->visible)) {
                $this->output->progressAdvance();
                continue;
            }

            foreach($category->visible as $spot) {
                $spotModel = Spot::whereHas('posterAccount', function($query) use($spot) {
                    $query->where('poster_id', $spot->spot_id);
                })->first();

                if(!$spotModel || $spot->visible) {
                    $this->output->progressAdvance();
                    continue;
                }

                HideCategory::create([
                    'spot_id' => $spotModel->id,
                    'category_id' => $categoryModel->id
                ]);
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->output->newLine();
        $this->output->writeln('Creating products...');
        $this->output->newLine();

        $products = (object)PosterApi::menu()->getProducts();

        $count = count($products->response);

        $this->output->progressStart($count);

        foreach ($products->response as $value) {
            $this->createProduct($value, $account);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        app()->bind(Index::class, function () use ($originalIndex) {
            return $originalIndex;
        });

        Artisan::call('mall:index', ['--force' => true]);

        $this->output->success('All done!');
    }

    public function createProduct($value, PosterAccount $account) {
        $product = Product::create([
            'name' => (string)$value->product_name,
            'slug' => str_slug($value->product_name),
            'user_defined_id' => (int)$value->product_id,
            'weight'  => isset($value->out) ? (int)$value->out: 0,
            'allow_out_of_stock_purchases' => 1,
            'published' => (int)$value->hidden === 0 ? 1: 0,
            'stock' => 9999999,
            'inventory_management_method' => 'single'
        ]);

        $product->poster_accounts()->attach($account, [
            'poster_id' => (int)$value->product_id,
        ]);

        foreach(($value->spots ?? []) as $spot) {
            $spotModel = Spot::whereHas('posterAccount', function($query) use($spot) {
                $query->where('poster_id', $spot->spot_id);
            })->first();
            if(!$spotModel) {
                continue;
            }
            if(!(int)$spot->visible) {
                HideProduct::create([
                    'spot_id' => $spotModel->id,
                    'product_id' => $product->id
                ]);
                $product->published = 0;
                $product->save();
            }
        }

        $rootCategory = Category::where('slug', RootCategory::SLUG_KEY)->first();

        $category = Category::whereHas('poster_accounts', function($query) use($value) {
            $query->where('poster_id', '=', $value->menu_category_id);
        })->first();

        if ($category) {
            $product->categories()->sync([
                $category['id'] => ['sort_order' => (int)$value->sort_order],
                $rootCategory['id'] => ['sort_order' => (int)$value->sort_order],
            ]);
        }

        $currency = Currency::where('code', '=', 'UAH')->first();

         if (!empty($value->photo)) {
             $url = $account->url . (string)$value->photo;

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
        }

        if (isset($value->modifications)) {
            $group = PropertyGroup::create([
                "name" =>'Модификаторы для товара ' . $value->product_name
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

                $variant->poster_accounts()->attach($account, [
                    'poster_id' => $mod->modificator_id
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

            $mod_category = Category::create([
                'name'          => (string)$value->product_name,
                'parent_id'     => $category['id'],
                'slug'          => str_slug($value->product_name) . '_mod',
            ]);
            $product->categories()->attach([$mod_category['id'] => ['sort_order' => (int)$value->sort_order]]);

            $mod_category->property_groups()->attach($group['id']);

            $property->property_groups()->attach($group->id, ['use_for_variants' => 1, 'filter_type'=>'set']);
            return;

        }

        // Тех. карта
        ProductPrice::create([
            'price' => (int)substr($value->price->{'1'}, 0, -2),
            'product_id' => $product['id'],
            'currency_id' => $currency->id,
        ]);

        $description = collect(($value->ingredients ?? []))
            ->map(fn($ingredient) => $ingredient->ingredient_name)
            ->join(', ');

        $product->description_short = $description;

        $product->save();
    }

    protected function getArguments()
    {
        return [];
    }

    protected function getOptions()
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Don\'t ask before deleting the data.', null],
        ];
    }

}

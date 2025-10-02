<?php namespace Layerok\PosterPos;

use Backend;
use File;
use Illuminate\Support\Facades\Event;
use Layerok\PosterPos\Classes\Customer\AuthManager;
use Layerok\PosterPos\Console\ImportData;
use Layerok\PosterPos\Models\Cart;
use Layerok\PosterPos\Models\City;
use Layerok\PosterPos\Models\PosterAccount;
use Layerok\PosterPos\Models\Spot;
use Layerok\PosterPos\Models\Wishlist;
use Maatwebsite\Excel\ExcelServiceProvider;
use Maatwebsite\Excel\Facades\Excel;
use OFFLINE\Mall\Controllers\Categories;
use OFFLINE\Mall\Controllers\Products;

use OFFLINE\Mall\Controllers\ShippingMethods;
use OFFLINE\Mall\Models\Category;
use OFFLINE\Mall\Models\Order;
use OFFLINE\Mall\Models\Product;
use OFFLINE\Mall\Models\Property;
use OFFLINE\Mall\Models\PropertyGroup;
use OFFLINE\Mall\Models\ShippingMethod;
use OFFLINE\Mall\Models\Variant;
use Backend\Widgets\Form;

use System\Classes\PluginBase;
use App;

/**
 * PosterPos Plugin Information File
 */
class Plugin extends PluginBase
{

    public $require = ['OFFLINE.Mall', 'Layerok.Telegram'];
    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'PosterPos',
            'description' => 'No description provided yet...',
            'author'      => 'Layerok',
            'icon'        => 'icon-leaf'
        ];
    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConsoleCommand('poster.import', ImportData::class);
        App::register(ExcelServiceProvider::class);
        App::registerClassAlias('Excel',  Excel::class);
    }

    public function posterAccountsRelationConfig() {
        // todo: move to yaml file
        return [
            'label'   => 'Poster account',
            'view' => [
                'list' => [
                    'columns' => [
                        'account_name' => [
                            'label' => 'Account name',
                        ],
                        'pivot[poster_id]' => [
                            'label' => 'Poster ID'
                        ]
                    ]
                ]
            ],
            'manage' => [
                'list' => [
                    'columns' => [
                        'account_name' => [
                            'label' => 'Account Name'
                        ]
                    ]
                ]
            ],
            'pivot' => [
                'form' => [
                    'fields' => [
                        'pivot[poster_id]' => [
                            'label' => 'Poster ID',
                            'type' => 'text'
                        ]
                    ]
                ]
            ]
        ];
    }

    public function posterAccountModelRelation() {
        return [
            PosterAccount::class,
            'table'    => 'layerok_posterpos_poster_accountable',
            'name' => 'poster_accountable',
            'otherKey' => 'poster_account_id',
            'pivot' => ['poster_id']
        ];
    }

    /**
     * Boot method, called right before the request route.
     *
     * @return array
     */
    public function boot()
    {

        Product::deleted(function($model) {
            $model->poster_accounts()->sync([]);
        });

        Variant::deleted(function($model) {
            $model->poster_accounts()->sync([]);
        });

        PropertyGroup::deleted(function($model) {
            $model->poster_accounts()->sync([]);
        });

        Property::deleted(function($model) {
            $model->poster_accounts()->sync([]);
        });

        Category::deleted(function($model) {
            $model->poster_accounts()->sync([]);
        });

        // Use custom user model
        App::singleton('user.auth', function () {
            return AuthManager::instance();
        });

        Event::listen('backend.form.extendFields', function (\Backend\Widgets\Form $widget) {

            if (!$widget->model instanceof Product) {
                return;
            }

            if(!$widget->model->exists) {
                $widget->removeField('user_defined_id');
                $widget->removeField('meta_title');
                $widget->removeField('meta_description');
                $widget->removeField('is_virtual');
                return;
            }

            if($widget->model->exists) {
                $widget->removeTab('offline.mall::lang.common.accessories');
                $widget->removeTab('offline.mall::lang.common.seo');
                $widget->removeTab('offline.mall::lang.common.reviews');
                $widget->removeTab('offline.mall::lang.common.taxes');
                $widget->removeTab('offline.mall::lang.common.properties');
                $widget->removeTab('offline.mall::lang.product.details');
                $widget->removeTab('offline.mall::lang.common.cart');
                //$widget->removeTab('offline.mall::lang.common.shipping');

                /** @var \Backend\Classes\FormField $weightField */
                $weightField = $widget->getField('weight');
                $weightField->span('left');
                $weightField->tab('offline.mall::lang.product.description');


                $widget->removeField('downloads');
                $widget->removeField('links');
                $widget->removeField('embeds');
                $widget->removeField('shippable');
                $widget->removeField('width');
                $widget->removeField('height');
                $widget->removeField('length');
                $widget->removeField('gtin');
                $widget->removeField('mpn');
                $widget->removeField('brand');
                $widget->removeField('description');
                $widget->removeField('user_defined_id');
            }

        });

        // extend property
        Event::listen('system.extendConfigFile', function ( $path, $config) {
            if ($path === '/plugins/offline/mall/models/property/fields_pivot.yaml') {
                $config['fields']['options']['form']['fields']['poster_id'] = [
                    'label' => 'Poster ID',
                    'type' => 'relation',
                    'span' => 'left'
                ];
                $config['fields']['options']['form']['fields']['value']['span'] = 'right';
            }

            return $config;
        });


        // extend property group
        Event::listen('system.extendConfigFile', function ( $path, $config) {
            if ($path === '/plugins/offline/mall/models/propertygroup/fields.yaml') {
                $config['fields']['poster_accounts'] = [
                    'label' => 'Poster accounts',
                    'type' => 'relation',
                ];
            }

            if($path === '/plugins/offline/mall/controllers/propertygroups/config_relation.yaml') {
                $config['poster_accounts'] = $this->posterAccountsRelationConfig();
            }

            return $config;
        });

        // extend category
        Event::listen('system.extendConfigFile', function ( $path, $config) {
            if ($path === '/plugins/offline/mall/models/category/fields.yaml') {
                $config['fields']['poster_accounts'] = [
                    'label' => 'Poster accounts',
                    'type' => 'relation',
                ];
                $config['fields']['hide_categories_in_spot'] = [
                    'label' => 'Скрыть категорию в заведении',
                    'type' => 'relation',
                ];
            }

            if($path === '/plugins/offline/mall/controllers/categories/config_relation.yaml') {
                $config['poster_accounts'] = $this->posterAccountsRelationConfig();
            }

            return $config;
        });

        // extend shipping method
        Event::listen('system.extendConfigFile', function ( $path, $config) {
            if ($path === '/plugins/offline/mall/models/shippingmethod/fields.yaml') {
                $config['fields']['code'] = [
                    'label' => 'Code',
                    'span' => 'auto'
                ];
            }

            if ($path === '/plugins/offline/mall/models/shippingmethod/columns.yaml') {
                $config['columns']['code'] = [
                    'label' => 'Code',
                ];
            }

            return $config;
        });

        // extend product
        Event::listen('system.extendConfigFile', function ( $path, $config) {
            if($path === '/plugins/offline/mall/controllers/products/config_relation.yaml') {
                $config['poster_accounts'] = $this->posterAccountsRelationConfig();
                return $config;
            }

            if ($path === '/plugins/offline/mall/models/product/columns.yaml') {
                return $config;
            }

            if ($path === '/plugins/offline/mall/models/product/fields_create.yaml') {
                return $config;
            }

            if($path === '/plugins/offline/mall/models/product/fields_edit.yaml') {
//                $config['tabs']['fields']['hide_products_in_spot'] = [
//                    'type' => 'relation',
//                    'tab' => 'offline.mall::lang.product.general'
//                ];
                $config['tabs']['fields']['poster_accounts'] = [
                    'type' => 'relation',
                    'tab' => 'offline.mall::lang.product.general'
                ];
            }

            return $config;
        });


        Event::listen('backend.form.extendFields', function (Form $widget) {
            if ($widget->model instanceof Category) {
                $widget->addFields([
                    'published' => [
                        'label' => 'layerok.posterpos::lang.extend.published',
                        'span' => 'left',
                        'type' => 'switch'
                    ]
                ]);
                $widget->addFields([
                    'hidden_categories_in_city' => [
                        'label' => 'Hide category in city',
                        'type' => 'relation'
                    ]
                ]);
            }

            if ($widget->model instanceof ShippingMethod) {
                $widget->addFields([
                    'code' => [
                        'label' => 'Code',
                        'span' => 'auto',
                        'type' => 'text',
                        'tab' => 'offline.mall::lang.common.general'
                    ]
                ], Backend\Classes\FormTabs::SECTION_PRIMARY);
            }
        });

        // Extend all backend list usage
        Event::listen('backend.list.extendColumns', function (Backend\Widgets\Lists $widget) {
            if ($widget->model instanceof Category && $widget->getController() instanceof Categories) {
                $widget->addColumns([
                    'published' => [
                        'label' => 'layerok.posterpos::lang.extend.published',
                        'type' => 'partial',
                        'path' => '$/offline/mall/models/product/_published.htm',
                        'sortable' => true
                    ]
                ]);
            }

            if ($widget->model instanceof ShippingMethod && $widget->getController() instanceof ShippingMethods) {
                $widget->addColumns([
                    'code' => [
                        'label' => 'Code',
                        'type' => 'text',
                        'sortable' => true,
                    ]
                ]);
            }
        });


        Event::listen('backend.page.beforeDisplay', function ( $backendController, $action, $params) {
            // workaround, trick controller to look for the template outside the self plugin folder
            if($backendController instanceof Products && $action === 'export') {
                $backendController->addViewPath(File::normalizePath("plugins\\layerok\\posterpos\\controllers\\products"));
            }
       });

        ShippingMethod::extend((function($model) {
            $model->fillable[] = 'code';
        }));

        Category::extend(function($model){
            $model->fillable[] = 'published';

            $model->casts['published'] = 'boolean';
            $model->rules['published'] = 'boolean';

            $model->belongsToMany['hide_categories_in_spot'] = [
                Spot::class,
                'table'    => 'layerok_posterpos_hide_categories_in_spot',
                'key'      => 'category_id',
                'otherKey' => 'spot_id',
            ];

            $model->belongsToMany['hidden_categories_in_city'] = [
                City::class,
                'table'    => 'layerok_posterpos_hidden_categories_in_city',
                'key'      => 'category_id',
                'otherKey' => 'city_id',
            ];

            $model->morphToMany['poster_accounts'] = $this->posterAccountModelRelation();
        });

        Product::extend(function($model){
            $model->belongsToMany['hide_products_in_spot'] = [
                Spot::class,
                'table'    => 'layerok_posterpos_hide_products_in_spot',
                'key'      => 'product_id',
                'otherKey' => 'spot_id',
            ];

            $model->morphToMany['poster_accounts'] = $this->posterAccountModelRelation();
        });

        Variant::extend(function($model){
            //$model->addFillable('poster_id');
            $model->morphToMany['poster_accounts'] = $this->posterAccountModelRelation();
        });

        Property::extend(function($model){
            $model->fillable[] = 'poster_type'; // dish or product

            $model->morphToMany['poster_accounts'] = $this->posterAccountModelRelation();
        });

        PropertyGroup::extend(function($model){
            $model->morphToMany['poster_accounts'] = $this->posterAccountModelRelation();
        });


        Cart::extend(function ($model) {
            $model->fillable[] = 'spot_id';
            $model->hasOne['spot'] = Spot::class;
        });

        Order::extend(function ($model) {
            $model->hasOne['spot'] = Spot::class;
        });

        Wishlist::extend(function ($model) {
            $model->fillable[] = 'spot_id';
            $model->hasOne['spot'] = Spot::class;
        });

    }



    /**
     * Registers back-end navigation items for this plugin.
     *
     * @return array
     */
    public function registerNavigation()
    {
        return [
            'posterpos' => [
                'label'       => 'PosterPos',
                'url'         => Backend::url('layerok/posterpos/mycontroller'),
                'icon'        => 'icon-shopping-bag',
                'permissions' => ['layerok.posterpos.*'],
                'order'       => 500,
                'sideMenu' => [
                    'posterpos-banners' => [
                        'label' => "Banners",
                        'icon'   => 'icon-text-image',
                        'url'    => Backend::url('layerok/posterpos/banner'),
                    ],
                    'posterpos-spots' => [
                        'label' => "Spots",
                        'icon'   => 'icon-map-marker',
                        'url'    => Backend::url('layerok/posterpos/spot'),
                    ],
                    'posterpos-cities' => [
                        'label' => "Cities",
                        'icon'   => 'icon-globe',
                        'url'    => Backend::url('layerok/posterpos/cities'),
                    ],
                    'posterpos-accounts' => [
                        'label' => "Poster Accounts",
                        'icon'   => 'icon-globe',
                        'url'    => Backend::url('layerok/posterpos/posteraccounts'),
                    ],
                    'posterpos-districts' => [
                        'label' => "Districts",
                        'icon'   => 'icon-globe',
                        'url'    => Backend::url('layerok/posterpos/districts'),
                    ],
                    'posterpos-addresses' => [
                        'label' => "Addresses",
                        'icon'   => 'icon-globe',
                        'url'    => Backend::url('layerok/posterpos/addresses'),
                    ],
                    'posterpos-tablets' => [
                        'label' => "Tablets",
                        'icon'   => 'icon-tablet',
                        'url'    => Backend::url('layerok/posterpos/tablet'),
                    ],
                    'posterpos-export' => [
                        'label' => 'Export',
                        'icon'   => 'icon-download',
                        'url' => Backend::url('layerok/posterpos/export')
                    ],
                    'posterpos-import' => [
                        'label' => 'Import',
                        'icon' => 'icon-upload',
                        'url' => Backend::url('layerok/posterpos/import')
                    ],
                    'posterpos-sync' => [
                        'label' => 'Sync',
                        'icon' => 'icon-upload',
                        'url' => Backend::url('layerok/posterpos/sync')
                    ],
                    'posterpos-diagnostics' => [
                        'label' => 'Diagnostics',
                        'icon' => 'icon-cog',
                        'url' => Backend::url('layerok/posterpos/diagnostics')
                    ]
                ]
            ],
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'Spot settings',
                'description' => 'Manage spot settings.',
                'category' => 'Spot',
                'icon' => 'icon-cog',
                'class' => \Layerok\PosterPos\Models\Settings::class,
                'order' => 500,
                'keywords' => 'spot',
            ],
            'wayforpay-settings' => [
                'label' => 'Wayforpay settings',
                'description' => 'Manage wayforpay settings.',
                'category' => 'Wayforpay',
                'icon' => 'icon-cog',
                'class' => \Layerok\PosterPos\Models\WayforpaySettings::class,
                'order' => 500,
                'keywords' => 'wayforpay',
            ]
        ];
    }


}

<?php
namespace Layerok\RestApi;

use Layerok\Restapi\Classes\Sort\Category;
use Layerok\RestApi\Classes\Customer\DefaultSignUpHandler;
use OFFLINE\Mall\Classes\CategoryFilter\SortOrder\Bestseller;
use OFFLINE\Mall\Classes\Customer\SignUpHandler;
use System\Classes\PluginBase;
use Config;
use Fruitcake\Cors\HandleCors;
use Fruitcake\Cors\CorsServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Event;
use RainLab\User\Models\User;

/**
 * RestApi Plugin Information File
 */
class Plugin extends PluginBase
{
    public $require = ['OFFLINE.Mall', 'Layerok.BaseCode', 'ReaZzon.JWTAuth'];

    public function pluginDetails()
    {
        return [
            'name' => 'RestApi',
            'description' => 'No description provided yet...',
            'author' => 'Layerok',
            'icon' => 'icon-leaf'
        ];
    }

    public function register()
    {

    }

    public function boot()
    {
        User::extend(function ($model) {
            $model->addFillable(['bonuses']);
        });
        Event::listen('offline.mall.extendSortOrder', function () {
            return [
                'default' => new Bestseller(),
                'category' => new Category(),
            ];
        });

        $this->app->bind(SignUpHandler::class, function () {
            return new DefaultSignUpHandler();
        });

        Config::set('cors', Config::get('layerok.restapi::cors'));

        $this->app->register(CorsServiceProvider::class);

        $this->app[Kernel::class]->pushMiddleware(HandleCors::class);
    }
    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'Bonus settings',
                'description' => 'Manage bonus settings.',
                'category' => 'Bonus',
                'icon' => 'icon-cog',
                'class' => \Layerok\RestApi\Models\Settings::class,
                'order' => 500,
                'keywords' => 'bonus',
            ],
            'app_version_settings' => [
                'label' => 'Allowed Mobile Versions Settings',
                'description' => 'Manage app versions.',
                'category' => 'Mobile App',
                'icon' => 'icon-cog',
                'class' => \Layerok\RestApi\Models\AppVersionSettings::class,
                'order' => 510,
                'keywords' => 'version',
            ],
            'contacts_settings' => [
                'label' => 'Change Contacts links',
                'description' => 'Change Contacts links.',
                'category' => 'Contacts',
                'icon' => 'icon-cog',
                'class' => \Layerok\RestApi\Models\ContactsSettings::class,
                'order' => 510,
                'keywords' => 'contact',
            ]
        ];
    }
}

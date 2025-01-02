<?php

use Layerok\Restapi\Http\Controllers\CatalogController;
use Layerok\Restapi\Http\Controllers\CheckoutController;
use Layerok\Restapi\Http\Controllers\CustomerController;
use Layerok\Restapi\Http\Controllers\SpotController;
use Layerok\Restapi\Http\Controllers\UserController;
use Layerok\Restapi\Http\Middleware\ExceptionsMiddleware;
use \Layerok\Restapi\Http\Controllers\ProductController;
use \Layerok\Restapi\Http\Controllers\CategoryController;
use \Layerok\Restapi\Http\Controllers\CartController;
use \Layerok\Restapi\Http\Middleware\CustomSession;
use \Layerok\Restapi\Http\Controllers\ShippingMethodController;
use \Layerok\Restapi\Http\Controllers\PaymentMethodController;
use \Layerok\Restapi\Http\Controllers\WishlistController;
use \Layerok\Restapi\Http\Controllers\IngredientController;
use \Layerok\Restapi\Http\Controllers\OrderController;
use Layerok\Restapi\Http\Controllers\ActivationController;
use Layerok\Restapi\Http\Controllers\AuthController;
use Layerok\Restapi\Http\Controllers\RefreshController;
use \Layerok\Restapi\Http\Controllers\CityController;
use \Layerok\Restapi\Http\Controllers\PromotionController;
use \Layerok\Restapi\Http\Controllers\BannerController;
use \Layerok\Restapi\Http\Controllers\OrderControllerV2;
use \Fruitcake\Cors\HandleCors;

Route::group([
    'middleware' => [
        ExceptionsMiddleware::class,
        CustomSession::class,
        HandleCors::class,
    ],
    'prefix' => 'api'
], function () {
    Route::get('checkout', [CheckoutController::class, 'index']);
    Route::get('catalog', [CatalogController::class, 'index']);
    Route::get('products', [ProductController::class, 'fetch']);
    Route::get('categories', [CategoryController::class, 'fetch']);
    Route::get('spots', [SpotController::class, 'fetch']);
    Route::get('spot', [SpotController::class, 'one']);
    Route::get('spot-main', [SpotController::class, 'main']);
    Route::get('cities', [CityController::class, 'fetch']);
    Route::get('city', [CityController::class, 'one']);
    Route::get('city-main', [CityController::class, 'main']);
    Route::get('shipping', [ShippingMethodController::class, 'all']);
    Route::get('payments', [PaymentMethodController::class, 'all']);
    Route::get('banners', [BannerController::class, 'all']);
    Route::get('ingredients', [IngredientController::class, 'all']);
    Route::prefix('order')->group(function() {
        Route::post('place', [OrderController::class, 'place']);
    });
    Route::prefix('order/v2')->group(function() {
        Route::post('place', [OrderControllerV2::class, 'place']);
    });

    Route::prefix('cart')->group(function() {
        Route::get('products', [CartController::class, 'all']);
        Route::post('add', [CartController::class, 'add']);
        Route::post('remove', [CartController::class, 'remove']);
        Route::post('clear', [CartController::class, 'clear']);
    });

    Route::prefix('wishlist')->group(function() {
        Route::get('add', [WishlistController::class, 'add']);
        Route::get('list', [WishlistController::class, 'list']);
    });

    Route::prefix('auth')->group(function() {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::post('restore-password', [AuthController::class, 'restorePassword']);


        Route::post('refresh', RefreshController::class);
        Route::post('activate', ActivationController::class);
    });

    Route::prefix('clients')->group(function() {
        Route::get('promotions', [PromotionController::class, 'list']);
    });

    Route::group([
        'middleware' => [
            \ReaZzon\JWTAuth\Http\Middlewares\ResolveUser::class
        ]
    ], function() {
        Route::get('user', [UserController::class, 'fetch']);
        Route::post('user', [UserController::class, 'save']);
        Route::post('user/password', [UserController::class, 'updatePassword']);
        Route::get('user/addresses', [UserController::class, 'addresses']);
        Route::post('user/address', [UserController::class, 'createAddress']);
        Route::delete('user/address', [UserController::class, 'deleteAddress']);
        Route::post('user/address/default', [UserController::class, 'setDefaultAddress']);

        Route::post('user/customer', [CustomerController::class, 'save']);
    });

    Route::post('/log', function() {
       $content =  \Illuminate\Support\Facades\Request::getContent();
       \Illuminate\Support\Facades\Log::error($content);
    });

});

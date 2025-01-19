<?php

namespace Layerok\Restapi\Http\Controllers;

use Composer\Semver\Comparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Layerok\Basecode\Classes\Receipt;
use Layerok\PosterPos\Classes\ServiceMode;
use Layerok\PosterPos\Classes\ShippingMethodCode;
use Layerok\PosterPos\Models\Spot;
use Layerok\PosterPos\Models\User;
use October\Rain\Exception\ValidationException;
use OFFLINE\Mall\Classes\Utils\Money;
use OFFLINE\Mall\Models\Currency;
use OFFLINE\Mall\Models\PaymentMethod;
use Layerok\PosterPos\Models\ShippingMethod;
use Layerok\PosterPos\Models\PosterAccount;
use OFFLINE\Mall\Models\Product;
use poster\src\PosterApi;
use Telegram\Bot\Api;

class OrderControllerV2 extends Controller
{
    const DEFAULT_POSTER_ACCOUNT_NAME = 'emoji-bar2';

    public function place(): JsonResponse
    {
        // to make wayforpay order unique
        $add_to_poster_id = 0;
        $data = post();
        $this->validate($data);

        $jwtGuard = app('JWTGuard');

        $rainlablUser = $jwtGuard->user();

        $cart = request('cart');

        if (!$cart || (is_array($cart) && count($cart) < 1)) {
            throw new ValidationException([trans('layerok.restapi::validation.cart_empty')]);
        }

        // todo: micro optimization. Query the user only if the spot is temporarily unavailable
        /** @var User | null $user */
        $user = $rainlablUser ? User::find($rainlablUser->id): null;

        /**
         * @var Spot $spot
         */
        $spot = Spot::find($data['spot_id']);

        if($spot->temporarily_unavailable && !($user && $user->isCallCenterAdmin())) {
            // admins are allowed to bypass this check
            throw new \ValidationException([trans('layerok.restapi::validation.temporarily_unavailable')]);
        }

        $poster_account = $spot->tablet->poster_account;

        $shippingMethod = ShippingMethod::where('id', $data['shipping_method_id'])->first();
        $paymentMethod = PaymentMethod::where('id', $data['payment_method_id'])->first();

        $products = Product::with([
            'poster_accounts'
        ])->whereIn('id', collect($cart['items'])->map(fn($item) => $item['id']))->get();

        /** @var Collection $posterProducts */
        $posterProducts = $products->map(function (Product $product) use($poster_account, $cart) {
            $cartProduct = collect($cart['items'])->first(fn ($item) => $item['id'] === (string)$product->id);

            $emojibar_bar_account = $product->poster_accounts->first(
                fn(PosterAccount $account) => $account->account_name === self::DEFAULT_POSTER_ACCOUNT_NAME
            );

            $product_poster_account = $product->poster_accounts->first(
                fn(PosterAccount $account) => $account->id === $poster_account->id,
                $emojibar_bar_account // default poster account
            );

            // todo: what if variant is ordered
            // isset($cartProduct['variant_id'])

            return [
                'count' => $cartProduct['quantity'],
                'product_id' => $product_poster_account->pivot->poster_id
            ];

        });

        if (intval($data['sticks']) > 0) {
            $posterSticks = $posterProducts->first(function($posterProduct) {
                return $posterProduct['product_id'] === $this->getSticksPosterId();
            });

            if($posterSticks) {
                $posterProducts = $posterProducts->filter(function($posterProduct)  {
                    return $posterProduct['product_id'] !== $this->getSticksPosterId();
                });
            }

            $posterProducts->add([
                'name' => 'Палички для суші',
                'product_id' => $this->getSticksPosterId(),
                // merge sticks count from checkout form and from the cart
                'count' => $data['sticks'] + ($posterSticks['count'] ?? 0)
            ]);
        }

        PosterApi::init([
            'account_name' => $poster_account->account_name,
            'application_id' => $poster_account->application_id,
            'application_secrete' => $poster_account->application_secret,
            'access_token' => $poster_account->access_token,
        ]);

        $posterComment = collect([
            ['', $data['comment']],
            [trans('layerok.restapi::lang.receipt.change'), $data['change']],
            [trans('layerok.restapi::lang.receipt.payment_method'), $paymentMethod->name],
            [trans('layerok.restapi::lang.receipt.persons_amount'), $data['sticks']],
        ])->filter(fn($part) => !empty($part[1]))
            ->map(fn($part) => ($part[0] ? $part[0] . ': ' : '') . $part[1])
            ->join(' || ');


        $incomingOrder = [
            'spot_id' => $spot->tablet->tablet_id,
            'phone' => $data['phone'],
            'comment' => $posterComment,
            'products' => $posterProducts,
            'first_name' => $data['firstname'] ?? null,
            'last_name' => $data['lastname'] ?? null,
            'service_mode' => ServiceMode::ON_SITE,
        ];

        if ($shippingMethod->code === ShippingMethodCode::COURIER) {
            $incomingOrder['service_mode'] = ServiceMode::COURIER;
            $incomingOrder['address'] = $data['address'] ?? null;
        }

        if ($shippingMethod->code === ShippingMethodCode::TAKEAWAY) {
            $incomingOrder['service_mode'] = ServiceMode::TAKEAWAY;
        }

        // todo: create DTO for the poster order
        $posterResult = (object)PosterApi::incomingOrders()
            ->createIncomingOrder($incomingOrder);

        if (isset($posterResult->error)) {
            $key = 'layerok.restapi::lang.poster.errors.' . $posterResult->error;
            if (\Lang::has($key)) {
                $err_text = trans(
                    'layerok.restapi::lang.poster.errors.' . $posterResult->error
                );
            } else {
                $err_text =
                    $posterResult->message;
            }

            throw new ValidationException([
                $posterResult->error => $err_text
            ]);
        }

        if(!isset($posterResult->response)) {
            // probably poster pos services are down
            $api = new Api($spot->bot->token);

            $api->sendMessage([
                'text' => $this->generateReceipt(
                    trans("layerok.restapi::lang.receipt.order_sending_error"),
                    $cart,
                    $shippingMethod,
                    $paymentMethod,
                    $data
                ),
                'parse_mode' => "html",
                'chat_id' => $spot->chat->internal_id
            ]);

            // todo: validate version
            $userWebClientVersion = request()->header('x-web-client-version');

            if(!$userWebClientVersion) {
                throw new \ValidationException([
                    trans('layerok.restapi::validation.send_order_error')
                ]);
            }

            if(Comparator::compare($userWebClientVersion, '<', '2024.2.11')) {
                throw new \ValidationException([
                    trans('layerok.restapi::validation.send_order_error')
                ]);
            }

            return response()->json([
                'success' => true,
            ]);
        }

        $api = new Api($spot->bot->token);

        $poster_order_id = $posterResult->response->incoming_order_id + $add_to_poster_id;

        // todo: wrap in try catch
        $api->sendMessage([
            'text' => $this->generateReceipt(
                trans('layerok.restapi::lang.receipt.new_order') . ' #' . $poster_order_id,
                $cart,
                $shippingMethod,
                $paymentMethod,
                $data
            ),
            'parse_mode' => "html",
            'chat_id' => $spot->chat->internal_id
        ]);

        return response()->json([
            'success' => true,
            'poster_order' => $posterResult->response
        ]);
    }

    public function validate($data)
    {
        $rules = [
            'phone' => 'required|phoneUa',
            'firstname' => 'min:2|nullable',
            'lastname' => 'min:2|nullable',
            'email' => 'email|nullable',
            'shipping_method_id' => 'exists:offline_mall_shipping_methods,id',
            'payment_method_id' => 'exists:offline_mall_payment_methods,id',
            'spot_id' => 'exists:layerok_posterpos_spots,id'
        ];

        if (isset($data['shipping_method_id'])) {
            $shippingMethod = ShippingMethod::where('id', $data['shipping_method_id'])->first();
            if ($shippingMethod) {
                if ($shippingMethod->code === 'courier') {
                    $rules['address'] = 'required';
                    $messages['address.required'] = trans('layerok.restapi::validation.address_required');
                }
            }
        }

        $messages = [
            'email.required' => trans('offline.mall::lang.components.signup.errors.email.required'),
            'email.email' => trans('offline.mall::lang.components.signup.errors.email.email'),
            'phone.phone_ua' => trans('layerok.posterpos::lang.validation.phone.ua'),
            'email.non_existing_user' => trans('layerok.restapi::validation.customer_exists'),
            'shipping_method_id' => trans('layerok.restapi::validation.shipping_method_exists'),
            'payment_method_id' => trans('layerok.restapi::validation.payment_method_exists'),
            'firstname.min' => trans('layerok.restapi::validation.firstname_min'),
            'lastname.min' => trans('layerok.restapi::validation.lastname_min'),
            'spot_id' => trans('layerok.restapi::validation.spot_exists'),
        ];

        $validation = Validator::make($data, $rules, $messages);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }
    }

    public function generateReceipt(
        string $headline,
        $cart,
        ShippingMethod $shippingMethod,
        PaymentMethod $paymentMethod,
        $data
    ): string {
        $money = app()->make(Money::class);
        $receipt = new Receipt();

        $products = Product::with([
            'poster_accounts'
        ])->whereIn('id', collect($cart['items'])->map(fn($item) => $item['id']))->get();


        $receiptProducts = $products->map(function (Product $product) use($cart) {
            $cartProduct = collect($cart['items'])->first(fn ($item) => $item['id'] === (string)$product->id);

            return [
                'name' => $product['name'],
                'count' => $cartProduct['quantity']
            ];
        });

        // todo: write a test for calculation of cart total
        $total = $products->reduce(function($acc, $product) use($cart) {
            $item = collect($cart['items'])->first(fn($item) => $item['id'] === (string)$product->id);
            return $acc + $product->prices[0]->price * $item['quantity'];
        }, 0);


        $receipt
            ->headline(htmlspecialchars($headline))
            ->field(
                trans('layerok.restapi::lang.receipt.'),
                htmlspecialchars($data['firstname'] ?? null)
            )
            ->field(
                trans('layerok.restapi::lang.receipt.last_name'),
                htmlspecialchars($data['lastname'] ?? null)
            )
            ->field(
                trans('layerok.restapi::lang.receipt.phone'),
                htmlspecialchars($data['phone'])
            )
            ->field(
                trans('layerok.restapi::lang.receipt.delivery_method'),
                htmlspecialchars($shippingMethod->name)
            )
            ->field(
                trans('layerok.restapi::lang.receipt.address'),
                htmlspecialchars($data['address'])
            )
            ->field(
                trans('layerok.restapi::lang.receipt.payment_method'),
                htmlspecialchars($paymentMethod->name)
            )
            ->field(
                trans('layerok.restapi::lang.receipt.change'),
                htmlspecialchars($data['change'] ?? null)
            )
            ->field(
                trans('layerok.restapi::lang.receipt.persons_amount'),
                htmlspecialchars($data['sticks'] ?? null)
            )
            ->field(
                trans('layerok.restapi::lang.receipt.comment'),
                htmlspecialchars($data['comment'] ?? null)
            )
            ->newLine()
            ->b(trans('layerok.restapi::lang.receipt.order_items'))
            ->colon()
            ->newLine()
            ->map($receiptProducts, function ($item) {
                $this->product(
                    htmlspecialchars($item['name']),
                    htmlspecialchars($item['count'])
                )->newLine();
            })
            ->newLine()
            ->field(trans('layerok.restapi::lang.receipt.total'), $money->format(
                $total,
                null,
                Currency::$defaultCurrency
            ));

        return $receipt->getText();
    }

    public function isDebugOn() {
        return !!request()->header('x-debug-mode');
    }

    public function getSticksPosterId() {
        return Config::get('layerok.restapi::order.sushi_sticks_poster_id');
    }
}

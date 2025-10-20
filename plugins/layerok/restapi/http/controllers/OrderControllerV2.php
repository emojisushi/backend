<?php

namespace Layerok\Restapi\Http\Controllers;

use Composer\Semver\Comparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Layerok\Basecode\Classes\Receipt;
use Layerok\PosterPos\Classes\OnlineOrderStatus;
use Layerok\PosterPos\Classes\ServiceMode;
use Layerok\PosterPos\Classes\ShippingMethodCode;
use Layerok\PosterPos\Models\Spot;
use Layerok\PosterPos\Models\User;
use Layerok\PosterPos\Models\PendingBonus;
use October\Rain\Exception\ValidationException;
use OFFLINE\Mall\Classes\Utils\Money;
use OFFLINE\Mall\Models\Currency;
use OFFLINE\Mall\Models\PaymentMethod;
use Layerok\PosterPos\Models\ShippingMethod;
use Layerok\PosterPos\Models\PosterAccount;
use OFFLINE\Mall\Models\Product;
use poster\src\PosterApi;
use Telegram\Bot\Api;
use Layerok\RestApi\Models\Settings;
use Layerok\PosterPos\Models\Address;
use Layerok\PosterPos\Models\AddressSettings;
use Layerok\PosterPos\Models\Area;
use Layerok\PosterPos\Models\OnlineOrder;
use WayForPay\SDK\Domain\Product as WayForPayProduct;
use WayForPay\SDK\Wizard\PurchaseWizard;
use Maksa988\WayForPay\Collection\ProductCollection;
use Maksa988\WayForPay\Domain\Client;
use Maksa988\WayForPay\Facades\WayForPay;
use Layerok\PosterPos\Models\WayforpaySettings;
use WayForPay\SDK\Credential\AccountSecretCredential;

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

        $isAddressSystem = AddressSettings::get('enable_address_system');

        $cart = request('cart');

        if (!$cart || (is_array($cart) && count($cart) < 1)) {
            throw new ValidationException([trans('layerok.restapi::validation.cart_empty')]);
        }

        // todo: micro optimization. Query the user only if the spot is temporarily unavailable
        /** @var User | null $user */
        $user = $rainlablUser ? User::find($rainlablUser->id) : null;

        /**
         * @var Spot $spot
         */
        $spot = Spot::find($data['spot_id']);

        $shippingMethod = ShippingMethod::where('id', $data['shipping_method_id'])->first();
        $address = null;
        $mode = ServiceMode::ON_SITE;
        $area = null;
        if ($shippingMethod->code === ShippingMethodCode::COURIER) {
            $spotId = null;
            $mode = ServiceMode::COURIER;
            if ($isAddressSystem) {
                $address = Address::find($data['address']);
                $areas = Area::all();
                foreach ($areas as $lArea) {
                    if (
                        $this->pointInPolygon(
                            $address->lat,
                            $address->lon,
                            $lArea['coords']
                        )
                    ) {
                        $area = $lArea;
                        $spotId = $lArea['spot_id'];
                        break;
                    }
                }
                $spot = Spot::find($spotId);
                // $incomingOrder['spot_id'] = $spot->tablet->tablet_id;
                $address = $address->name_ua . ', ' . $address->suburb_ua . ', ' . $data['address_details'] ?? null;
                $data['address'] = $address;
            } else {
                $address = $data['address'] ?? null;
            }
        }

        if ($spot->temporarily_unavailable && !($user && $user->isCallCenterAdmin())) {
            // admins are allowed to bypass this check
            throw new \ValidationException([trans('layerok.restapi::validation.temporarily_unavailable')]);
        }
        $poster_account = $spot->tablet->poster_account;

        $paymentMethod = PaymentMethod::where('id', $data['payment_method_id'])->first();

        if ($paymentMethod->code === 'wayforpay' && $shippingMethod->code !== ShippingMethodCode::COURIER) {
            throw new \ValidationException(['error' => 'Online payment is available only for a courier']);
        }

        $products = Product::with([
            'poster_accounts'
        ])->whereIn('id', collect($cart['items'])->map(fn($item) => $item['id']))->get();

        /** @var Collection $posterProducts */
        $posterProducts = $products->map(function (Product $product) use ($poster_account, $cart) {
            $cartProduct = collect($cart['items'])->first(fn($item) => $item['id'] === (string) $product->id);

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
            $posterSticks = $posterProducts->first(function ($posterProduct) {
                return $posterProduct['product_id'] === $this->getSticksPosterId();
            });

            if ($posterSticks) {
                $posterProducts = $posterProducts->filter(function ($posterProduct) {
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

        // todo: write a test for calculation of cart total
        $total = $products->reduce(function ($acc, $product) use ($cart) {
            $item = collect($cart['items'])->first(fn($item) => $item['id'] === (string) $product->id);
            return $acc + $product->prices[0]->price * $item['quantity'];
        }, 0);
        $incomingOrder = [
            'spot_id' => $spot->tablet->tablet_id,
            'phone' => $data['phone'],
            'comment' => $posterComment,
            'products' => $posterProducts,
            'first_name' => $data['firstname'] ?? null,
            'last_name' => $data['lastname'] ?? null,
            'service_mode' => $mode,
            'address' => $address,
        ];

        $courier_fee = null;
        if ($shippingMethod->code === ShippingMethodCode::COURIER && $isAddressSystem) {
            if ($total / 100 < $area->min_amount) {
                $courier_fee = $area->delivery_price;
                $data['delivery_price'] = $courier_fee . " ₴";
            } else {
                $data['delivery_price'] = 0 . " ₴";
            }
        }

        if ($shippingMethod->code === ShippingMethodCode::TAKEAWAY) {
            $incomingOrder['service_mode'] = ServiceMode::TAKEAWAY;
        }

        if ($user) {
            $usedBonus = 0;
            if (isset($data['bonuses_to_use'])) {
                if (!(bool) Settings::get('bonus_enabled')) {
                    return response()->json(['message' => 'Error', 'errors' => ['bonusesToUse' => ['Bonuses are disabled']]], 400);
                }
                $usedBonus = $data['bonuses_to_use'];
            }
            if ($usedBonus > $user->bonus_amount) {
                return response()->json('Not enough bonuses', 400);
            }
            $max = Settings::get('max_bonus');
            if ($usedBonus > $total / 100 * $max) {
                return response()->json(['message' => 'Error', 'errors' => ['bonusesToUse' => [trans("layerok.restapi::validation.bonus_limit", ['max' => $max])]]], 400);
            }
        }
        if ($paymentMethod->code === 'wayforpay') {
            $incomingOrderTest = $incomingOrder;
            $incomingOrderTest['products'] = null;
            $posterTest = (object) PosterApi::incomingOrders()
                ->createIncomingOrder($incomingOrderTest);
            if (isset($posterTest->error)) {
                $key = 'layerok.restapi::lang.poster.errors.' . $posterTest->error;
                if (\Lang::has($key)) {
                    $err_text = trans(
                        'layerok.restapi::lang.poster.errors.' . $posterTest->error
                    );
                } else {
                    $err_text =
                        $posterTest->message;
                }
                if ($err_text !== 'products is empty') {
                    throw new ValidationException([
                        $posterTest->error => $err_text
                    ]);
                }
            }

            $order = $this->createOnlineOrder($incomingOrder, $total, $cart, $spot->id);
            $order_id = $order->id;
            $wayforpay_id = $order_id . '-' . time();
            $order->online_payment_id = $wayforpay_id;
            $order->save();

            $client = new Client(
                optional($data)['first_name'],
                optional($data)['last_name'],
                optional($data)['email'],
                optional($data)['phone']
            );

            $way_total = $total / 100;

            $merchantAccount = $spot->merchant_account ?? null;
            $merchantSecretKey = $spot->merchant_secret_key ?? null;
            $domainName = $spot->domain_name ?? null;

            if (!$merchantAccount || !$merchantSecretKey) {
                throw new \Exception("WayForPay credentials missing for this spot");
            }
            $credential = new AccountSecretCredential($merchantAccount, $merchantSecretKey);
            $products = [
                new WayForPayProduct(
                    'Замовлення: #' . $order_id,
                    $way_total,
                    1
                )
            ];
            if ($courier_fee != null) {
                $products[] = new WayForPayProduct(
                    'Курьер',
                    $courier_fee,
                    1
                );
            }
            $form = PurchaseWizard::get($credential)
                ->setOrderReference($wayforpay_id)
                ->setAmount($way_total + $courier_fee)
                ->setCurrency(WayforpaySettings::get('currency'))
                ->setOrderDate(new \DateTime())
                ->setMerchantDomainName($domainName)
                ->setClient($client)
                ->setProducts(new ProductCollection($products))
                ->setReturnUrl(WayforpaySettings::get('return_url') . "?order_id=$order_id")
                ->setServiceUrl(WayforpaySettings::get('service_url') . "?spot_id=$spot->id")
                ->setLanguage(WayforpaySettings::get('language'))
                ->setOrderLifetime(600)
                ->setOrderTimeout(600)
                ->getForm()
                ->getAsString();
            // $form = WayForPay::purchase(
            //     $wayforpay_id,
            //     // $poster_order_id,
            //     $way_total,
            //     $client,
            //     // $way_products,
            //     new ProductCollection([
            //         new WayForPayProduct(
            //             'Замовлення: #' . $order_id,
            //             $way_total,
            //             1
            //         ),
            //     ]),
            //     WayforpaySettings::get('currency'),
            //     null,
            //     WayforpaySettings::get('language'),
            //     null,
            //     $spot->city->thankyou_page_url . "?order_id=$order_id",
            //     WayforpaySettings::get('service_url') . "?spot_id=$spot->id",
            //     null,
            //     null,
            //     null,
            //     60,
            //     120,

            // )->getAsString(); // Get html form as string

            // $cart->delete();
            return response()->json([
                'success' => true,
                'form' => $form,
                'poster_order' => $order_id
            ]);
        }
        // todo: create DTO for the poster order
        $posterResult = (object) PosterApi::incomingOrders()
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

        if (!isset($posterResult->response)) {
            // probably poster pos services are down
            $api = new Api($spot->bot->token);

            try {
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
            } catch (\Throwable $exception) {
                try {
                    \Log::error($exception->getMessage());
                } catch (\Exception $exception) {
                }
            }

            // todo: validate version
            $userWebClientVersion = request()->header('x-web-client-version');

            if (!$userWebClientVersion) {
                throw new \ValidationException([
                    trans('layerok.restapi::validation.send_order_error')
                ]);
            }

            if (Comparator::compare($userWebClientVersion, '<', '2024.2.11')) {
                throw new \ValidationException([
                    trans('layerok.restapi::validation.send_order_error')
                ]);
            }

            return response()->json([
                'success' => true,
            ]);
        }


        $poster_order_id = $posterResult->response->incoming_order_id + $add_to_poster_id;

        if ($user) {
            $usedBonus = 0;
            if (isset($data['bonuses_to_use'])) {
                $usedBonus = $data['bonuses_to_use'];
            }
            $bonusRate = Settings::get('bonus_rate');
            $dif = 0;
            if (!(bool) Settings::get('get_bonus_from_used_bonus')) {
                $dif = $usedBonus; // отнимаем бонусы от стоимости заказа
            }
            PendingBonus::create([
                'order_id' => $poster_order_id,
                'user_id' => $user->id,
                'receive_bonus_amount' => floor(($total - $dif) / 100 * ($bonusRate / 100)),
                'use_bonus_amount' => $usedBonus,
                'pending' => true,
            ]);
            $user->bonus_amount -= $usedBonus;
            $user->save();
        }

        $api = new Api($spot->bot->token);
        $telegramRes = null;
        try {
            // В 1 хвилину 1 бот може надіслати не більше 20 повідомлень.
            $telegramRes = $api->sendMessage([
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
        } catch (\Exception $exception) {
            try {
                \Log::error($exception->getMessage());
            } catch (\Exception $exception) {
            }
        }
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

    public static function generateReceipt(
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


        $receiptProducts = $products->map(function (Product $product) use ($cart) {
            $cartProduct = collect($cart['items'])->first(fn($item) => $item['id'] === (string) $product->id);

            return [
                'name' => $product['name'],
                'count' => $cartProduct['quantity']
            ];
        });

        // todo: write a test for calculation of cart total
        $total = $products->reduce(function ($acc, $product) use ($cart) {
            $item = collect($cart['items'])->first(fn($item) => $item['id'] === (string) $product->id);
            return $acc + $product->prices[0]->price * $item['quantity'];
        }, 0);

        $receipt
            ->headline(htmlspecialchars($headline))
            ->field(
                trans('layerok.restapi::lang.receipt.first_name'),
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
            ->field(
                trans('layerok.restapi::lang.receipt.delivery_price'),
                htmlspecialchars($data['delivery_price'] ?? null)
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

    public function isDebugOn()
    {
        return !!request()->header('x-debug-mode');
    }

    public function getSticksPosterId()
    {
        return Config::get('layerok.restapi::order.sushi_sticks_poster_id');
    }

    private function pointInPolygon($x, $y, $poly)
    {
        $c = false;
        $l = count($poly);

        for ($i = 0, $j = $l - 1; $i < $l; $j = $i++) {
            $xj = $poly[$j][0];
            $yj = $poly[$j][1];
            $xi = $poly[$i][0];
            $yi = $poly[$i][1];

            $where = ($yi - $yj) * ($x - $xi) - ($xi - $xj) * ($y - $yi);

            if ($yj < $yi) {
                if ($y >= $yj && $y < $yi) {
                    if ($where == 0)
                        return true; // point on the line
                    if ($where > 0) {
                        if ($y == $yj) {
                            // ray intersects vertex
                            $prevIndex = ($j == 0) ? $l - 1 : $j - 1;
                            if ($y > $poly[$prevIndex][1]) {
                                $c = !$c;
                            }
                        } else {
                            $c = !$c;
                        }
                    }
                }
            } elseif ($yi < $yj) {
                if ($y > $yi && $y <= $yj) {
                    if ($where == 0)
                        return true; // point on the line
                    if ($where < 0) {
                        if ($y == $yj) {
                            // ray intersects vertex
                            $prevIndex = ($j == 0) ? $l - 1 : $j - 1;
                            if ($y < $poly[$prevIndex][1]) {
                                $c = !$c;
                            }
                        } else {
                            $c = !$c;
                        }
                    }
                }
            } elseif ($y == $yi && (($x >= $xj && $x <= $xi) || ($x >= $xi && $x <= $xj))) {
                return true; // point on horizontal edge
            }
        }

        return $c;
    }
    private function createOnlineOrder($data, $total, $cart, $real_spot_id)
    {
        $order = [
            'status'            => OnlineOrderStatus::WAITING,
            'online_payment_id' => $data['online_payment_id'] ?? null,
            'poster_id'         => null,
            'products'          => json_encode($data['products']),
            'phone'             => $data['phone'],
            'comment'           => $data['comment'],
            'first_name'        => $data['first_name'],
            'last_name'         => $data['last_name'],
            'address'           => $data['address'],
            'service_mode'      => $data['service_mode'],
            'spot_id'           => $data['spot_id'],
            'total'             => $total,
            'cart'              => json_encode($cart),
            'real_spot_id'      => $real_spot_id
        ];

        // Create and return the order
        return OnlineOrder::create($order);
    }
}

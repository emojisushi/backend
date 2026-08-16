<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\User;
use OFFLINE\Mall\Models\Address;
use RainLab\Location\Models\Country;
use poster\src\PosterApi;
use Layerok\PosterPos\Models\OnlineOrder;
use OFFLINE\Mall\Models\Product;

class UserController extends Controller
{
    public function fetch(): JsonResponse
    {
        $jwtGuard = app('JWTGuard');
        /** @var User $user */
        $user = User::with([
            'customer.addresses',
            'customer.orders.products.product.image_sets',
            'customer.orders.order_state'
        ])->find($jwtGuard->user()->id);

        // Remove everything except digits and +
        $phone = preg_replace('/[^+\d]/', '', $user->phone);

        if (str_starts_with($phone, '38')) {
            $phone = '+' . $phone;
        }
        if (str_starts_with($phone, '0')) {
            $phone = '+38' . $phone;
        }
        $user->phone = $phone;
        return response()->json(array_merge($user->toArray(), [
            'is_call_center_admin' => $user->isCallCenterAdmin()
        ]));
    }

    public function orders(): JsonResponse
    {
        $jwtGuard = app('JWTGuard');
        /** @var User $user */
        $user = $jwtGuard->user();

        PosterApi::init(config('poster'));

        $client = PosterApi::clients()->getClients([
            'phone' => $user->phone,
        ]);

        $clientId = $client->response[0]->client_id ?? null;

        if (!$clientId) {
            return response()->json([]);
        }

        $transactions = PosterApi::dash()->getTransactions([
            'date_from' => now()->subYears(4)->format('Ymd'),
            'date_to'   => now()->format('Ymd'),
            'type'     => 'clients',
            'id'       => $clientId,
            'status'   => 2,
        ]);

        return response()->json($transactions->response ?? []);
    }

    public function order($id): JsonResponse
    {
        $jwtGuard = app('JWTGuard');
        /** @var User $user */
        $user = $jwtGuard->user();
        PosterApi::init(config('poster'));

        $transaction = PosterApi::dash()->getTransaction([
            'include_delivery' => 'true',
            'include_products' => 'true',
            'transaction_id'   => $id,
        ]);
        $transactionProducts = collect(
            PosterApi::dash()->getTransactionProducts(['transaction_id' => $id])->response ?? []
        );
        $productNames = $transactionProducts
            ->keyBy('product_id')
            ->map(fn($p) => $p->product_name ?? null);

        $data = $transaction->response[0] ?? null;

        if (!$data) {
            return response()->json([]);
        }

        $transactionPhone = preg_replace('/\D/', '', $data->client_phone ?? '');
        $userPhone        = preg_replace('/\D/', '', $user->phone ?? '');

        if ($transactionPhone !== $userPhone) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $posterIds = collect($data->products ?? [])->pluck('product_id')->unique()->values()->toArray();

        $pivotRows = \DB::table('layerok_posterpos_poster_accountable')
            ->where('poster_account_id', 1) // todo: handle multiple accounts
            // ->where('poster_accountable_type', 'mall.product')
            ->whereIn('poster_id', $posterIds)
            ->get();

        $posterIdToProductId = $pivotRows
            ->keyBy(fn($row) => (string) $row->poster_id)
            ->map(fn($row) => $row->poster_accountable_id);

        $products = Product::whereIn('id', $posterIdToProductId->values())->get()->keyBy('id');

        $enrichedProducts = collect($data->products ?? [])->map(function ($transactionProduct) use ($posterIdToProductId, $products, $productNames, $transactionProducts) {
            $posterId       = (string) $transactionProduct->product_id;
            $localProductId = $posterIdToProductId[$posterId] ?? null;
            $localProduct   = $localProductId ? $products[$localProductId] ?? null : null;

            $transactionProductData = $transactionProducts->keyBy('product_id')[$posterId] ?? null;

            return [
                'poster_id'   => $posterId,
                'product_id'  => $localProduct?->id ?? -1,
                'name'        => $localProduct?->name ?? $productNames[$posterId] ?? 'Невідомий товар',
                'num'         => $transactionProductData?->num ?? $transactionProduct->num ??  null,
                'product_sum' => $transactionProductData?->product_sum ?? $transactionProduct->product_sum ?? null,
            ];
        });

        $address = null;
        $dPrice  = null;
        if (isset($data->delivery)) {
            $address = $data->delivery->address1 ?? null;
            $dPrice = $data->delivery->delivery_price ?? null;
        }

        return response()->json([
            'products'       => $enrichedProducts,
            'address'        => $address,
            'delivery_price' => $dPrice,
            'sum'            => $data->payed_sum ?? null,
        ]);
    }

    public function save()
    {
        $name = input('name');
        $surname = input('surname');
        $phone = input('phone');

        request()->validate([
            // 'name' => 'required|min:2',
            // 'surname' => 'required|min:2',
            // 'phone' => 'nullable|phoneUa'
        ], [
            'phone.phone_ua' => trans('layerok.posterpos::lang.validation.phone.ua')
        ]);

        $jwtGuard = app('JWTGuard');
        $user = $jwtGuard->user();
        $customer = $user->customer;

        if ($name) {
            $user->name = $name;
            $customer->firstname = $name;
        }

        if ($surname) {
            $user->surname = $surname;
            $customer->lastname = $surname;
        }

        if ($phone) {
            $user->phone = $phone;
        }

        $customer->save();
        $user->save();
        return response()->json($user);
    }

    public function updatePassword()
    {


        $minPasswordLength = User::getMinPasswordLength();

        request()->validate([
            'password_old' => "required",
            'password' => "required|between:$minPasswordLength,255|confirmed",
            'password_confirmation' => "required_with:password|between:$minPasswordLength,255"
        ]);


        $password_old = input('password_old');
        $password = input('password');
        $password_confirmation = input('password_confirmation');

        $jwtGuard = app('JWTGuard');
        $user = $jwtGuard->user();

        if ($user->checkHashValue('password', $password_old)) {
            $user->password = $password;
            $user->password_confirmation = $password_confirmation;
            $user->save();
        } else {
            throw new \ValidationException(
                ['password_old' => \Lang::get('layerok.restapi::validation.not_correct_password')]
            );
        }
    }

    public function createAddress()
    {
        request()->validate([
            'name' => 'required',
            'lines' => 'required',
            'zip' => 'required',
            'city' => 'required',
            'two_letters_country_code' => 'required'
        ]);

        $jwtGuard = app('JWTGuard');
        $user = $jwtGuard->user();
        $customer = $user->customer;

        $name = input('name');
        $lines = input('lines');
        $zip = input('zip');
        $city = input('city');
        $two_letters_country_code =  input('two_letters_country_code');

        $shippingAddress             = new Address();
        $shippingAddress->name       = $name;
        $shippingAddress->lines      = $lines;
        $shippingAddress->zip        = $zip;
        $shippingAddress->city       = $city;

        $country = Country::where('code', 'UA')->first();
        if ($country) {
            $shippingAddress->country_id = $country->id;
        } else {
            throw new \ValidationException([
                'two_letter_country_code' => "Country with code[$two_letters_country_code] doesn't exist"
            ]);
        }


        $customer->addresses()->save($shippingAddress);
        return response()->json($shippingAddress);
    }

    public function deleteAddress()
    {
        request()->validate([
            'id' => 'required|integer|exists:offline_mall_addresses'
        ]);

        $jwtGuard = app('JWTGuard');
        $user = $jwtGuard->user();
        $customer = $user->customer;

        $id = input('id');

        $address = Address::where([
            ['id', $id],
            ['customer_id', $customer->id]
        ])->first();
        if ($address) {
            $address->delete();
        }
    }

    public function setDefaultAddress()
    {
        $jwtGuard = app('JWTGuard');
        $user = $jwtGuard->user();
        $customer = $user->customer;

        request()->validate([
            'id' => 'required|integer|exists:offline_mall_addresses'
        ]);

        $id = input('id');

        $address = Address::where([
            ['id', $id],
            ['customer_id', $customer->id]
        ])->first();

        if ($address) {
            $customer->default_shipping_address_id = $address->id;
            $customer->save();
        }
    }
}

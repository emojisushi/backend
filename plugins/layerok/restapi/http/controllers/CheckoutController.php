<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\ShippingMethod;
use Layerok\PosterPos\Models\Spot;
use OFFLINE\Mall\Models\PaymentMethod;

class CheckoutController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'payment_methods' => $this->getPaymentMethods(),
            'shipping_methods' => $this->getShippingMethods(),
            'spots' => $this->getSpots(),
        ]);
    }

    public function getPaymentMethods(): array {
        $records = PaymentMethod::all();

        return $records->toArray();
    }

    public function getShippingMethods(): array {
        $records =  ShippingMethod::all();

        return $records->toArray();
    }

    public function getSpots(): array {
        $query = Spot::with('city')
            ->where('published', '=', '1');

        $records = $query->get();

        return $records->toArray();
    }

}

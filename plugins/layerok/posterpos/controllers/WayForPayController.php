<?php

namespace Layerok\PosterPos\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Layerok\PosterPos\Classes\OnlineOrderStatus;
use Layerok\PosterPos\Models\OnlineOrder;
use Layerok\PosterPos\Models\ShippingMethod;
use Telegram\Bot\Api;
use WayForPay\SDK\Domain\TransactionService;
use Layerok\PosterPos\Models\Spot;
use Layerok\PosterPos\Models\WayforpaySettings;
use WayForPay\SDK\Handler\ServiceUrlHandler;
use WayForPay\SDK\Exception\WayForPaySDKException;
use WayForPay\SDK\Credential\AccountSecretCredential;
use October\Rain\Exception\ValidationException;
use Layerok\Restapi\Http\Controllers\OrderControllerV2;
use poster\src\PosterApi;
use Layerok\PosterPos\Classes\ShippingMethodCode;
use OFFLINE\Mall\Models\PaymentMethod;

use Redirect;

class WayForPayController
{
    public Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function __invoke()
    {
        $content = $this->request->getContent();
        $data = json_decode($content);
        $spot = Spot::findBySlugOrId(input('spot_id'));
        $merchantAccount = $spot->merchant_account ?? null;
        $merchantSecretKey = $spot->merchant_secret_key ?? null;

        if (!$merchantAccount || !$merchantSecretKey) {
            throw new \Exception("WayForPay credentials missing for this spot");
        }
        $credential = new AccountSecretCredential($merchantAccount, $merchantSecretKey);

        try {
            $handler = new ServiceUrlHandler($credential);
            $response = $handler->parseRequestFromPostRaw();

            $transaction = $response->getTransaction();
            $this->notify($spot, $transaction);
            $logMessage = sprintf(
                '[WAYFORPAY] Status of order #%s  %s',
                $transaction->getOrderReference(),
                $transaction->getStatus()
            );
            Log::channel('single')->debug($logMessage);
        } catch (WayForPaySDKException $e) {
        }
        return $handler->getSuccessResponse($transaction);
    }

    public function notify(Spot $spot, TransactionService $transaction)
    {
        $api = new Api($spot->bot->token);

        if ($transaction->isStatusApproved()) {
            $order = OnlineOrder::where('online_payment_id', $transaction->getOrderReference())->first();
            $order->status = OnlineOrderStatus::PAID;
            $order->save();

            $poster_id = $this->sendPosterOrder($transaction->getOrderReference());
            $order->poster_id = $poster_id;

            $order->save();
            $message = sprintf(
                "✅ Успішний платіж на сайті https://emojisushi.com.ua \n\nСума: %s %s \nНомер замовлення: %s",
                $transaction->getAmount(),
                $transaction->getCurrency(),
                $transaction->getOrderReference()
            );
        } else if ($transaction->isStatusPending()) {
            $order = OnlineOrder::where('online_payment_id', $transaction->getOrderReference())->first();
            $order->status = OnlineOrderStatus::PENDING;
            $order->save();
            $message = sprintf(
                "Платіж у перевірці \nСума: %s %s \nНомер замовлення: %s",
                $transaction->getAmount(),
                $transaction->getCurrency(),
                $transaction->getOrderReference()
            );
        } else if ($transaction->isStatusRefunded()) {
            $order = OnlineOrder::where('online_payment_id', $transaction->getOrderReference())->first();
            $order->status = OnlineOrderStatus::REFUND;
            $order->save();
            $message = sprintf(
                "Платіж повернуто \nСума: %s %s \nНомер замовлення: %s",
                $transaction->getAmount(),
                $transaction->getCurrency(),
                $transaction->getOrderReference()
            );
        } else if ($transaction->isStatusDeclined()) {
            $order = OnlineOrder::where('online_payment_id', $transaction->getOrderReference())->first();
            $order->status = OnlineOrderStatus::CANCELLED;
            $order->save();
            $message = sprintf(
                "Платіж скасовано \nСума: %s %s \nНомер замовлення: %s",
                $transaction->getAmount(),
                $transaction->getCurrency(),
                $transaction->getOrderReference()
            );
        } else if ($transaction->isStatusExpired()) {
            $order = OnlineOrder::where('online_payment_id', $transaction->getOrderReference())->first();
            $order->status = OnlineOrderStatus::EXPIRED;
            $order->save();
            $message = sprintf(
                "❌ Час на оплату вичерпано \nСума: %s %s \nНомер замовлення: %s",
                $transaction->getAmount(),
                $transaction->getCurrency(),
                $transaction->getOrderReference()
            );
        } else {
            $message = sprintf(
                "Статус платежу: %s \nСума: %s %s \nНомер замовлення: %s",
                $transaction->getStatus(),
                $transaction->getAmount(),
                $transaction->getCurrency(),
                $transaction->getOrderReference()
            );
        }
        $params = [
            'text' => $message,
            'parse_mode' => "html",
            'chat_id' => $spot->chat->internal_id,
        ];
        if (input('telegram_message_id')) {
            $params['reply_to_message_id'] = input('telegram_message_id');
        }
        // $api->sendMessage($params);
    }

    public function redirect()
    {
        $data = post();

        $status = $data['transactionStatus'] ?? null;

        if ($status === 'Approved') {
            // $order = \OFFLINE\Mall\Models\Order::where('order_number', $data['orderReference'])->first();
            // if ($order) {
            //     $order->markAsPaid();
            // }
            return Redirect::to(WayforpaySettings::get('thankyou_url') . '?location_confirmed=true&order_id=' . $data['orderReference']);
        }
        if ($status === 'Pending') { //Pending
            // $order = \OFFLINE\Mall\Models\Order::where('order_number', $data['orderReference'])->first();
            // if ($order) {
            //     $order->markAsPaid();
            // }
            // return Redirect::to(WayforpaySettings::get('thankyou_url') . '?location_confirmed=true&order_id=' . $data['orderReference']);
        }
        return Redirect::to(WayforpaySettings::get('status_url') . '?location_confirmed=true&order_id=' . $data['orderReference']);
    }
    public function sendPosterOrder($order_id)
    {

        $order = OnlineOrder::where('online_payment_id', $order_id)->first();
        $spot = Spot::findBySlugOrId($order->spot_id);
        $poster_account = $spot->tablet->poster_account;

        PosterApi::init([
            'account_name' => $poster_account->account_name,
            'application_id' => $poster_account->application_id,
            'application_secrete' => $poster_account->application_secret,
            'access_token' => $poster_account->access_token,
        ]);

        $incomingOrder = [
            'spot_id' => $order->spot_id,
            'phone' => $order->phone,
            'comment' => $order->online_payment_id . '  ОПЛАЧЕНО  ' . $order->comment,
            'products' => json_decode($order->products),
            'first_name' => $order->first_name ?? null,
            'last_name' => $order->last_name ?? null,
            'service_mode' => $order->service_mode,
            'address' => $order->address,
            'payment'  => ['type' => 1, 'sum' => $order->total, 'currency' => 'UAH']
        ];


        $posterResult = (object) PosterApi::incomingOrders()
            ->createIncomingOrder($incomingOrder);

        $poster_order_id = $posterResult->response->incoming_order_id ?? null;
        
        $order->poster_id = $poster_order_id;


        // if (isset($posterResult->error) || !isset($posterResult->response)) { // error or poster is down -> send to telegram
        $api = new Api($spot->bot->token);
        try {
            $api->sendMessage([
                'text' => OrderControllerV2::generateReceipt(
                    trans($poster_order_id ? 'layerok.restapi::lang.receipt.new_order' : 'layerok.restapi::lang.receipt.order_sending_error') . ' #' . $poster_order_id . ' (' . $order_id . ')',
                    json_decode($order->cart, true),
                    ShippingMethod::where('code', ShippingMethodCode::COURIER)->first(),
                    PaymentMethod::where('code', 'wayforpay')->first(),
                    $order
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
        return $poster_order_id;
    }
}

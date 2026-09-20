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
use Layerok\Restapi\Services\BonusService;

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

        $handler = new ServiceUrlHandler($credential);

        try {
            $response = $handler->parseRequestFromPostRaw();
            $transaction = $response->getTransaction();
        } catch (WayForPaySDKException $e) {
            // A malformed body or a signature that does not match this spot's
            // credentials. Report it instead of falling through to an undefined
            // $transaction, which hid the real reason.
            Log::channel('single')->error('[WAYFORPAY] callback rejected: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }

        $this->notify($spot, $transaction);

        Log::channel('single')->debug(sprintf(
            '[WAYFORPAY] Status of order #%s  %s',
            $transaction->getOrderReference(),
            $transaction->getStatus()
        ));

        return $handler->getSuccessResponse($transaction);
    }

    public function notify(Spot $spot, TransactionService $transaction)
    {
        $api = new Api($spot->bot->token);

        if ($transaction->isStatusApproved()) {
            $order = OnlineOrder::where('online_payment_id', $transaction->getOrderReference())->first();

            $order->status = OnlineOrderStatus::PAID;
            $order->save();

            $poster_id = $this->sendPosterOrder($spot->id, $transaction->getOrderReference());
            $order->poster_id = $poster_id;

            $order->save();
            $this->attachPosterOrderToBonuses($order, $poster_id);


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
            $this->refundBonuses($order, "Payment refunded");
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
            $this->refundBonuses($order, "Payment declined");
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
            $this->refundBonuses($order, "Payment expired");
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

        // $status = $data['transactionStatus'] ?? null;

        // if ($status === 'Approved') {
        //     // $order = \OFFLINE\Mall\Models\Order::where('order_number', $data['orderReference'])->first();
        //     // if ($order) {
        //     //     $order->markAsPaid();
        //     // }
        //     return Redirect::to(WayforpaySettings::get('thankyou_url') . '?location_confirmed=true&order_id=' . $data['orderReference']);
        // }
        // if ($status === 'Pending') { //Pending
        //     // $order = \OFFLINE\Mall\Models\Order::where('order_number', $data['orderReference'])->first();
        //     // if ($order) {
        //     //     $order->markAsPaid();
        //     // }
        //     // return Redirect::to(WayforpaySettings::get('thankyou_url') . '?location_confirmed=true&order_id=' . $data['orderReference']);
        // }
        $params = ['location_confirmed' => 'true', 'order_id' => $data['orderReference']];

        $waitTime = request()->query('wait_time');
        if ($waitTime !== null) {
            $params['wait_time'] = $waitTime;
        }
        $mobile = request()->query('mobile');

        if ($mobile !== null) {
            $orderId = request()->query('order_id');
            $deepLink = "emojisushi://payment?orderId={$orderId}&wait_time={$waitTime}";

            return response()->make("
                <!DOCTYPE html>
                <html>
                <head>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Оплата</title>
                </head>

                <body style='display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;text-align:center;background-color:#141414;'>

                <div>
                    <p style='color:white;margin-bottom:24px;font-size:16px;'>Оплата успішна! ✅</p>
                    <a id='deeplink-btn' href='{$deepLink}' style='background-color:#FFE600;padding:24px;border-radius:10px;font-size:16px;border:none;text-decoration:none;color:black;display:inline-block;'>
                        Повернутися до додатку
                    </a>
                </div>

                <script>
                    const deepLink = '{$deepLink}';
                    let opened = false;

                    function tryIframe() {
                        const iframe = document.createElement('iframe');
                        iframe.style.display = 'none';
                        iframe.src = deepLink;
                        document.body.appendChild(iframe);
                        setTimeout(() => {
                            if (document.body.contains(iframe)) {
                                document.body.removeChild(iframe);
                            }
                        }, 2000);
                    }

                    function tryAnchor() {
                        document.getElementById('deeplink-btn').click();
                    }

                    function tryLocation() {
                        window.location.href = deepLink;
                    }

                    function openApp() {
                        if (opened) return;
                        opened = true; // prevent double-firing

                        tryIframe();

                        setTimeout(tryAnchor, 100);

                        setTimeout(tryLocation, 300);
                    }

                    setTimeout(openApp, 500);
                </script>

                </body>
                </html>
                ");
        }
        $baseUrl = WayforpaySettings::get('status_url');
        return Redirect::to($baseUrl . '?' . http_build_query($params));
    }
    /**
     * Links the write-off made at checkout to the order Poster has now created, so
     * that the register can read the discount and the transaction webhook can mark
     * the write-off final. Failures are logged rather than thrown: the payment is
     * already taken, so the order must still go ahead.
     */
    private function attachPosterOrderToBonuses(OnlineOrder $order, $posterOrderId): void
    {
        if (!$posterOrderId) {
            return;
        }

        try {
            $row = (new BonusService())->appliedForOnlineOrder((int) $order->id);

            if ($row) {
                $row->incoming_order_id = (int) $posterOrderId;
                $row->save();
            }
        } catch (\Throwable $e) {
            Log::error('Linking bonus write-off to Poster order failed', [
                'online_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Payment was refunded, so the points go back — but only while the write-off
     * is still open. A bill already closed on the register is left for manual
     * handling, since the discount has been rung up.
     */
    private function refundBonuses(OnlineOrder $order, string $note): void
    {
        try {
            $service = new BonusService();
            $row = $service->appliedForOnlineOrder((int) $order->id);

            if ($row) {
                $service->refund($row, $note);
            }
        } catch (\Throwable $e) {
            Log::error('Bonus refund after payment refund failed', [
                'online_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendPosterOrder($real_spot_id, $order_id)
    {

        $order = OnlineOrder::where('online_payment_id', $order_id)->first();
        $spot = Spot::findBySlugOrId($real_spot_id);
        $poster_account = $spot->tablet->poster_account;

        PosterApi::init([
            'account_name' => $poster_account->account_name,
            'application_id' => $poster_account->application_id,
            'application_secrete' => $poster_account->application_secret,
            'access_token' => $poster_account->access_token,
        ]);

        // The client was charged the total less any points they spent, so the
        // prepayment reported to Poster must match. The register adds the points
        // back as a setOrderBonus line and the bill balances.
        $bonusRow = (new BonusService())->appliedForOnlineOrder((int) $order->id);
        $paidSum = $order->total + $order->delivery_price - ($bonusRow->amount ?? 0);

        $incomingOrder = [
            'spot_id' => $order->spot_id,
            'phone' => $order->phone,
            'comment' => $order->online_payment_id . '  ОПЛАЧЕНО  ' . $order->comment,
            'products' => json_decode($order->products),
            'first_name' => $order->first_name ?? null,
            'last_name' => $order->last_name ?? null,
            'service_mode' => $order->service_mode,
            'address' => $order->address,
            'delivery_price' => $order->delivery_price,
            'payment'  => ['type' => 1, 'sum' => $paidSum, 'currency' => 'UAH']
        ];


        $posterResult = (object) PosterApi::incomingOrders()
            ->createIncomingOrder($incomingOrder);

        $poster_order_id = $posterResult->response->incoming_order_id ?? null;

        $order->poster_id = $poster_order_id;
        $order['delivery_price_uah'] =  $order->delivery_price / 100 . " ₴";


        // if (isset($posterResult->error) || !isset($posterResult->response)) { // error or poster is down -> send to telegram
        $api = new Api($spot->bot->token);
        try {
            $api->sendMessage([
                'text' => OrderControllerV2::generateReceipt(
                    trans($poster_order_id ? 'layerok.restapi::lang.receipt.new_order' : 'layerok.restapi::lang.receipt.order_sending_error') . ' #' . $poster_order_id . ' (' . $order_id . ')',
                    json_decode($order->cart, true),
                    ShippingMethod::where('code', ShippingMethodCode::COURIER)->first(),
                    PaymentMethod::where('code', 'wayforpay')->first(),
                    $order,
                    null,
                    (int) ($bonusRow->amount ?? 0)
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

    public function getOrderStatus()
    {
        $order_id = input('order_id');
        $order = OnlineOrder::where('online_payment_id', $order_id)->first();
        if ($order == null) {
            return response()->json(null, 404);
        }
        $data = $order->only(['status', 'online_payment_id', 'poster_id']);
        return response()->json($data);
    }
}

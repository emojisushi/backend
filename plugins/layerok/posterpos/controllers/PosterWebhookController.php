<?php

namespace Layerok\PosterPos\Controllers;

use Illuminate\Support\Facades\Log;
use Layerok\PosterPos\Classes\Delivery\DeliveryEventRecorder;
use Layerok\PosterPos\Classes\PosterTransition;
use Layerok\PosterPos\Classes\SpotStockService;
use Layerok\RestApi\Models\BonusTransaction;
use Layerok\Restapi\Services\BonusService;
use poster\src\PosterApi;

class PosterWebhookController
{
    public function __invoke()
    {
        // Секретный ключ вашего приложения
        $client_secret = config('poster.application_secret');

        // Приводим к нужному формату входящие данные
        $postJSON = file_get_contents('php://input');
        $postData = json_decode($postJSON, true);
        $verify_original = $postData['verify'];
        unset($postData['verify']);

        $verify = [
            $postData['account'],
            $postData['object'],
            $postData['object_id'],
            $postData['action'],
        ];

        // Если есть дополнительные параметры
        if (isset($postData['data'])) {
            $verify[] = $postData['data'];
        }
        $verify[] = $postData['time'];
        $verify[] = $client_secret;

        // Создаём строку для верификации запроса клиентом
        $verify = md5(implode(';', $verify));

        // Проверяем валидность данных
        if ($verify != $verify_original) {
            Log::info("Проверка валидности данных провалилась");
            exit;
        }

        // Бонусы: если обработка сорвалась, отвечаем ошибкой, чтобы Poster
        // повторил вебхук — списание не должно потеряться.
        try {
            if ($postData['object'] === 'incoming_order') {
                $this->handleIncomingOrder((int) $postData['object_id'], $postData['action'] ?? '');
            }

            // 'client' fires on a manual edit, 'client_payed_sum' when a bill closes
            // with a linked customer — which is when Poster grants points.
            if ($postData['object'] === 'client' || $postData['object'] === 'client_payed_sum') {
                (new BonusService())->recordExternalChange((int) $postData['object_id']);
            }

            if ($postData['object'] === 'transaction') {
                $this->handleTransaction(
                    (int) $postData['object_id'],
                    $postData['action'] ?? '',
                    $postData['data'] ?? null
                );
            }
        } catch (\Throwable $e) {
            Log::error('Bonus webhook failed', [
                'object' => $postData['object'],
                'object_id' => $postData['object_id'],
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo json_encode(['status' => 'retry']);

            return;
        }

        // Лента событий операторов. Ошибка здесь не должна вызывать повтор
        // вебхука — иначе Poster заново прогонит и бонусную логику выше.
        try {
            if ($postData['object'] === 'transaction') {
                (new DeliveryEventRecorder())->recordTransaction(
                    (int) $postData['object_id'],
                    $postData['action'] ?? '',
                    isset($postData['time']) ? (int) $postData['time'] : null
                );
            }

            if ($postData['object'] === 'incoming_order') {
                (new DeliveryEventRecorder())->recordIncomingOrder(
                    (int) $postData['object_id'],
                    $postData['action'] ?? '',
                    isset($postData['time']) ? (int) $postData['time'] : null
                );
            }
        } catch (\Throwable $e) {
            Log::error('Delivery event webhook failed', [
                'object' => $postData['object'],
                'object_id' => $postData['object_id'],
                'error' => $e->getMessage(),
            ]);
        }

        // Меню: ошибки синхронизации не должны вызывать бесконечные повторы.
        try {
            if ($postData['object'] === 'dish') {
                $this->handleDish($postData);
            }
        } catch (\Throwable $e) {
            Log::error('Dish webhook failed', [
                'object_id' => $postData['object_id'],
                'error' => $e->getMessage(),
            ]);
        }

        echo json_encode(['status' => 'accept']);
    }

    /**
     * Запоминаем, в какой чек превратился онлайн-заказ, чтобы вебхук транзакции
     * нашёл списание при закрытии. Отменённый заказ возвращает бонусы клиенту
     * и лимитированные товары — на остаток заведения.
     */
    private function handleIncomingOrder(int $incomingOrderId, string $action = ''): void
    {
        $row = BonusTransaction::where('incoming_order_id', $incomingOrderId)
            ->where('status', BonusTransaction::STATUS_APPLIED)
            ->first();

        $stock = new SpotStockService();
        $holdsStock = $stock->hasReservedForIncomingOrder($incomingOrderId);

        if (!$row && !$holdsStock) {
            return;
        }

        // A deleted online order will not come back, and the lookup below may no
        // longer resolve it, so refund straight from the webhook action.
        if ($action === 'removed') {
            if ($row) {
                (new BonusService())->refund($row, 'Online order removed in Poster');
            }

            $stock->releaseForIncomingOrder($incomingOrderId);

            return;
        }

        PosterApi::init(config('poster'));
        $result = (object) PosterApi::incomingOrders()->getIncomingOrder([
            'incoming_order_id' => $incomingOrderId,
        ]);

        $order = $result->response ?? null;

        if (!$order) {
            return;
        }

        // 0 — новый, 1 — принят, 7 — отменён
        if ((int) ($order->status ?? 0) === 7) {
            if ($row) {
                (new BonusService())->refund($row, 'Online order canceled in Poster');
            }

            $stock->releaseForIncomingOrder($incomingOrderId);

            return;
        }

        if ($row && !empty($order->transaction_id)) {
            $row->transaction_id = (int) $order->transaction_id;
            $row->save();
        }
    }

    /**
     * Чек закрыт — списание становится окончательным, и начисляем бонусы, которые
     * Poster не начислил из-за оплаты бонусами. Удалённый чек возвращает бонусы.
     */
    private function handleTransaction(int $transactionId, string $action = '', $rawData = null): void
    {
        $row = BonusTransaction::where('transaction_id', $transactionId)
            ->where('status', BonusTransaction::STATUS_APPLIED)
            ->first();

        if (!$row) {
            return;
        }

        // A deleted order will not come back, and the lookup below may no longer
        // resolve it, so refund straight from the webhook action.
        if ($action === 'removed') {
            (new BonusService())->refund($row, 'Order removed in Poster');

            return;
        }

        // Cancelling is not a removal: Poster sends action "changed" with a
        // changeorderstatus entry (value 4 = cancelled), and dash.getTransaction
        // still reports the order as open, so the status check below misses it.
        if ($this->isCancellation($rawData)) {
            (new BonusService())->refund($row, 'Order cancelled in Poster');

            return;
        }

        PosterApi::init(config('poster'));
        $result = (object) PosterApi::dash()->getTransaction([
            'transaction_id' => $transactionId,
            // Needed to grant the accrual Poster withholds — see accrueWithheld().
            'include_history' => 'true',
            'include_products' => 'true',
        ]);

        $transaction = collect($result->response ?? [])->first();

        if (!$transaction) {
            return;
        }

        // 1 — открыт, 2 — закрыт, 3 — удалён
        $status = (int) ($transaction->status ?? 0);

        if ($status === 3) {
            (new BonusService())->refund($row, 'Order removed in Poster');

            return;
        }

        if ($status === 2) {
            $bonus = new BonusService();

            // Accrue before settling: if Poster fails here the webhook is retried,
            // and the retry only finds the row while it is still applied.
            $bonus->accrueWithheld($row, $transaction);
            $bonus->settle($row);
        }
    }

    /**
     * Whether a transaction webhook describes the order being cancelled.
     * The detail lives in the history entry Poster attaches as `data`.
     */
    private function isCancellation($rawData): bool
    {
        if (empty($rawData)) {
            return false;
        }

        $data = is_array($rawData) ? $rawData : json_decode((string) $rawData, true);
        $history = $data['transactions_history'] ?? null;

        return is_array($history)
            && ($history['type_history'] ?? null) === 'changeorderstatus'
            && (int) ($history['value'] ?? 0) === 4;
    }

    private function handleDish(array $postData): void
    {
        $transition = new PosterTransition;

        if ($postData['action'] === 'removed') {
            $transition->deleteProduct($postData['object_id']);

            return;
        }

        PosterApi::init(config('poster'));
        $result = (object) PosterApi::menu()->getProduct([
            'product_id' => $postData['object_id']
        ]);

        $product = $result->response;

        if (!$product) {
            return;
        }

        switch ($postData['action']) {
            case "added":
                $transition->createProduct($product);
                break;
            case "changed":
                $transition->updateProduct($product);
                break;
        }
    }
}

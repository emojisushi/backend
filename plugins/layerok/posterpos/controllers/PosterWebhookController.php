<?php

namespace Layerok\PosterPos\Controllers;

use Illuminate\Support\Facades\Log;
use Layerok\PosterPos\Classes\PosterTransition;
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
                $this->handleIncomingOrder((int) $postData['object_id']);
            }

            // 'client' fires on a manual edit, 'client_payed_sum' when a bill closes
            // with a linked customer — which is when Poster grants points.
            if ($postData['object'] === 'client' || $postData['object'] === 'client_payed_sum') {
                $row = (new BonusService())->recordExternalChange((int) $postData['object_id']);

                Log::channel('single')->debug(sprintf(
                    '[BONUS] %s #%s -> %s',
                    $postData['object'],
                    $postData['object_id'],
                    $row ? 'delta ' . $row->delta : 'no change recorded'
                ));
            }

            if ($postData['object'] === 'transaction') {
                $this->handleTransaction((int) $postData['object_id']);
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
     * нашёл списание при закрытии. Отменённый заказ возвращает бонусы клиенту.
     */
    private function handleIncomingOrder(int $incomingOrderId): void
    {
        $row = BonusTransaction::where('incoming_order_id', $incomingOrderId)
            ->where('status', BonusTransaction::STATUS_APPLIED)
            ->first();

        if (!$row) {
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
            (new BonusService())->refund($row, 'Online order canceled in Poster');

            return;
        }

        if (!empty($order->transaction_id)) {
            $row->transaction_id = (int) $order->transaction_id;
            $row->save();
        }
    }

    /**
     * Чек закрыт — списание становится окончательным. Удалённый чек возвращает бонусы.
     */
    private function handleTransaction(int $transactionId): void
    {
        $row = BonusTransaction::where('transaction_id', $transactionId)
            ->where('status', BonusTransaction::STATUS_APPLIED)
            ->first();

        if (!$row) {
            return;
        }

        PosterApi::init(config('poster'));
        $result = (object) PosterApi::dash()->getTransaction([
            'transaction_id' => $transactionId,
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
            (new BonusService())->settle($row);
        }
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

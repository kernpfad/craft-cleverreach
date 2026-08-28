<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\util\OrderEnqueueGate;

/**
 * Async Commerce order push + order-complete tags (CR-11 / CR-10).
 *
 * Only the order ID is stored — the Order is reloaded when the worker runs.
 */
class PushOrderJob extends BaseJob
{
    public int $orderId;

    public static function enqueue(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $cache = Craft::$app->getCache();
        $key = OrderEnqueueGate::cacheKey($orderId);

        if ($cache !== null && !$cache->add($key, 1, OrderEnqueueGate::TTL_SECONDS)) {
            return;
        }

        Queue::push(
            new self(['orderId' => $orderId]),
            delay: OrderEnqueueGate::DELAY_SECONDS,
        );
    }

    public function execute($queue): void
    {
        Craft::$app->getCache()?->delete(OrderEnqueueGate::cacheKey($this->orderId));

        if (!class_exists(Order::class)) {
            return;
        }

        $order = Order::find()
            ->id($this->orderId)
            ->status(null)
            ->one();

        if (!$order instanceof Order) {
            return;
        }

        Plugin::getInstance()->commerceOrderPush->pushOrder($order);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('cleverreach', 'Push CleverReach order {id}', [
            'id' => $this->orderId,
        ]);
    }
}

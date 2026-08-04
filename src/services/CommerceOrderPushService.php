<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use kernpfad\cleverreach\Plugin;
use Throwable;

/**
 * Pushes a completed Craft Commerce order to CleverReach so
 * CleverReach's own automation flows (welcome mail after first order,
 * reactivation, post-purchase) can react to it.
 *
 * This class is only ever loaded/called when Craft Commerce is installed
 * and `enableOrderPush` is on — see Plugin::attachCommerceEventHandlers().
 * It's kept separate from SubscriberService/CleverReachApiService so the
 * rest of the plugin has no hard dependency on craftcms/commerce.
 */
class CommerceOrderPushService extends Component
{
    public function pushOrder(Order $order): void
    {
        $email = $order->getEmail();

        if ($email === null || $email === '') {
            return;
        }

        $consentRecord = Plugin::getInstance()->consent->getLatestConsent(null, $email);

        // Never create a receiver purely because of an order — only push order
        // data for people who already opted in.
        if ($consentRecord === null) {
            return;
        }

        $groupId = $consentRecord->groupId ?? Plugin::getInstance()->getSettings()->defaultGroupId;

        if ($groupId === null) {
            return;
        }

        // Runs inside the order-complete flow — a CleverReach outage must never
        // break order completion, so failures are logged, not thrown.
        try {
            Plugin::getInstance()->cleverReachApi->pushOrderToReceiver(
                (int) $groupId,
                $email,
                $this->buildOrderPayload($order)
            );
        } catch (Throwable $e) {
            Craft::error('CleverReach order push failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderPayload(Order $order): array
    {
        $items = [];

        foreach ($order->getLineItems() as $lineItem) {
            $items[] = [
                'name' => $lineItem->getDescription(),
                'quantity' => $lineItem->qty,
                'price' => $lineItem->salePrice,
            ];
        }

        return [
            'order_id' => $order->number,
            'date' => $order->dateOrdered?->format('Y-m-d\TH:i:sP'),
            'total' => (float) $order->totalPrice,
            'currency' => $order->currency,
            'items' => $items,
        ];
    }
}

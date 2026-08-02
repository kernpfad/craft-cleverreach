<?php

namespace kernpfad\cleverreach\services;

use craft\base\Component;
use craft\commerce\elements\Order;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\ConsentLogRecord;

/**
 * Baustein C: pushes a completed Craft Commerce order to CleverReach so
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

        // Per Feinkonzept Abschnitt 8/9: never create a receiver purely because
        // of an order — only push order data for people who already opted in.
        if (!$this->hasExistingConsent($email)) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->defaultGroupId === null) {
            return;
        }

        Plugin::getInstance()->cleverReachApi->pushOrderToReceiver(
            $settings->defaultGroupId,
            $email,
            $this->buildOrderPayload($order)
        );
    }

    private function hasExistingConsent(string $email): bool
    {
        return ConsentLogRecord::find()->where(['email' => $email])->exists();
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

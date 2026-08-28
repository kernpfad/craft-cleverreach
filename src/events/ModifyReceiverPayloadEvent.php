<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\events;

use yii\base\Event;

/**
 * Fired before a receiver's attribute/order payload is sent to
 * CleverReach's `groups/{id}/receivers/upsert` endpoint (CR-08). Mutate
 * `$event->payload` to add, override, or remove keys — it's exactly what
 * gets sent, `email` and `activated` included.
 */
class ModifyReceiverPayloadEvent extends Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $groupId,
        public string $email,
        public bool $activated,
        public array $payload,
    ) {
        parent::__construct();
    }
}

<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\events;

use kernpfad\cleverreach\records\ConsentLogRecord;
use yii\base\Event;

/**
 * Fired when an unsubscribe/bounce notification is received and applied
 * (CR-07) — after `$consentRecord->unsubscribedAt` is already persisted.
 */
class ReceiverUnsubscribedEvent extends Event
{
    public function __construct(
        public string $email,
        public ConsentLogRecord $consentRecord,
        public ?string $reason,
    ) {
        parent::__construct();
    }
}

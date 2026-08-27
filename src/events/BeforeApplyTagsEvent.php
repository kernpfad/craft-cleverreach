<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\events;

use yii\base\Event;

/**
 * Fired before tags are sent to CleverReach (CR-10). Mutate `$tags` or set
 * `$isValid = false` to cancel.
 */
class BeforeApplyTagsEvent extends Event
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $email,
        public array $tags,
        public string $context,
        public ?int $groupId,
        public bool $isValid = true,
    ) {
        parent::__construct();
    }
}

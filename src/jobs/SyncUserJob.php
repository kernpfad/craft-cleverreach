<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\jobs;

use Craft;
use craft\elements\User;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\util\SyncEnqueueGate;

/**
 * Async CleverReach attribute sync for a Craft user (debounced on save).
 *
 * Only the user ID is stored on the job — field values are read when the
 * worker runs so later profile edits inside the debounce window are included.
 */
class SyncUserJob extends BaseJob
{
    public int $userId;

    /**
     * Enqueue a sync for this user unless one is already pending (cache gate).
     */
    public static function enqueue(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $cache = Craft::$app->getCache();
        $key = SyncEnqueueGate::cacheKey($userId);

        // add() is atomic: only the first caller in the TTL window succeeds.
        // Without a cache component, fall through and always enqueue.
        if ($cache !== null && !$cache->add($key, 1, SyncEnqueueGate::TTL_SECONDS)) {
            return;
        }

        Queue::push(
            new self(['userId' => $userId]),
            delay: SyncEnqueueGate::DELAY_SECONDS,
        );
    }

    public function execute($queue): void
    {
        Craft::$app->getCache()?->delete(SyncEnqueueGate::cacheKey($this->userId));

        $user = User::find()
            ->id($this->userId)
            ->status(null)
            ->one();

        if (!$user instanceof User || ElementHelper::isDraftOrRevision($user)) {
            return;
        }

        Plugin::getInstance()->userSync->syncUser($user);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('cleverreach', 'Sync CleverReach attributes for user {id}', [
            'id' => $this->userId,
        ]);
    }
}

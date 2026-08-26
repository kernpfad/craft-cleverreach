<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\ElementHelper;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\UserSyncRecord;
use kernpfad\cleverreach\util\ReceiverSyncDecision;
use Throwable;

/**
 * Keeps a subscriber's CleverReach attributes (name, language, etc.) in
 * sync when their Craft User profile changes — never creates a new
 * subscription. Only ever acts on users who already have a
 * `cleverreach_consentlog` entry (linked by userId or matching email),
 * same "existing consent required" rule as CommerceOrderPushService.
 *
 * Respects the receiver's real CleverReach state (CR-06 soft-sync): a
 * still-pending (unconfirmed) receiver gets attribute updates with
 * `activated: false` so data is not lost before DOI confirmation; an
 * unsubscribed one (CR-07) is skipped entirely.
 */
class UserSyncService extends Component
{
    public function syncUser(User $user): void
    {
        if (ElementHelper::isDraftOrRevision($user) || $user->email === null || $user->id === null) {
            return;
        }

        $consentRecord = Plugin::getInstance()->consent->getLatestConsent($user->id, $user->email);

        if ($consentRecord === null) {
            return;
        }

        $unsubscribed = $consentRecord->unsubscribedAt !== null;
        $groupId = $consentRecord->groupId ?? Plugin::getInstance()->getSettings()->defaultGroupId;

        if ($groupId === null) {
            return;
        }

        $email = $user->email;
        $attributes = Plugin::getInstance()->subscriber->mapAttributes($user->getFieldValues());

        // This runs synchronously inside a User save request (CP or
        // front-end profile edit) — a CleverReach outage must never break
        // saving a user profile, so failures are logged and recorded, not
        // thrown.
        try {
            $api = Plugin::getInstance()->cleverReachApi;
            $receiver = $unsubscribed ? null : $api->getReceiver((int) $groupId, $email);
            $action = ReceiverSyncDecision::decide($receiver, $unsubscribed);

            if ($action === ReceiverSyncDecision::ACTION_SKIP) {
                return;
            }

            if ($action === ReceiverSyncDecision::ACTION_SOFT_UPDATE) {
                $api->updateReceiverAttributes((int) $groupId, $email, $attributes);
                $this->recordSyncResult((int) $user->id, 'ok', null, false);

                return;
            }

            $api->activateReceiver((int) $groupId, $email, $attributes);
            $this->recordSyncResult((int) $user->id, 'ok', null, true);
        } catch (Throwable $e) {
            Craft::error('CleverReach user sync failed: ' . $e->getMessage(), __METHOD__);
            $this->recordSyncResult((int) $user->id, 'error', $e->getMessage(), null);
        }
    }

    public function getSyncRecord(int $userId): ?UserSyncRecord
    {
        /** @var UserSyncRecord|null $record */
        $record = UserSyncRecord::find()->where(['userId' => $userId])->one();

        return $record;
    }

    private function recordSyncResult(
        int $userId,
        string $status,
        ?string $error,
        ?bool $doiConfirmed,
    ): void {
        $record = $this->getSyncRecord($userId) ?? new UserSyncRecord();
        $record->userId = $userId;
        $record->status = $status;
        $record->message = $error;
        $record->doiConfirmed = $doiConfirmed;

        if (!$record->save(false)) {
            Craft::warning(
                'CleverReach could not persist sync status: ' . implode(', ', $record->getFirstErrors()),
                __METHOD__
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use DateTime;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\records\ConsentLogRecord;
use Throwable;

/**
 * Keeps a subscriber's CleverReach attributes (name, language, etc.) in
 * sync when their Craft User profile changes — never creates a new
 * subscription. Only ever acts on users who already have a
 * `cleverreach_consentlog` entry (linked by userId or matching email),
 * same "existing consent required" rule as CommerceOrderPushService.
 *
 * Respects the receiver's real CleverReach state (CR-06): a still-pending
 * (unconfirmed) receiver is left alone rather than force-activated, and an
 * unsubscribed one (CR-07) is skipped entirely. This replaces the plugin's
 * previous behavior of always sending `activated: true` regardless of
 * whether CleverReach had actually recorded a DOI confirmation yet.
 */
class UserSyncService extends Component
{
    public function syncUser(User $user): void
    {
        if (ElementHelper::isDraftOrRevision($user) || $user->email === null) {
            return;
        }

        $consentRecord = Plugin::getInstance()->consent->getLatestConsent($user->id, $user->email);

        if ($consentRecord === null) {
            return;
        }

        if ($consentRecord->unsubscribedAt !== null) {
            return;
        }

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
            $receiver = $api->getReceiver((int) $groupId, $email);
            $isConfirmed = $receiver !== null && ($receiver['activated'] ?? false) === true;

            if ($receiver !== null && !$isConfirmed) {
                // Still pending DOI confirmation on CleverReach's own side -
                // leave activation alone rather than forcing it early.
                // Attributes aren't pushed either: CleverReach's upsert
                // can't update a pending receiver's data without also
                // touching `activated`, and this plugin only ever sends
                // `activated: true` through that endpoint (see
                // CleverReachApiService::activateReceiver()/
                // createReceiverForDoubleOptIn()).
                $this->recordSyncResult($consentRecord, 'ok', null, doiConfirmed: false);

                return;
            }

            $api->activateReceiver((int) $groupId, $email, $attributes);
            $this->recordSyncResult($consentRecord, 'ok', null, doiConfirmed: true);
        } catch (Throwable $e) {
            Craft::error('CleverReach user sync failed: ' . $e->getMessage(), __METHOD__);
            $this->recordSyncResult($consentRecord, 'error', $e->getMessage(), doiConfirmed: null);
        }
    }

    private function recordSyncResult(
        ConsentLogRecord $consentRecord,
        string $status,
        ?string $error,
        ?bool $doiConfirmed,
    ): void {
        $consentRecord->lastSyncStatus = $status;
        $consentRecord->lastSyncAt = Db::prepareDateForDb(new DateTime());
        $consentRecord->lastSyncError = $error;

        if ($doiConfirmed === true && $consentRecord->doiConfirmedAt === null) {
            $consentRecord->doiConfirmedAt = Db::prepareDateForDb(new DateTime());
        }

        if (!$consentRecord->save(false)) {
            Craft::warning(
                'CleverReach could not persist sync status: ' . implode(', ', $consentRecord->getFirstErrors()),
                __METHOD__
            );
        }
    }
}

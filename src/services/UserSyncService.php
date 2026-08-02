<?php

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\ElementHelper;
use kernpfad\cleverreach\Plugin;
use Throwable;

/**
 * Keeps a subscriber's CleverReach attributes (name, language, etc.) in
 * sync when their Craft User profile changes — never creates a new
 * subscription. Only ever acts on users who already have a
 * `cleverreach_consentlog` entry (linked by userId or matching email),
 * same "existing consent required" rule as CommerceOrderPushService.
 *
 * Like CommerceOrderPushService, this sends `activated: true` on every
 * sync (see CleverReachApiService::activateReceiver()) — the same
 * accepted trade-off already made for Baustein C: since we don't poll
 * CleverReach for whether a DOI-pending receiver has actually confirmed
 * yet, a profile-save sync could in theory activate someone slightly
 * early. Kept consistent with the rest of the plugin rather than
 * inventing a different rule here.
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

        $groupId = $consentRecord->groupId ?? Plugin::getInstance()->getSettings()->defaultGroupId;

        if ($groupId === null) {
            return;
        }

        // This runs synchronously inside a User save request (CP or
        // front-end profile edit) — a CleverReach outage must never break
        // saving a user profile, so failures are logged, not thrown.
        try {
            Plugin::getInstance()->cleverReachApi->activateReceiver(
                (int) $groupId,
                $user->email,
                Plugin::getInstance()->subscriber->mapAttributes($user->getFieldValues())
            );
        } catch (Throwable $e) {
            Craft::error('CleverReach user sync failed: ' . $e->getMessage(), __METHOD__);
        }
    }
}

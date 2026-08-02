<?php

namespace fipschen95\cleverreach\services;

use craft\base\Component;
use fipschen95\cleverreach\records\ConsentLogRecord;

/**
 * Records an independent double-opt-in consent proof on the Craft side
 * (timestamp, IP, source, consent text version) — kept separate from
 * CleverReach's own DOI confirmation log, per German Nachweispflicht
 * best practice (see Feinkonzept, Abschnitt 9).
 */
class ConsentService extends Component
{
    public function logConsent(
        string $email,
        string $ipAddress,
        string $source,
        ?string $consentTextVersion,
        ?int $groupId,
        ?int $userId = null
    ): void {
        $record = new ConsentLogRecord();
        $record->email = $email;
        $record->userId = $userId;
        $record->ipAddress = $ipAddress;
        $record->source = $source;
        $record->consentTextVersion = $consentTextVersion;
        $record->groupId = $groupId;
        $record->save();
    }

    /**
     * Most recent consent record for a Craft User, matched by userId or
     * (as a fallback, for signups that predate the user account existing
     * or that happened while logged out) by email. Used for the newsletter
     * status shown in the User CP edit page and for UserSyncService.
     */
    public function getLatestConsent(?int $userId, ?string $email): ?ConsentLogRecord
    {
        if ($userId === null && ($email === null || $email === '')) {
            return null;
        }

        $conditions = ['or'];

        if ($userId !== null) {
            $conditions[] = ['userId' => $userId];
        }

        if ($email !== null && $email !== '') {
            $conditions[] = ['email' => $email];
        }

        return ConsentLogRecord::find()
            ->where($conditions)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }
}

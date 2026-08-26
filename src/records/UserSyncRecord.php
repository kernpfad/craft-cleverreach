<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\records;

use craft\db\ActiveRecord;

/**
 * Last CleverReach user-attribute sync result for a Craft user (CR-05).
 *
 * @property int $id
 * @property int $userId
 * @property string $status 'ok' or 'error'
 * @property string|null $message
 * @property bool|null $doiConfirmed
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class UserSyncRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cleverreach_user_sync}}';
    }
}

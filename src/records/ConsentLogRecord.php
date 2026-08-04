<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $email
 * @property int|null $userId Linked Craft User account, if the email matched one at signup time
 * @property string $ipAddress
 * @property string $source
 * @property string|null $consentTextVersion
 * @property int|null $groupId
 */
class ConsentLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cleverreach_consentlog}}';
    }
}

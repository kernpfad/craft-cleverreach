<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\migrations;

use craft\db\Migration;

/**
 * Installs `cleverreach_consentlog` (consent proof + unsubscribe marker)
 * and `cleverreach_user_sync` (last attribute-sync status per Craft user).
 *
 * No token storage table is needed: the plugin authenticates against
 * CleverReach via the OAuth2 Client Credentials grant (client_id/secret
 * from env vars), so access tokens are short-lived and simply re-requested
 * on expiry rather than persisted.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%cleverreach_consentlog}}')) {
            $this->createTable('{{%cleverreach_consentlog}}', [
                'id' => $this->primaryKey(),
                'email' => $this->string()->notNull(),
                'userId' => $this->integer(),
                'ipAddress' => $this->string(45)->notNull(),
                'source' => $this->string()->notNull(),
                'consentTextVersion' => $this->string(),
                'groupId' => $this->integer(),
                'unsubscribedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%cleverreach_consentlog}}', ['email']);
            $this->createIndex(null, '{{%cleverreach_consentlog}}', ['userId']);

            // No cascading delete: a consent record is a legal/historical proof
            // and should survive the Craft User account being deleted later.
            $this->addForeignKey(
                null,
                '{{%cleverreach_consentlog}}',
                ['userId'],
                '{{%users}}',
                ['id'],
                'SET NULL',
                null
            );
        }

        if (!$this->db->tableExists('{{%cleverreach_user_sync}}')) {
            $this->createTable('{{%cleverreach_user_sync}}', [
                'id' => $this->primaryKey(),
                'userId' => $this->integer()->notNull(),
                'status' => $this->string(10)->notNull(),
                'message' => $this->text()->null(),
                'doiConfirmed' => $this->boolean()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%cleverreach_user_sync}}', ['userId'], true);
            $this->addForeignKey(
                null,
                '{{%cleverreach_user_sync}}',
                ['userId'],
                '{{%users}}',
                ['id'],
                'CASCADE',
                null
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%cleverreach_user_sync}}');
        $this->dropTableIfExists('{{%cleverreach_consentlog}}');

        return true;
    }
}

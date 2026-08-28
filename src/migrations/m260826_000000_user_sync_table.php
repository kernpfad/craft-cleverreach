<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;

/**
 * Moves sync/DOI status off the consent log onto `cleverreach_user_sync`
 * (CR-05 delta) and backfills existing rows where possible.
 */
class m260826_000000_user_sync_table extends Migration
{
    private const CONSENT_TABLE = '{{%cleverreach_consentlog}}';
    private const SYNC_TABLE = '{{%cleverreach_user_sync}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::SYNC_TABLE)) {
            $this->createTable(self::SYNC_TABLE, [
                'id' => $this->primaryKey(),
                'userId' => $this->integer()->notNull(),
                'status' => $this->string(10)->notNull(),
                'message' => $this->text()->null(),
                'doiConfirmed' => $this->boolean()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::SYNC_TABLE, ['userId'], true);
            $this->addForeignKey(
                null,
                self::SYNC_TABLE,
                ['userId'],
                '{{%users}}',
                ['id'],
                'CASCADE',
                null
            );
        }

        $this->backfillFromConsentLog();

        foreach (['lastSyncStatus', 'lastSyncAt', 'lastSyncError', 'doiConfirmedAt'] as $column) {
            if ($this->db->columnExists(self::CONSENT_TABLE, $column)) {
                $this->dropColumn(self::CONSENT_TABLE, $column);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach ([
            'lastSyncStatus' => 'string(10) NULL',
            'lastSyncAt' => 'datetime NULL',
            'lastSyncError' => 'text NULL',
            'doiConfirmedAt' => 'datetime NULL',
        ] as $name => $definition) {
            if (!$this->db->columnExists(self::CONSENT_TABLE, $name)) {
                $this->addColumn(self::CONSENT_TABLE, $name, $definition);
            }
        }

        $this->dropTableIfExists(self::SYNC_TABLE);

        return true;
    }

    private function backfillFromConsentLog(): void
    {
        if (
            !$this->db->tableExists(self::CONSENT_TABLE)
            || !$this->db->columnExists(self::CONSENT_TABLE, 'lastSyncStatus')
        ) {
            return;
        }

        $rows = (new Query())
            ->select([
                'userId',
                'lastSyncStatus',
                'lastSyncAt',
                'lastSyncError',
                'doiConfirmedAt',
                'dateUpdated',
            ])
            ->from(self::CONSENT_TABLE)
            ->where(['not', ['userId' => null]])
            ->andWhere([
                'or',
                ['not', ['lastSyncStatus' => null]],
                ['not', ['doiConfirmedAt' => null]],
            ])
            ->orderBy(['dateUpdated' => SORT_ASC])
            ->all($this->db);

        $now = Db::prepareDateForDb(new DateTime());

        foreach ($rows as $row) {
            $userId = (int) $row['userId'];
            $status = $row['lastSyncStatus'] ?? 'ok';
            if ($status !== 'ok' && $status !== 'error') {
                $status = 'ok';
            }

            $exists = (new Query())
                ->from(self::SYNC_TABLE)
                ->where(['userId' => $userId])
                ->exists($this->db);

            $values = [
                'status' => $status,
                'message' => $row['lastSyncError'] ?? null,
                'doiConfirmed' => $row['doiConfirmedAt'] !== null ? true : null,
                'dateUpdated' => $row['lastSyncAt'] ?? $row['dateUpdated'] ?? $now,
            ];

            if ($exists) {
                $this->update(self::SYNC_TABLE, $values, ['userId' => $userId]);
            } else {
                $this->insert(self::SYNC_TABLE, array_merge($values, [
                    'userId' => $userId,
                    'dateCreated' => $row['lastSyncAt'] ?? $row['dateUpdated'] ?? $now,
                    'uid' => StringHelper::UUID(),
                ]));
            }
        }
    }
}

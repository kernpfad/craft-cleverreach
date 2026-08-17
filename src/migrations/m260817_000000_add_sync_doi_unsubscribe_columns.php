<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\migrations;

use craft\db\Migration;

/**
 * Adds the columns CR-05 (last sync status), CR-06 (cached DOI confirmation
 * timestamp), and CR-07 (unsubscribe) need on `cleverreach_consentlog`, for
 * installs that already had this plugin before those features existed.
 * Install.php only runs on a brand-new install.
 */
class m260817_000000_add_sync_doi_unsubscribe_columns extends Migration
{
    private const TABLE = '{{%cleverreach_consentlog}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        if (!$this->db->columnExists(self::TABLE, 'lastSyncStatus')) {
            $this->addColumn(self::TABLE, 'lastSyncStatus', 'string(10) NULL');
        }

        if (!$this->db->columnExists(self::TABLE, 'lastSyncAt')) {
            $this->addColumn(self::TABLE, 'lastSyncAt', 'datetime NULL');
        }

        if (!$this->db->columnExists(self::TABLE, 'lastSyncError')) {
            $this->addColumn(self::TABLE, 'lastSyncError', 'text NULL');
        }

        if (!$this->db->columnExists(self::TABLE, 'doiConfirmedAt')) {
            $this->addColumn(self::TABLE, 'doiConfirmedAt', 'datetime NULL');
        }

        if (!$this->db->columnExists(self::TABLE, 'unsubscribedAt')) {
            $this->addColumn(self::TABLE, 'unsubscribedAt', 'datetime NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return true;
        }

        foreach (['lastSyncStatus', 'lastSyncAt', 'lastSyncError', 'doiConfirmedAt', 'unsubscribedAt'] as $name) {
            if ($this->db->columnExists(self::TABLE, $name)) {
                $this->dropColumn(self::TABLE, $name);
            }
        }

        return true;
    }
}

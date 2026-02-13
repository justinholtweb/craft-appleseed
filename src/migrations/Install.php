<?php

namespace justinholtweb\appleseed\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createScansTable();
        $this->_createLinksTable();
        $this->_createLinkSourcesTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%appleseed_link_sources}}');
        $this->dropTableIfExists('{{%appleseed_links}}');
        $this->dropTableIfExists('{{%appleseed_scans}}');

        return true;
    }

    private function _createScansTable(): void
    {
        $this->createTable('{{%appleseed_scans}}', [
            'id' => $this->primaryKey(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'type' => $this->string(20)->notNull()->defaultValue('full'),
            'totalLinksFound' => $this->integer()->notNull()->defaultValue(0),
            'totalLinksChecked' => $this->integer()->notNull()->defaultValue(0),
            'brokenCount' => $this->integer()->notNull()->defaultValue(0),
            'redirectCount' => $this->integer()->notNull()->defaultValue(0),
            'workingCount' => $this->integer()->notNull()->defaultValue(0),
            'startedAt' => $this->dateTime(),
            'completedAt' => $this->dateTime(),
            'entryId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function _createLinksTable(): void
    {
        $this->createTable('{{%appleseed_links}}', [
            'id' => $this->primaryKey(),
            'url' => $this->text()->notNull(),
            'urlHash' => $this->char(64)->notNull(),
            'statusCode' => $this->smallInteger(),
            'status' => $this->string(20)->notNull()->defaultValue('unknown'),
            'redirectUrl' => $this->text(),
            'redirectChain' => $this->text(),
            'errorMessage' => $this->text(),
            'lastCheckedAt' => $this->dateTime(),
            'lastScanId' => $this->integer(),
            'isIgnored' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%appleseed_links}}', 'urlHash', true);
        $this->createIndex(null, '{{%appleseed_links}}', 'status');
        $this->createIndex(null, '{{%appleseed_links}}', 'isIgnored');
        $this->createIndex(null, '{{%appleseed_links}}', 'lastScanId');

        $this->addForeignKey(
            null,
            '{{%appleseed_links}}',
            'lastScanId',
            '{{%appleseed_scans}}',
            'id',
            'SET NULL',
        );
    }

    private function _createLinkSourcesTable(): void
    {
        $this->createTable('{{%appleseed_link_sources}}', [
            'id' => $this->primaryKey(),
            'linkId' => $this->integer()->notNull(),
            'entryId' => $this->integer(),
            'siteId' => $this->integer(),
            'fieldHandle' => $this->string(255),
            'linkText' => $this->string(500),
            'sourceType' => $this->string(20)->notNull(),
            'sourceUrl' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%appleseed_link_sources}}', 'linkId');
        $this->createIndex(null, '{{%appleseed_link_sources}}', 'entryId');
        $this->createIndex(null, '{{%appleseed_link_sources}}', 'siteId');

        $this->addForeignKey(
            null,
            '{{%appleseed_link_sources}}',
            'linkId',
            '{{%appleseed_links}}',
            'id',
            'CASCADE',
        );
    }
}

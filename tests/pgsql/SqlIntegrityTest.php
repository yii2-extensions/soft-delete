<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\pgsql;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use yii2\extensions\softdelete\tests\base\AbstractSqlIntegrity;
use yii2\extensions\softdelete\tests\data\SqlProbeRecord;
use yii2\extensions\softdelete\tests\support\DatabaseConnection;

/**
 * Unit tests for the {@see AbstractSqlIntegrity} soft-delete SQL integrity checks on the PostgreSQL driver.
 */
#[Group('pgsql')]
final class SqlIntegrityTest extends AbstractSqlIntegrity
{
    public function testThrowPDOExceptionWhenDeferredForeignKeyRejectsTheCommit(): void
    {
        $db = $this->connection();

        $this->createTable(
            '{{%deferred_child}}',
            [
                'id' => 'integer NOT NULL',
                'item_id' => 'integer NOT NULL',
                'PRIMARY KEY ([[id]])',
                'FOREIGN KEY ([[item_id]]) REFERENCES {{%item}} ([[id]]) DEFERRABLE INITIALLY DEFERRED',
            ],
        );

        $db
            ->createCommand()
            ->insert('{{%deferred_child}}', ['id' => 1, 'item_id' => 1])
            ->execute();

        $record = SqlProbeRecord::requireOne(1);

        $this->expectException(PDOException::class);

        try {
            $record->safeDelete();
        } finally {
            self::assertInstanceOf(
                PDO::class,
                $db->pdo,
                'The connection must stay open.',
            );
            self::assertFalse(
                $db->pdo->inTransaction(),
                'The server must have discarded the transaction.',
            );
            self::assertFalse(
                $record->isNewRecord,
                'The model must stay persisted.',
            );
            self::assertSame(
                [],
                $record->getDirtyAttributes(),
                'The model must match the stored row.',
            );
            self::assertNotNull(
                SqlProbeRecord::findOne(1),
                'The stored row must remain.',
            );
        }
    }

    protected function database(): DatabaseConnection
    {
        return DatabaseConnection::PGSQL;
    }
}

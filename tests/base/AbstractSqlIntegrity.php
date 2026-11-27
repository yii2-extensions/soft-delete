<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\base;

use LogicException;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\{ActiveRecord, Connection};
use yii\db\IntegrityException;
use yii\db\oci\Schema as OracleSchema;
use yii2\extensions\softdelete\{DeleteBehavior,ScopedQuery};
use yii2\extensions\softdelete\tests\data\SqlProbeRecord;
use yii2\extensions\softdelete\tests\support\DatabaseConnection;
use function is_int;
use function is_string;

/**
 * Base class for the SQL integrity tests of {@see DeleteBehavior} and {@see ScopedQuery}, running physical deletion,
 * the foreign-key fallback, rollbacks and the query scopes against the connection of each driver subclass.
 */
abstract class AbstractSqlIntegrity extends TestCase
{
    /**
     * @var list<string>
     */
    private array $createdTables = [];
    private Connection|null $db = null;

    abstract protected function database(): DatabaseConnection;

    public function testPhysicalDeleteForeignKeyFallbackAndRollback(): void
    {
        $db = $this->connection();

        $schema = $db->getSchema();

        if ($schema instanceof OracleSchema) {
            // Yii's Oracle schema only maps ORA-00001. Scope this policy to the
            // integration connection, and never classify all Oracle DB errors.
            $schema->exceptionMap += [
                'ORA-02292' => IntegrityException::class,
                'ORA-01400' => IntegrityException::class,
            ];
        }

        $physical = SqlProbeRecord::requireOne(1);

        self::assertSame(
            1,
            $physical->safeDelete(),
            'An unreferenced row must be deleted.',
        );
        self::assertNull(
            SqlProbeRecord::findOne(1),
            'The unreferenced row must be removed.',
        );
        self::assertTrue(
            $physical->isNewRecord,
            'The deleted model must become new.',
        );

        $fallback = SqlProbeRecord::requireOne(2);

        $fallbackEvents = 0;

        $fallback->on(
            DeleteBehavior::EVENT_AFTER_SOFT_DELETE,
            static function () use (&$fallbackEvents): void {
                ++$fallbackEvents;
            },
        );

        self::assertSame(
            1,
            $fallback->safeDelete(),
            'A referenced row must be marked instead.',
        );
        self::assertSame(
            1,
            $fallback->deleted,
            'The model must receive the marker.',
        );
        self::assertSame(
            1,
            $this->storedMarker(2),
            'The stored row must be marked.',
        );
        self::assertSame(
            1,
            $fallbackEvents,
            "The fallback must trigger 'afterSoftDelete' once.",
        );
        self::assertFalse(
            $fallback->isNewRecord,
            'The marked model must stay persisted.',
        );
        self::assertSame(
            2,
            (int) $db
                ->createCommand(
                    <<<SQL
                    SELECT [[item_id]] FROM {{%child}} WHERE [[id]] = 1
                    SQL,
                )
                ->queryScalar(),
            'The child row must keep its reference.',
        );

        $audit = SqlProbeRecord::requireOne(3);

        $captured = new class {
            public IntegrityException|null $error = null;
        };

        $audit->on(
            ActiveRecord::EVENT_AFTER_DELETE,
            static function () use ($db, $captured): void {
                self::assertNull(
                    SqlProbeRecord::findOne(3),
                    'Audit runs after physical SQL.',
                );

                try {
                    $db->createCommand()
                        ->insert('{{%audit}}', ['message' => null])
                        ->execute();
                } catch (IntegrityException $error) {
                    $captured->error = $error;
                    throw $error;
                }
            }
        );

        try {
            $audit
                ->deleteBehavior()
                ->safeDelete();

            self::fail(
                'The audit failure must reach the caller.',
            );
        } catch (IntegrityException $error) {
            self::assertSame(
                $captured->error,
                $error,
                'The audit failure must propagate unchanged.',
            );
        }

        self::assertFalse(
            $audit->isNewRecord,
            'The audited model must stay persisted.',
        );
        self::assertSame(
            0,
            $audit->deleted,
            'The audited model must not receive the marker.',
        );
        self::assertSame(
            0,
            $this->storedMarker(3),
            'The audited row must be restored unmarked.',
        );
        self::assertNull(
            $db->getTransaction(),
            'The transaction must be closed.',
        );

        $outer = $db->beginTransaction();

        $nested = SqlProbeRecord::requireOne(4);

        self::assertSame(
            1,
            $nested->safeDelete(),
            'The nested fallback must mark one row.',
        );
        self::assertSame(
            1,
            $outer->getLevel(),
            'The fallback leaves the caller transaction active.',
        );
        self::assertSame(
            1,
            $this->storedMarker(4),
            'The marker must be visible inside the caller transaction.',
        );

        $outer->rollBack();

        self::assertSame(
            0,
            $this->storedMarker(4),
            'The caller can roll back the fallback marker.',
        );
    }

    public function testSoftDeleteAndRestoreUseIndependentQueryScopes(): void
    {
        $record = SqlProbeRecord::requireOne(1);
        $query = new ScopedQuery(SqlProbeRecord::class);

        self::assertSame(
            1,
            $record->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            1,
            $this->storedMarker(1),
            'The stored row must be marked.',
        );
        self::assertTrue(
            (clone $query)->deleted()->alias('item')->where(['item.id' => 1])->exists(),
            'The marked row must count as deleted.',
        );
        self::assertFalse(
            (clone $query)->notDeleted()->alias('item')->where(['item.id' => 1])->exists(),
            'The marked row must not count as active.',
        );
        self::assertSame(
            1,
            $record->restore(),
            'One row must be restored.',
        );
        self::assertSame(
            0,
            $this->storedMarker(1),
            'The stored row must be unmarked.',
        );
        self::assertFalse(
            (clone $query)->deleted()->alias('item')->where(['item.id' => 1])->exists(),
            'The restored row must not count as deleted.',
        );
        self::assertTrue(
            (clone $query)->notDeleted()->alias('item')->where(['item.id' => 1])->exists(),
            'The restored row must count as active.',
        );
    }

    protected function connection(): Connection
    {
        return $this->db ?? throw new LogicException('Expected an initialized fixture connection.');
    }

    /**
     * Creates a table that {@see tearDown()} drops after the test.
     *
     * @param array<array-key, string> $columns
     */
    protected function createTable(string $name, array $columns, string|null $options = null): void
    {
        $this->connection()
            ->createCommand()
            ->createTable($name, $columns, $options)
            ->execute();

        $this->createdTables[] = $name;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $configuration = $this->database()->configuration();

        $configuration['tablePrefix'] = 'sd_' . bin2hex(random_bytes(4)) . '_';

        $app = new Application(
            [
                'id' => 'sql-integrity-test',
                'basePath' => __DIR__,
                'vendorPath' => dirname(__DIR__, 2) . '/vendor',
                'components' => ['db' => $configuration],
            ],
        );

        $this->db = $app->getDb();
        $db = $this->db;

        self::assertSame(
            $this->database()->value,
            $db->getDriverName(),
            'The connection must use the driver.',
        );

        if ($this->database() === DatabaseConnection::SQLITE) {
            $db
                ->createCommand(
                    <<<SQL
                    PRAGMA foreign_keys = ON
                    SQL
                )
                ->execute();
        }

        // Quote all identifiers through Yii and omit ON DELETE RESTRICT,
        // which Oracle and SQL Server do not accept. Their default rejects
        // a parent delete as long as a child references it.
        $options = $this->database() === DatabaseConnection::MYSQL ? 'ENGINE=InnoDB' : null;

        $this->createTable(
            '{{%item}}',
            [
                'id' => 'integer NOT NULL',
                'deleted' => 'integer NOT NULL',
                'PRIMARY KEY ([[id]])',
            ],
            $options,
        );
        $this->createTable(
            '{{%child}}',
            [
                'id' => 'integer NOT NULL',
                'item_id' => 'integer NOT NULL',
                'PRIMARY KEY ([[id]])',
                'FOREIGN KEY ([[item_id]]) REFERENCES {{%item}} ([[id]])',
            ],
            $options,
        );
        $this->createTable(
            '{{%audit}}',
            ['message' => 'string(100) NOT NULL'],
            $options,
        );

        // One statement per row also works on Oracle versions without the
        // standard multi-row VALUES syntax.
        foreach ([1, 2, 3, 4] as $id) {
            $db
                ->createCommand()
                ->insert('{{%item}}', ['id' => $id, 'deleted' => 0])
                ->execute();
        }

        $db->createCommand()
            ->insert('{{%child}}', ['id' => 1, 'item_id' => 2])
            ->execute();
        $db->createCommand()
            ->insert('{{%child}}', ['id' => 2, 'item_id' => 4])
            ->execute();
    }

    protected function tearDown(): void
    {
        try {
            $db = $this->db;

            if ($db !== null) {
                $transaction = $db->getTransaction();

                while ($transaction?->getIsActive() === true) {
                    $transaction->rollBack();
                }

                foreach (array_reverse($this->createdTables) as $table) {
                    $db->createCommand()
                        ->dropTable($table)
                        ->execute();
                }
            }
        } finally {
            $this->db?->close();
            Yii::$app = null; // @phpstan-ignore assign.propertyType (The Yii analysis stub prohibits a null application.)
            parent::tearDown();
        }
    }

    private function storedMarker(int $id): int
    {
        $value = $this
            ->connection()
            ->createCommand(
                <<<SQL
                SELECT [[deleted]] FROM {{%item}} WHERE [[id]] = :id
                SQL,
                [':id' => $id],
            )
            ->queryScalar();

        self::assertTrue(
            is_int($value) || is_string($value),
            'Expected a persisted database marker.',
        );

        return (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests;

use ArrayObject;
use Closure;
use DomainException;
use Error;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, TestWith};
use RuntimeException;
use stdClass;
use yii\base\{InvalidConfigException, ModelEvent, NotSupportedException};
use yii\db\{BaseActiveRecord, Connection, IntegrityException, StaleObjectException};
use yii2\extensions\softdelete\DeleteBehavior;
use yii2\extensions\softdelete\exception\Message;
use yii2\extensions\softdelete\tests\data\{Category, Item, TransactionalItem, VersionedItem};
use yii2\extensions\softdelete\tests\provider\DeleteBehaviorProvider;
use yii2\extensions\softdelete\tests\support\CommitRejectingPdo;

/**
 * Unit tests for {@see DeleteBehavior} soft deletion, restoration and safe deletion of ActiveRecord models on SQLite.
 *
 * {@see DeleteBehaviorProvider} for test case data providers.
 */
#[Group('sqlite')]
class DeleteBehaviorTest extends TestCase
{
    public function testAllowDelete(): void
    {
        $item = Item::requireOne(1);

        $item->name = 'allow-delete';

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be deleted.',
        );
        self::assertNull(
            Item::findOne(1),
            'The row must be removed physically.',
        );
    }

    public function testAttachedInsertDefaults(): void
    {
        $item = new Item(['name' => 'with defaults']);

        $behavior = $item->deleteBehavior();

        $behavior->useRestoreAttributeValuesAsDefaults = true;
        $behavior->restoreAttributeValues = ['deletedAt' => static fn(): int => 456];

        self::assertTrue(
            $item->save(false),
            'The first record must be inserted.',
        );
        self::assertSame(
            456,
            Item::requireOne($item->id)->deletedAt,
            'The computed default must be stored.',
        );

        $second = new Item(['name' => 'without defaults']);

        $second->deleteBehavior()->restoreAttributeValues = ['deletedAt' => 456];

        self::assertTrue(
            $second->save(false),
            'The second record must be inserted.',
        );
        self::assertNull(
            Item::requireOne($second->id)->deletedAt,
            'A disabled setting must leave the attribute unset.',
        );
    }

    public function testBeforeRestore(): void
    {
        $item = Item::requireOne(1);

        $item->softDelete();

        $item = Item::requireOne(1);

        $item->on(
            DeleteBehavior::EVENT_BEFORE_RESTORE,
            function (ModelEvent $event) {
                $item = $event->sender;

                self::assertInstanceOf(
                    Item::class,
                    $item,
                    'The event sender must be the record.',
                );

                $item->deletedAt = 200;
            },
        );

        $item->restore();

        self::assertSame(
            200,
            Item::requireOne(1)->deletedAt,
            'The handler change must be stored.',
        );
    }

    public function testBeforeSoftDelete(): void
    {
        $item = Item::requireOne(1);

        $item->on(
            DeleteBehavior::EVENT_BEFORE_SOFT_DELETE,
            function (ModelEvent $event) {
                $item = $event->sender;

                self::assertInstanceOf(
                    Item::class,
                    $item,
                    'The event sender must be the record.',
                );

                $item->deletedAt = 100;
            },
        );

        $item->softDelete();

        self::assertSame(
            100,
            Item::requireOne(1)->deletedAt,
            'The handler change must be stored.',
        );
    }

    public function testBehaviorSubclassCanExtendStateTransitions(): void
    {
        $behavior = new class extends DeleteBehavior {
            /** @var list<string> */
            public array $calls = [];

            protected function isDeleteAllowed(): bool
            {
                $this->calls[] = 'decision';
                return parent::isDeleteAllowed();
            }

            protected function restoreInternal(): int
            {
                $this->calls[] = 'restore';
                return parent::restoreInternal();
            }

            protected function softDeleteInternal(): int|false
            {
                $this->calls[] = 'mark';
                return parent::softDeleteInternal();
            }
        };

        $item = Item::requireOne(2);

        $item->attachBehavior('softDelete', $behavior::class);

        $attachedBehavior = $item->deleteBehavior();

        self::assertInstanceOf(
            $behavior::class,
            $attachedBehavior,
            'The subclass must be attached.',
        );
        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            1,
            $item->restore(),
            'One row must be restored.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row must be active again.',
        );
        self::assertSame(
            ['decision', 'mark', 'restore'],
            $attachedBehavior->calls,
            'Each override must run once, in order.',
        );
    }

    public function testBinaryMarkersInferOppositeValues(): void
    {
        self::assertSame(
            ['a' => 1, 'b' => 0, 'c' => true],
            (new DeleteBehavior([
                'softDeleteAttributeValues' => ['a' => 0, 'b' => 1, 'c' => false],
            ]))->resolveRestoreAttributeValues(),
            "Booleans must be negated and '0' and '1' swapped.",
        );
    }

    public function testCallback(): void
    {
        $item = Item::requireOne(1);

        $deletedAt = 1_700_000_000;

        $item->deleteBehavior()->softDeleteAttributeValues = ['deletedAt' => static fn(): int => $deletedAt];
        $item->softDelete();

        self::assertSame(
            $deletedAt,
            $item->deletedAt,
            'The computed delete value must be assigned.',
        );

        $item = Item::requireOne(1);

        $item->deleteBehavior()->restoreAttributeValues = ['deletedAt' => fn() => null];
        $item->restore();

        self::assertNull(
            $item->deletedAt,
            'The computed restore value must be assigned.',
        );
    }

    public function testCommitListenerCanCloseConnectionBeforeReportingFailure(): void
    {
        $item = Item::requireOne(2);
        $db = Item::getDb();

        $error = new LogicException(
            'Commit listener closed the connection',
        );

        $db->on(
            Connection::EVENT_COMMIT_TRANSACTION,
            static function () use ($db, $error): never {
                $db->close();

                throw $error;
            },
        );

        self::assertSame(
            $error,
            self::catchThrowable(static fn() => $item->safeDelete()),
            'The exception must originate in the listener.',
        );
        self::assertFalse(
            $db->getIsActive(),
            'The connection must stay closed.',
        );
        self::assertTrue(
            $item->getIsNewRecord(),
            'The already committed deletion must remain reflected in the model.',
        );
    }

    public function testCommitListenerFailureLeavesAlreadyCommittedModelState(): void
    {
        $item = Item::requireOne(2);

        $error = new LogicException(
            'Commit listener failed',
        );

        Item::getDb()->on(
            Connection::EVENT_COMMIT_TRANSACTION,
            static fn() => throw $error,
        );

        self::assertSame(
            $error,
            self::catchThrowable(static fn() => $item->safeDelete()),
            'The exception must originate in the listener.',
        );
        self::assertTrue(
            $item->isNewRecord,
            'The already committed deletion must remain reflected in the model.',
        );
        self::assertNull(
            Item::findOne(2),
            'The committed deletion must remain stored.',
        );
    }

    public function testCompositePrimaryKeyTargetsOnlyOneRow(): void
    {
        $db = Item::getDb();

        $db
            ->createCommand(
                <<<SQL
                CREATE TABLE CompositeItem (id INTEGER, tenant INTEGER, name TEXT, isDeleted BOOLEAN, PRIMARY KEY (id, tenant))
                SQL
            )
            ->execute();

        $db
            ->createCommand()
            ->batchInsert(
                'CompositeItem',
                ['id', 'tenant', 'name', 'isDeleted'],
                [
                    [
                        1,
                        1,
                        'first',
                        false,
                    ],
                    [
                        1,
                        2,
                        'second',
                        false,
                    ],
                ],
            )
            ->execute();

        $fixture = new class extends Item {
            public static function tableName(): string
            {
                return 'CompositeItem';
            }
        };

        $target = $fixture::requireOne(['id' => 1, 'tenant' => 2]);

        $sibling = [
            'id' => 1,
            'tenant' => 1,
        ];

        self::assertSame(
            1,
            $target->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            0,
            $target->softDelete(),
            'A repeated mark must not write.',
        );
        self::assertFalse(
            $fixture::requireOne($sibling)->isDeleted,
            'The sibling row must stay active.',
        );
        self::assertSame(
            1,
            $target->safeDelete(),
            'One row must be deleted.',
        );
        self::assertNull(
            $fixture::findOne(['id' => 1, 'tenant' => 2]),
            'The target row must be removed.',
        );
        self::assertSame(
            'first',
            $fixture::requireOne($sibling)->name,
            'The sibling row must remain.',
        );
    }

    public function testConsumersCanDispatchLifecycleHooksOnTheBehavior(): void
    {
        $item = Item::requireOne(2);

        $events = self::recordEvents(
            $item,
            [
                DeleteBehavior::EVENT_BEFORE_SOFT_DELETE,
                DeleteBehavior::EVENT_AFTER_SOFT_DELETE,
                DeleteBehavior::EVENT_BEFORE_RESTORE,
                DeleteBehavior::EVENT_AFTER_RESTORE,
            ],
        );

        $behavior = $item->deleteBehavior();

        self::assertTrue(
            $behavior->beforeSoftDelete(),
            'The soft-delete hook must allow the transition.',
        );

        $behavior->afterSoftDelete();

        self::assertTrue(
            $behavior->beforeRestore(),
            'The restore hook must allow the transition.',
        );

        $behavior->afterRestore();

        self::assertSame(
            ['beforeSoftDelete', 'afterSoftDelete', 'beforeRestore', 'afterRestore'],
            $events->getArrayCopy(),
            'Each hook must trigger its event once, in order.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'Dispatching the hooks alone must leave the row active.',
        );
    }

    public function testDeleteCallbackIsEvaluatedOnce(): void
    {
        $item = Item::requireOne(2);

        $calls = 0;

        $item->deleteBehavior()->allowDeleteCallback = static function () use (&$calls): bool {
            return ++$calls === 1;
        };

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be deleted.',
        );
        self::assertSame(
            1,
            $calls,
            'The callback must run once.',
        );
        self::assertNull(
            Item::findOne(2),
            'The row must be removed physically.',
        );
    }

    /**
     * @param list<string> $order
     */
    #[TestWith([true, ['beforeDelete', 'veto']], 'veto appended')]
    #[TestWith([false, ['veto', 'beforeDelete']], 'veto prepended')]
    public function testDeleteVetoInEitherHandlerOrder(bool $append, array $order): void
    {
        $item = Item::requireOne(2);

        $events = self::recordEvents($item, [Item::EVENT_BEFORE_DELETE]);

        $item->on(
            Item::EVENT_BEFORE_DELETE,
            static function (ModelEvent $event) use ($events): void {
                $events[] = 'veto';
                $event->isValid = false;
            },
            append: $append,
        );

        self::forbidEvents(
            $item,
            [Item::EVENT_AFTER_DELETE, DeleteBehavior::EVENT_AFTER_SOFT_DELETE],
        );

        self::assertFalse(
            $item->softDelete(),
            'The veto must cancel the operation.',
        );
        self::assertSame(
            $order,
            $events->getArrayCopy(),
            'Both handlers must run in registration order.',
        );
        self::assertFalse(
            $item->isDeleted,
            'The model must keep its marker.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay active.',
        );
    }

    public function testFailedRollbackPreventsPhysicalFallback(): void
    {
        $fixture = new class extends Item {
            /**
             * @param array<array-key, mixed>|string|null $condition
             * @param array<array-key, mixed> $params
             */
            public static function deleteAll($condition = null, $params = []): int
            {
                throw new IntegrityException('Physical restriction');
            }
        };

        $item = $fixture::requireOne(2);

        Item::getDb()->on(
            Connection::EVENT_ROLLBACK_TRANSACTION,
            static fn() => throw new LogicException('Rollback hook failure'),
        );

        $exception = self::catchThrowable(static fn() => $item->safeDelete());

        self::assertInstanceOf(
            RuntimeException::class,
            $exception,
            'The rollback failure must be reported.',
        );
        self::assertSame(
            "Unable to complete rollback; 'Rollback hook failure'.",
            $exception->getMessage(),
            'The message must carry the rollback failure.',
        );
        self::assertSame(
            0,
            $exception->getCode(),
            'The wrapper has no database error code of its own.',
        );

        $previous = $exception->getPrevious();

        self::assertInstanceOf(
            IntegrityException::class,
            $previous,
            'The cause must be the physical failure.',
        );
        self::assertSame(
            'Physical restriction',
            $previous->getMessage(),
            'The cause must keep its message.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The fallback must not mark the row.',
        );
    }

    public function testFallbackRollsBackWhenSoftDeleteHookFails(): void
    {
        $this->referenceItemFromChildTable('ON DELETE RESTRICT');

        $item = Item::requireOne(2);

        $error = new Error('Soft delete hook failed');

        $item->on(
            DeleteBehavior::EVENT_AFTER_SOFT_DELETE,
            static function () use ($error): never {
                Item::updateAll(['name' => 'failed fallback write'], ['id' => 1]);

                throw $error;
            },
        );

        self::assertSame(
            $error,
            self::catchThrowable(static fn() => $item->deleteBehavior()->safeDelete()),
            'The hook failure must propagate unchanged.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored marker must be rolled back.',
        );
        self::assertFalse(
            $item->isDeleted,
            'The model marker must be restored.',
        );
        self::assertFalse(
            $item->getOldAttribute('isDeleted'),
            'The old marker must be restored.',
        );
        self::assertSame(
            'item1',
            Item::requireOne(1)->name,
            'The hook write must be rolled back.',
        );
        self::assertNull(
            Item::getDb()->getTransaction(),
            'The transaction must be closed.',
        );
    }

    public function testInsertDefaultsPreserveExistingValuesAndTreatCallableStringsAsLiteralValues(): void
    {
        $item = new Item(['deletedAt' => 17]);

        $behavior = $item->deleteBehavior();

        $behavior->restoreAttributeValues = ['deletedAt' => 456, 'name' => 'strlen'];

        $behavior->useRestoreAttributeValuesAsDefaults = true;

        self::assertTrue(
            $item->save(false),
            'The record must be inserted.',
        );

        $stored = Item::requireOne($item->id);

        self::assertSame(
            17,
            $stored->deletedAt,
            "A value other than 'null' must be kept.",
        );
        self::assertSame(
            'strlen',
            $stored->name,
            'A callable string must be stored literally.',
        );
    }

    public function testLockTokenChangeAloneIsNotAWrite(): void
    {
        Item::updateAll(['version' => 0], ['id' => 2]);

        $item = VersionedItem::requireOne(2);

        $item->setAttribute('version', '0');

        self::assertSame(
            0,
            $item->restore(),
            'A lock token change alone must not count as a write.',
        );
        self::assertSame(
            0,
            Item::requireOne(2)->version,
            'The stored version must not be incremented.',
        );
    }

    #[TestWith([false], 'false')]
    #[TestWith([0], 'zero')]
    public function testModelCanVetoDeleteAndRestoreThroughPublicHooks(bool|int $result): void
    {
        $fixture = new class extends Item {
            public bool|int $hookResult = true;

            public function beforeRestore(): bool|int
            {
                return $this->hookResult;
            }

            public function beforeSoftDelete(): bool|int
            {
                return $this->hookResult;
            }
        };

        $item = $fixture::requireOne(2);

        $item->hookResult = $result;

        self::forbidEvents(
            $item,
            [DeleteBehavior::EVENT_BEFORE_SOFT_DELETE, DeleteBehavior::EVENT_BEFORE_RESTORE],
        );

        self::assertFalse(
            $item->softDelete(),
            'The soft-delete hook must veto.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay active.',
        );

        Item::updateAll(['isDeleted' => true], ['id' => 2]);

        $item->refresh();

        self::assertFalse(
            $item->restore(),
            'The restore hook must veto.',
        );
        self::assertTrue(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay deleted.',
        );
    }

    public function testNativeDeleteStillPhysicallyRemovesTheRow(): void
    {
        $item = Item::requireOne(1);

        self::assertSame(
            1,
            $item->delete(),
            'One row must be deleted.',
        );
        self::assertNull(
            Item::findOne(1),
            'The row must be removed.',
        );
    }

    /**
     * @param Closure(Item): (int|false) $write
     */
    #[DataProviderExternal(DeleteBehaviorProvider::class, 'transitionsWithTargetMarker')]
    public function testNoOpTransitionDiscardsPendingMarkerChange(Closure $write, bool $marker): void
    {
        Item::updateAll(['isDeleted' => $marker], ['id' => 2]);

        $item = Item::requireOne(2);

        $item->isDeleted = !$marker;

        self::assertSame(
            0,
            $write($item),
            'A row that already holds the marker must not be written.',
        );
        self::assertSame(
            $marker,
            $item->isDeleted,
            'The model must hold the stored marker.',
        );
        self::assertSame(
            [],
            $item->getDirtyAttributes(),
            'A later save must not write the discarded marker.',
        );
    }

    public function testPublicHooksAndEventsRunInSequence(): void
    {
        $fixture = new class extends Item {
            /** @var ArrayObject<int, string>|null */
            public ArrayObject|null $log = null;

            public function afterRestore(): void
            {
                $this->log?->append('modelAfterRestore');
            }

            public function afterSoftDelete(): void
            {
                $this->log?->append('modelAfterSoftDelete');
            }
        };

        $item = $fixture::requireOne(2);

        $log = self::recordEvents(
            $item,
            [
                Item::EVENT_BEFORE_DELETE,
                Item::EVENT_AFTER_DELETE,
                DeleteBehavior::EVENT_AFTER_SOFT_DELETE,
                DeleteBehavior::EVENT_BEFORE_RESTORE,
                DeleteBehavior::EVENT_AFTER_RESTORE,
            ],
        );

        $item->log = $log;

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            1,
            $item->restore(),
            'One row must be restored.',
        );
        self::assertSame(
            [
                'beforeDelete',
                'modelAfterSoftDelete',
                'afterSoftDelete',
                'afterDelete',
                'beforeRestore',
                'modelAfterRestore',
                'afterRestore',
            ],
            $log->getArrayCopy(),
            'Each model hook must run before its event, inside the delete events.',
        );
    }

    public function testRestore(): void
    {
        $item = Item::requireOne(2);

        $item->softDelete();

        self::assertSame(
            1,
            $item->restore(),
            'One row must be restored.',
        );
        self::assertFalse(
            $item->isDeleted,
            'The model marker must be cleared.',
        );
    }

    /**
     * @param class-string<Item|VersionedItem> $class
     */
    #[TestWith([Item::class], 'without lock')]
    #[TestWith([VersionedItem::class], 'with optimistic lock')]
    public function testRestoreAssignsMarkerValuesThatMatchTheStoredRow(string $class): void
    {
        Item::updateAll(['version' => 0], ['id' => 2]);

        $item = $class::requireOne(2);

        $item->isDeleted = true;
        $item->name = 'renamed';

        self::assertSame(
            1,
            $item->restore(),
            'The pending name change must be written.',
        );
        self::assertFalse(
            $item->isDeleted,
            'The restore marker must replace the pending marker change.',
        );
        self::assertSame(
            [],
            $item->getDirtyAttributes(),
            'The model must match the stored row.',
        );
        self::assertSame(
            'renamed',
            Item::requireOne(2)->name,
            'The pending name change must be stored.',
        );
    }

    public function testRestoreVetoRollsBackTransactionalHookChanges(): void
    {
        Item::updateAll(['isDeleted' => true], ['id' => 2]);

        $item = TransactionalItem::requireOne(2);

        $item->operations = Item::OP_UPDATE;

        $item->on(
            DeleteBehavior::EVENT_BEFORE_RESTORE,
            static function (ModelEvent $event) use ($item): void {
                Item::updateAll(['name' => 'veto write'], ['id' => 1]);

                $item->name = 'veto changed the model';
                $event->isValid = false;
            },
        );

        self::forbidEvents(
            $item,
            [DeleteBehavior::EVENT_AFTER_RESTORE],
        );
        self::assertFalse(
            $item->restore(),
            'The veto must cancel the restoration.',
        );
        self::assertTrue(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay deleted.',
        );
        self::assertTrue(
            $item->isDeleted,
            'The model must keep its marker.',
        );
        self::assertSame(
            'item2',
            $item->name,
            'The handler change to the model must be reverted.',
        );
        self::assertSame(
            'item1',
            Item::requireOne(1)->name,
            'The handler write must be rolled back.',
        );
    }

    public function testRollbackFailureRetainsTheOriginalErrorAsCause(): void
    {
        $item = Item::requireOne(2);

        $error = new RuntimeException(
            'Original hook failure',
        );

        $item->on(
            Item::EVENT_BEFORE_DELETE,
            static fn() => throw $error,
        );

        Item::getDb()->on(
            Connection::EVENT_ROLLBACK_TRANSACTION,
            static fn() => throw new RuntimeException('Rollback hook failure'),
        );

        $exception = self::catchThrowable(static fn() => $item->safeDelete());

        self::assertInstanceOf(
            RuntimeException::class,
            $exception,
            'The rollback failure must be reported.',
        );
        self::assertSame(
            $error,
            $exception->getPrevious(),
            'The original error must be the cause.',
        );
        self::assertSame(
            Message::ROLLBACK_FAILED->getMessage('Rollback hook failure'),
            $exception->getMessage(),
            'The message must carry the rollback failure.',
        );
        self::assertSame(
            0,
            $exception->getCode(),
            'The wrapper has no database error code of its own.',
        );
        self::assertNull(
            Item::getDb()->getTransaction(),
            'The transaction must be closed.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay active.',
        );
    }

    #[TestWith([true], 'veto')]
    #[TestWith([false], 'after hook failure')]
    public function testRolledBackTransactionKeepsPartiallyLoadedRecordIntact(bool $veto): void
    {
        Item::updateAll(['isDeleted' => true], ['id' => 2]);

        $item = TransactionalItem::find()
            ->select(['id', 'name'])
            ->where(['id' => 2])
            ->one();

        self::assertInstanceOf(
            TransactionalItem::class,
            $item,
            'The partial record must be loaded.',
        );

        $item->operations = Item::OP_UPDATE;

        $item->name = 'pending';

        $error = new RuntimeException(
            'After restore failed',
        );

        $item->on(
            DeleteBehavior::EVENT_BEFORE_RESTORE,
            static function (ModelEvent $event) use ($item, $veto): void {
                $item->deletedAt = 5;
                $event->isValid = !$veto;
            },
        );
        $item->on(
            DeleteBehavior::EVENT_AFTER_RESTORE,
            static fn() => throw $error,
        );

        try {
            $result = $item
                ->deleteBehavior()
                ->restore();
        } catch (RuntimeException $exception) {
            $result = $exception;
        }

        self::assertSame(
            $veto ? false : $error,
            $result,
            'A veto must cancel and a hook failure must propagate.',
        );
        self::assertSame(
            ['name' => 'pending'],
            $item->getDirtyAttributes(),
            'Only the change made before the call may remain.',
        );
        self::assertSame(
            ['id' => 2, 'name' => 'item2'],
            $item->getOldAttributes(),
            'Old attributes must stay as loaded.',
        );
        self::assertTrue(
            $item->save(false),
            'The record must remain savable.',
        );

        $stored = Item::requireOne(2);

        self::assertSame(
            'pending',
            $stored->name,
            'The pending change must be stored.',
        );
        self::assertSame(
            2,
            $stored->categoryId,
            'Unloaded columns must keep their stored values.',
        );
        self::assertTrue(
            $stored->isDeleted,
            'The rolled-back marker must not be stored.',
        );
        self::assertNull(
            $stored->deletedAt,
            'The hook assignment must not be stored.',
        );
    }

    public function testSafeDeleteFallbackKeepsPartiallyLoadedRecordIntact(): void
    {
        $fixture = new class extends Item {
            /**
             * @param array<array-key, mixed>|string|null $condition
             * @param array<array-key, mixed> $params
             */
            public static function deleteAll($condition = null, $params = []): int
            {
                throw new IntegrityException(
                    'Physical restriction',
                );
            }
        };

        $item = $fixture::find()
            ->select(['id', 'name'])
            ->where(['id' => 2])
            ->one();

        self::assertInstanceOf(
            $fixture::class,
            $item,
            'The partial record must be loaded.',
        );

        $item->name = 'pending';

        $item->on(
            Item::EVENT_BEFORE_DELETE,
            static function () use ($item): void {
                $item->deletedAt = 9;
            },
        );

        self::assertSame(
            1,
            $item->safeDelete(),
            'The fallback must mark one row.',
        );
        self::assertSame(
            [],
            $item->getDirtyAttributes(),
            'Neither the marker nor unloaded columns may stay dirty.',
        );
        self::assertSame(
            ['id' => 2, 'name' => 'pending', 'isDeleted' => true],
            $item->getOldAttributes(),
            'Old attributes must hold the loaded columns and the written values.',
        );

        $stored = Item::requireOne(2);

        self::assertTrue(
            $stored->isDeleted,
            'The stored row must be marked.',
        );
        self::assertSame(
            'pending',
            $stored->name,
            'The pending change must be stored with the marker.',
        );
        self::assertSame(
            2,
            $stored->categoryId,
            'Unloaded columns must keep their stored values.',
        );
        self::assertNull(
            $stored->deletedAt,
            'The rolled-back hook assignment must not be stored.',
        );
    }

    public function testSafeDeleteFallsBackForConfiguredExceptionSubclass(): void
    {
        $fixture = new class extends Item {
            /**
             * @param array<array-key, mixed>|string|null $condition
             * @param array<array-key, mixed> $params
             */
            public static function deleteAll($condition = null, $params = []): int
            {
                throw new DomainException(
                    'Physical delete rejected',
                );
            }
        };

        $item = $fixture::requireOne(2);

        $item->deleteBehavior()->deleteFallbackException = LogicException::class;

        self::assertSame(
            1,
            $item->safeDelete(),
            'The fallback must mark one row.',
        );
        self::assertTrue(
            Item::requireOne(2)->isDeleted,
            'The stored row must be marked.',
        );
        self::assertFalse(
            $item->isNewRecord,
            'The model must stay persisted.',
        );
    }

    public function testSafeDeleteFallsBackForRestrictiveForeignKey(): void
    {
        $this->referenceItemFromChildTable('ON DELETE RESTRICT');

        $item = Item::requireOne(2);

        $events = self::recordEvents(
            $item,
            [
                Item::EVENT_BEFORE_DELETE,
                Item::EVENT_AFTER_DELETE,
                DeleteBehavior::EVENT_BEFORE_SOFT_DELETE,
                DeleteBehavior::EVENT_AFTER_SOFT_DELETE,
            ],
        );

        self::assertSame(
            1,
            $item->safeDelete(),
            'The fallback must mark one row.',
        );
        self::assertTrue(
            Item::requireOne(2)->isDeleted,
            'The stored row must be marked.',
        );
        self::assertTrue(
            $item->isDeleted,
            'The model must receive the marker.',
        );
        self::assertFalse(
            $item->isNewRecord,
            'The model must stay persisted.',
        );
        self::assertSame(
            2,
            (int) Item::getDb()->createCommand('SELECT itemId FROM Child')->queryScalar(),
            'The child row must keep its reference.',
        );
        self::assertSame(
            ['beforeDelete', 'beforeSoftDelete', 'afterSoftDelete'],
            $events->getArrayCopy(),
            "The fallback must neither repeat 'beforeDelete' nor emit 'afterDelete'.",
        );
        self::assertNull(
            Item::getDb()->getTransaction(),
            'The transaction must be closed.',
        );
    }

    public function testSafeDeleteLocksThePhysicalAttempt(): void
    {
        Item::updateAll(['version' => 1], ['id' => 2]);

        $stale = VersionedItem::requireOne(2);

        Item::updateAll(['version' => 2], ['id' => 2]);

        self::assertInstanceOf(
            StaleObjectException::class,
            self::catchThrowable(static fn() => $stale->deleteBehavior()->safeDelete()),
            'A stale lock must block physical deletion.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stale attempt must not fall back to a mark.',
        );
        self::assertSame(
            1,
            $stale->version,
            'The model must keep its version.',
        );
        self::assertSame(
            1,
            VersionedItem::requireOne(2)->safeDelete(),
            'A current lock must allow deletion.',
        );
        self::assertNull(
            Item::findOne(2),
            'The row must be removed.',
        );
    }

    public function testSafeDeletePhysicallyDeletesUnreferencedRow(): void
    {
        $item = Item::requireOne(1);

        self::assertSame(
            1,
            $item->safeDelete(),
            'One row must be deleted.',
        );
        self::assertNull(
            Item::findOne(1),
            'The row must be removed.',
        );
        self::assertTrue(
            $item->isNewRecord,
            'The model must become new.',
        );
        self::assertFalse(
            Item::getDb()->hasEventHandlers(Connection::EVENT_COMMIT_TRANSACTION),
            'The commit handler must be detached.',
        );
    }

    public function testSafeDeletePropagatesOriginalAuditExceptionAndRestoresModel(): void
    {
        $db = Item::getDb();

        $db
            ->createCommand(
                <<<SQL
                CREATE TABLE AuditLog (message TEXT NOT NULL)
                SQL
            )
            ->execute();

        $item = Item::requireOne(2);

        $original = $item->getOldAttributes();

        $captured = new class {
            public IntegrityException|null $error = null;
        };

        $item->on(
            Item::EVENT_AFTER_DELETE,
            static function () use ($db, $item, $captured): void {
                self::assertNull(
                    Item::findOne(2),
                    'The physical delete must precede this hook.',
                );

                $item->name = 'audit changed the model';

                try {
                    $db->createCommand()->insert('AuditLog', ['message' => null])->execute();
                } catch (IntegrityException $exception) {
                    $captured->error = $exception;
                    throw $exception;
                }
            },
        );

        self::forbidEvents(
            $item,
            [DeleteBehavior::EVENT_AFTER_SOFT_DELETE],
        );

        $exception = self::catchThrowable(static fn() => $item->deleteBehavior()->safeDelete());

        self::assertSame(
            $captured->error,
            $exception,
            'The audit failure must propagate unchanged.',
        );
        self::assertSame(
            $original,
            Item::requireOne(2)->getAttributes(),
            'The stored row must be unchanged.',
        );
        self::assertSame(
            $original,
            $item->getAttributes(),
            'The model attributes must be restored.',
        );
        self::assertSame(
            $original,
            $item->getOldAttributes(),
            'The old attributes must be restored.',
        );
        self::assertFalse(
            $item->isNewRecord,
            'The model must stay persisted.',
        );
        self::assertSame(
            0,
            (int) $db->createCommand('SELECT COUNT(*) FROM AuditLog')->queryScalar(),
            'No audit row may remain.',
        );
        self::assertNull(
            $db->getTransaction(),
            'The transaction must be closed.',
        );
    }

    public function testSafeDeleteVetoRollsBackHookWriteAndKeepsOuterTransaction(): void
    {
        $db = Item::getDb();

        $outer = $db->beginTransaction();
        try {
            Item::updateAll(['name' => 'outer write'], ['id' => 1]);

            $item = Item::requireOne(2);

            $item->on(
                Item::EVENT_BEFORE_DELETE,
                static function (ModelEvent $event) use ($item): void {
                    Item::updateAll(['name' => 'veto write'], ['id' => 1]);

                    $item->name = 'veto changed the model';
                    $event->isValid = false;
                },
            );

            self::assertFalse(
                $item->safeDelete(),
                'The veto must cancel the deletion.',
            );
            self::assertSame(
                'outer write',
                Item::requireOne(1)->name,
                'The outer write must survive.',
            );
            self::assertSame(
                'item2',
                $item->name,
                'The handler change to the model must be reverted.',
            );
            self::assertSame(
                1,
                $outer->getLevel(),
                'The outer transaction must stay open.',
            );
        } finally {
            $outer->rollBack();
        }

        self::assertSame(
            'item1',
            Item::requireOne(1)->name,
            'The outer rollback must revert the outer write.',
        );
    }

    public function testSoftDelete(): void
    {
        $item = Item::requireOne(2);

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertTrue(
            $item->isDeleted,
            'The model must receive the marker.',
        );
    }

    public function testSoftDeleteAndRestoreIncrementTheLockVersion(): void
    {
        $item = new VersionedItem();

        $item->name = 'optimistic lock';
        $item->version = 1;

        $item->save(false);

        $item = VersionedItem::requireOne($item->id);

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            2,
            $item->version,
            'The marker write must increment the version.',
        );

        $item = VersionedItem::requireOne($item->id);

        self::assertSame(
            1,
            $item->restore(),
            'One row must be restored.',
        );
        self::assertSame(
            3,
            $item->version,
            'The restore write must increment the version.',
        );
    }

    public function testSoftDeletePersistsDirtyFieldsAndPublicHookChanges(): void
    {
        $fixture = new class extends Item {
            public function beforeSoftDelete(): bool
            {
                $this->deletedAt = 800;
                return true;
            }
        };

        $item = $fixture::requireOne(2);

        $item->name = 'manual change';

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );

        $stored = Item::requireOne(2);

        self::assertSame(
            'manual change',
            $stored->name,
            'The pending change must be stored with the marker.',
        );
        self::assertSame(
            800,
            $stored->deletedAt,
            'The hook change must be stored with the marker.',
        );
    }

    #[TestWith([0, false], 'not transactional')]
    #[TestWith([Item::OP_UPDATE, true], 'update transactional')]
    #[TestWith([Item::OP_DELETE, true], 'delete transactional')]
    public function testSoftDeleteUsesTheConfiguredTransactionAndCallsHooksOnce(int $operations, bool $transactional): void
    {
        $item = TransactionalItem::requireOne(2);

        $item->operations = $operations;

        $calls = 0;

        $item->on(
            DeleteBehavior::EVENT_BEFORE_SOFT_DELETE,
            static function () use ($transactional, &$calls): void {
                ++$calls;

                self::assertSame(
                    $transactional,
                    Item::getDb()->getTransaction() !== null,
                    'A transaction must be open only when configured.',
                );
            },
        );

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            1,
            $calls,
            'The hook must run once.',
        );
    }

    public function testSoftDeleteVetoDoesNotEmitSuccessEvents(): void
    {
        $item = Item::requireOne(2);

        $item->on(
            DeleteBehavior::EVENT_BEFORE_SOFT_DELETE,
            static function (ModelEvent $event): void {
                $event->isValid = false;
            },
        );

        self::forbidEvents(
            $item,
            [Item::EVENT_AFTER_DELETE, DeleteBehavior::EVENT_AFTER_SOFT_DELETE],
        );
        self::assertFalse(
            $item->softDelete(),
            'The veto must cancel the operation.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay active.',
        );
    }

    public function testSoftDeleteWithoutDeleteEventsStillRunsSoftDeleteHooks(): void
    {
        $item = Item::requireOne(2);

        $item->deleteBehavior()->invokeDeleteEvents = false;

        $item->on(
            Item::EVENT_BEFORE_DELETE,
            static function (ModelEvent $event): void {
                $event->isValid = false;
            },
        );

        $events = self::recordEvents(
            $item,
            [
                Item::EVENT_BEFORE_DELETE,
                Item::EVENT_AFTER_DELETE,
                DeleteBehavior::EVENT_BEFORE_SOFT_DELETE,
                DeleteBehavior::EVENT_AFTER_SOFT_DELETE,
            ],
        );

        self::assertSame(
            1,
            $item->softDelete(),
            'The delete veto must not apply.',
        );
        self::assertSame(
            ['beforeSoftDelete', 'afterSoftDelete'],
            $events->getArrayCopy(),
            'Only the soft-delete events may run.',
        );
        self::assertTrue(
            Item::requireOne(2)->isDeleted,
            'The stored row must be marked.',
        );
    }

    public function testThrowIntegrityExceptionWhenBeforeDeleteFails(): void
    {
        $item = Item::requireOne(2);

        $error = new IntegrityException('Before hook failed');

        $item->on(
            Item::EVENT_BEFORE_DELETE,
            static function () use ($error): never {
                throw $error;
            },
        );

        self::assertSame(
            $error,
            self::catchThrowable(static fn() => $item->deleteBehavior()->safeDelete()),
            'The hook failure must propagate instead of falling back.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay active.',
        );
        self::assertFalse(
            $item->isDeleted,
            'The model must not be marked.',
        );
    }

    public function testThrowInvalidConfigExceptionForBehaviorWithoutRecord(): void
    {
        $this->expectException(InvalidConfigException::class);

        (new DeleteBehavior())->softDelete();
    }

    public function testThrowInvalidConfigExceptionForInvalidDeleteCallback(): void
    {
        $item = Item::requireOne(2);

        $item->deleteBehavior()->allowDeleteCallback = static fn(): int => 1;

        $this->expectException(InvalidConfigException::class);

        $item->softDelete();
    }

    public function testThrowInvalidConfigExceptionForInvalidFallbackClass(): void
    {
        $item = Item::requireOne(2);

        $item->deleteBehavior()->deleteFallbackException = 'UnknownException';

        $this->expectException(InvalidConfigException::class);

        $item->safeDelete();
    }

    /**
     * @param array<string, mixed>|string $key
     */
    #[TestWith([[]], 'empty array')]
    #[TestWith(['2'], 'scalar')]
    public function testThrowInvalidConfigExceptionForInvalidPrimaryKeyShape(array|string $key): void
    {
        $fixture = new class extends Item {
            /** @var array<string, mixed>|string */
            public array|string $key = [];

            /** @return array<string, mixed>|string */
            public function getOldPrimaryKey($asArray = false): array|string
            {
                return $this->key;
            }
        };

        $item = $fixture::requireOne(2);

        $item->key = $key;

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::PERSISTED_PRIMARY_KEY_REQUIRED->getMessage(),
        );
        try {
            $item->safeDelete();
        } finally {
            self::assertSame(
                2,
                (int) Item::find()->count(),
                'An invalid condition must leave both rows in place.',
            );
            self::assertNull(
                Item::getDb()->getTransaction(),
                'The transaction must close after rejecting the key.',
            );
        }
    }

    public function testThrowInvalidConfigExceptionForNonSqlSafeDelete(): void
    {
        $behavior = new DeleteBehavior();

        $behavior->attach(self::createStub(BaseActiveRecord::class));

        $this->expectException(InvalidConfigException::class);

        $behavior->safeDelete();
    }

    /**
     * @param Closure(Item): (int|false) $write
     */
    #[DataProviderExternal(DeleteBehaviorProvider::class, 'transitionsWithTargetMarker')]
    public function testThrowInvalidConfigExceptionForNoOpWriteWithoutPrimaryKey(Closure $write, bool $marker): void
    {
        Item::updateAll(['isDeleted' => $marker], ['id' => 2]);

        $item = Item::find()->select(['name', 'isDeleted'])->where(['id' => 2])->one();

        self::assertInstanceOf(
            Item::class,
            $item,
            'The partial record must be loaded.',
        );
        self::assertSame(
            $marker,
            $item->isDeleted,
            'The loaded marker must already hold the target value.',
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::PERSISTED_PRIMARY_KEY_REQUIRED->getMessage(),
        );

        $write($item);
    }

    public function testThrowInvalidConfigExceptionForProtectedHook(): void
    {
        $fixture = new class extends Item {
            protected function beforeSoftDelete(): bool
            {
                return true;
            }
        };

        $item = $fixture::requireOne(2);

        self::assertSame(
            "ActiveRecord hook 'beforeSoftDelete' must be public.",
            Message::HOOK_MUST_BE_PUBLIC->getMessage('beforeSoftDelete'),
            'The template must name the hook.',
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::HOOK_MUST_BE_PUBLIC->getMessage('beforeSoftDelete'),
        );

        $item->softDelete();
    }

    public function testThrowInvalidConfigExceptionForRecordWithoutItsPrimaryKey(): void
    {
        $item = Item::find()
            ->select('name')
            ->where(['id' => 2])
            ->one();

        self::assertInstanceOf(
            Item::class,
            $item,
            'The partial record must be loaded.',
        );

        $this->expectException(InvalidConfigException::class);

        $item->safeDelete();
    }

    #[TestWith(['strlen'], 'callable string')]
    #[TestWith([null], 'null')]
    #[TestWith([5], 'integer other than zero or one')]
    public function testThrowInvalidConfigExceptionForRestoreWithoutAnInferableDefault(int|string|null $marker): void
    {
        $behavior = new class (['softDeleteAttributeValues' => ['name' => $marker]]) extends DeleteBehavior {};

        self::assertSame(
            'Unable to determine restore attribute values automatically; \'' . $behavior::class
            . '::restoreAttributeValues\' must be set explicitly.',
            Message::RESTORE_ATTRIBUTE_VALUES_NOT_INFERABLE->getMessage($behavior::class),
            'The template must name the behavior class.',
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::RESTORE_ATTRIBUTE_VALUES_NOT_INFERABLE->getMessage($behavior::class),
        );

        $behavior->resolveRestoreAttributeValues();
    }

    /**
     * @param Closure(Item): (int|false) $write
     */
    #[DataProviderExternal(DeleteBehaviorProvider::class, 'transitions')]
    public function testThrowInvalidConfigExceptionForWriteWithoutPrimaryKey(Closure $write): void
    {
        $item = Item::find()
            ->select(['name'])
            ->where(['id' => 2])
            ->one();

        self::assertInstanceOf(
            Item::class,
            $item,
            'The partial record must be loaded.',
        );

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::PERSISTED_PRIMARY_KEY_REQUIRED->getMessage(),
        );

        try {
            $write($item);
        } finally {
            self::assertSame(
                [],
                $item->getDirtyAttributes(),
                'The marker must not be assigned to the model.',
            );
        }
    }

    public function testThrowInvalidConfigExceptionWhenTransactionalRecordHasNoSqlConnection(): void
    {
        $item = new class extends TransactionalItem {
            public static function getDb()
            {
                return new stdClass(); // @phpstan-ignore return.type (Intentionally give the guard a non-SQL connection.)
            }
        };

        $item->operations = Item::OP_DELETE;

        $item->setOldAttributes(['id' => 2]);
        $item->deleteBehavior()->allowDeleteCallback = null;

        $this->expectException(InvalidConfigException::class);

        $item->softDelete();
    }

    public function testThrowLogicExceptionWhenAttributeSnapshotFails(): void
    {
        $item = new class extends Item {
            /**
             * @param array<array-key, mixed>|null $names
             * @return array<string, mixed>
             */
            public function getDirtyAttributes($names = null): array
            {
                throw new LogicException(
                    'Could not read model attributes',
                );
            }
        };

        $item->setOldAttributes(['id' => 2]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Could not read model attributes',
        );

        try {
            $item->safeDelete();
        } finally {
            self::assertNull(
                Item::getDb()->getTransaction(),
                'No transaction may be started.',
            );
        }
    }

    public function testThrowNotSupportedExceptionWhenNestedSavepointsAreDisabled(): void
    {
        $db = Item::getDb();

        $db->enableSavepoint = false;

        $outer = $db->beginTransaction();

        $this->expectException(NotSupportedException::class);

        try {
            Item::requireOne(2)->safeDelete();
        } finally {
            self::assertSame(
                1,
                $outer->getLevel(),
                'The outer transaction must stay open.',
            );
            self::assertFalse(
                Item::requireOne(2)->isDeleted,
                'The stored row must stay active.',
            );

            $outer->rollBack();
        }
    }

    public function testThrowPDOExceptionWhenCommitFailsAfterDriverDiscardsTransaction(): void
    {
        $this->mockApplication(['pdoClass' => CommitRejectingPdo::class]);

        $db = Item::getDb();
        $item = Item::requireOne(2);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage(
            'Commit rejected.',
        );

        try {
            $item->safeDelete();
        } finally {
            self::assertInstanceOf(
                PDO::class,
                $db->pdo,
                'The connection must stay open.',
            );
            self::assertFalse(
                $db->pdo->inTransaction(),
                'The driver must hold no transaction.',
            );
            self::assertFalse(
                $item->isNewRecord,
                'The model must stay persisted.',
            );
            self::assertSame(
                [],
                $item->getDirtyAttributes(),
                'The model must match the stored row.',
            );
            self::assertNotNull(
                Item::findOne(2),
                'The stored row must remain.',
            );
            self::assertFalse(
                $db->hasEventHandlers(Connection::EVENT_COMMIT_TRANSACTION),
                'The commit handler must be detached.',
            );
        }
    }

    public function testThrowPDOExceptionWhenDeferredConstraintFailsAtCommit(): void
    {
        $this->referenceItemFromChildTable('DEFERRABLE INITIALLY DEFERRED');

        $db = Item::getDb();
        $item = Item::requireOne(2);

        $this->expectException(PDOException::class);
        try {
            $item->safeDelete();
        } finally {
            self::assertInstanceOf(
                PDO::class,
                $db->pdo,
                'The connection must stay open.',
            );
            self::assertFalse(
                $db->pdo->inTransaction(),
                'The PDO transaction must be rolled back.',
            );
            self::assertFalse(
                $item->isNewRecord,
                'The model must stay persisted.',
            );
            self::assertFalse(
                $item->isDeleted,
                'The model must not be marked.',
            );
            self::assertFalse(
                Item::requireOne(2)->isDeleted,
                'The stored row must stay active.',
            );
        }
    }

    public function testThrowRuntimeExceptionWhenPhysicalDeleteFailsOutsideFallbackType(): void
    {
        $fixture = new class extends Item {
            /**
             * @param array<array-key, mixed>|string|null $condition
             * @param array<array-key, mixed> $params
             */
            public static function deleteAll($condition = null, $params = []): int
            {
                throw new RuntimeException('Physical attempt failed.');
            }
        };

        $item = $fixture::requireOne(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Physical attempt failed.',
        );

        $item->safeDelete();
    }

    #[TestWith(['invalid token'], 'non-numeric string')]
    #[TestWith([true], 'boolean')]
    public function testThrowStaleObjectExceptionForInvalidLockToken(string|bool $token): void
    {
        Item::updateAll(['version' => 1], ['id' => 2]);

        $item = VersionedItem::requireOne(2);

        $item->setAttribute('version', $token);

        $this->expectException(StaleObjectException::class);

        $item->softDelete();
    }

    #[TestWith(['invalid token'], 'non-numeric string')]
    #[TestWith([-1], 'negative integer')]
    public function testThrowStaleObjectExceptionForInvalidLockTokenOnNoOpWrite(string|int $token): void
    {
        Item::updateAll(['version' => 1], ['id' => 2]);

        $item = VersionedItem::requireOne(2);

        $item->setAttribute('version', $token);

        $this->expectException(StaleObjectException::class);
        $this->expectExceptionMessage(
            Message::OPTIMISTIC_LOCK_MUST_BE_NON_NEGATIVE_INTEGER->getMessage(),
        );

        try {
            $item->restore();
        } finally {
            self::assertSame(
                1,
                Item::requireOne(2)->version,
                'The stored version must stay unchanged.',
            );
        }
    }

    public function testThrowStaleObjectExceptionWhenLockVersionCannotBeIncremented(): void
    {
        Item::updateAll(['version' => PHP_INT_MAX], ['id' => 2]);

        $item = VersionedItem::requireOne(2);

        $this->expectException(StaleObjectException::class);
        $this->expectExceptionMessage(
            Message::OPTIMISTIC_LOCK_CANNOT_BE_INCREMENTED->getMessage(),
        );

        $item->softDelete();
    }

    public function testThrowStaleObjectExceptionWhenLockVersionIsOutdated(): void
    {
        Item::updateAll(['version' => 3], ['id' => 2]);

        $item = VersionedItem::requireOne(2);

        $item->version = 0;

        $this->expectException(StaleObjectException::class);
        $this->expectExceptionMessage(
            Message::UPDATED_OBJECT_OUTDATED->getMessage(),
        );

        $item->softDelete();
    }

    public function testTransactionalRestoreCommitsTheRestoration(): void
    {
        Item::updateAll(['isDeleted' => true], ['id' => 2]);

        $item = TransactionalItem::requireOne(2);

        $item->operations = Item::OP_UPDATE;

        $levels = [];

        $item->on(
            DeleteBehavior::EVENT_AFTER_RESTORE,
            static function () use (&$levels): void {
                $levels[] = Item::getDb()->getTransaction()?->getLevel();
            },
        );

        self::assertSame(
            1,
            $item->restore(),
            'One row must be restored.',
        );
        self::assertSame(
            [1],
            $levels,
            'The hook must run inside the transaction.',
        );
        self::assertNull(
            Item::getDb()->getTransaction(),
            'The transaction must be closed.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The restoration must be committed.',
        );
        self::assertFalse(
            $item->getOldAttribute('isDeleted'),
            'The model must reflect the stored row.',
        );
    }

    public function testTransactionalRestoreRollsBackWhenAfterHookFails(): void
    {
        Item::updateAll(['isDeleted' => true], ['id' => 2]);

        $item = TransactionalItem::requireOne(2);

        $item->operations = Item::OP_UPDATE;

        $error = new RuntimeException(
            'After restore failed',
        );

        $item->on(
            DeleteBehavior::EVENT_AFTER_RESTORE,
            static function () use ($error): never {
                Item::updateAll(['name' => 'failed restore write'], ['id' => 1]);

                throw $error;
            },
        );

        self::assertSame(
            $error,
            self::catchThrowable(static fn() => $item->restore()),
            'The original exception must propagate.',
        );
        self::assertTrue(
            Item::requireOne(2)->isDeleted,
            'The restoration must be rolled back.',
        );
        self::assertSame(
            'item1',
            Item::requireOne(1)->name,
            'The hook write must be rolled back.',
        );
        self::assertTrue(
            $item->isDeleted,
            'The model must keep its marker.',
        );
        self::assertTrue(
            $item->getOldAttribute('isDeleted'),
            'The old marker must be restored.',
        );
        self::assertNull(
            Item::getDb()->getTransaction(),
            'The transaction must be closed.',
        );
    }

    public function testTransactionalSoftDeleteRestoresModelAfterHookFailure(): void
    {
        $item = TransactionalItem::requireOne(2);

        $item->operations = Item::OP_DELETE;

        $error = new RuntimeException(
            'After hook failure',
        );

        $item->on(
            Item::EVENT_AFTER_DELETE,
            static fn() => throw $error,
        );

        self::assertSame(
            $error,
            self::catchThrowable(static fn() => $item->softDelete()),
            'The original exception must propagate.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The marker must be rolled back.',
        );
        self::assertFalse(
            $item->isDeleted,
            'The model marker must be restored.',
        );
    }

    public function testUnsavedVersionedRecordReturnsZero(): void
    {
        $item = new VersionedItem(['id' => 2, 'version' => 1]);

        self::assertSame(
            0,
            $item->softDelete(),
            'An unsaved record must not be marked.',
        );
        self::assertSame(
            0,
            $item->restore(),
            'An unsaved record must not be restored.',
        );
        self::assertSame(
            0,
            $item->safeDelete(),
            'An unsaved record must not be deleted.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row with the same id must stay active.',
        );
    }

    public function testUseRestoreAttributeValuesAsDefaults(): void
    {
        $category = new Category();

        $category->name = 'apply restore attribute';

        $category->save(false);

        self::assertFalse(
            $category->isDeleted,
            "A 'null' marker must receive the restore value.",
        );

        $category = new Category();

        $category->name = 'prevent restore attribute application';
        $category->isDeleted = true;

        $category->save(false);

        self::assertTrue(
            $category->isDeleted,
            'An explicit marker must be kept.',
        );
    }

    public function testVersionedNoOpRestoreKeepsTheMaximumLockVersion(): void
    {
        Item::updateAll(['version' => PHP_INT_MAX], ['id' => 2]);

        $item = VersionedItem::requireOne(2);

        self::assertSame(
            0,
            $item->restore(),
            'An active row must not be written.',
        );
        self::assertSame(
            PHP_INT_MAX,
            $item->version,
            'The lock version must not be incremented.',
        );
    }

    public function testVersionedSoftDeleteTargetsOnlyItsOwnRow(): void
    {
        Item::updateAll(['version' => 1]);

        $item = VersionedItem::requireOne(2);

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );

        $other = Item::requireOne(1);

        self::assertFalse(
            $other->isDeleted,
            'A row sharing the version must stay active.',
        );
        self::assertSame(
            1,
            $other->version,
            'A row sharing the version must keep it.',
        );
    }

    public function testVetoExposesItsRollbackError(): void
    {
        $item = Item::requireOne(2);

        $item->on(
            Item::EVENT_BEFORE_DELETE,
            static function (ModelEvent $event): void {
                $event->isValid = false;
            },
        );

        $error = new LogicException(
            'Rollback hook failure',
        );

        Item::getDb()->on(
            Connection::EVENT_ROLLBACK_TRANSACTION,
            static fn() => throw $error,
        );

        self::assertSame(
            $error,
            self::catchThrowable(static fn() => $item->safeDelete()),
            'The rollback error must propagate unwrapped.',
        );
        self::assertFalse(
            Item::requireOne(2)->isDeleted,
            'The stored row must stay active.',
        );
    }

    #[TestWith([0], 'integer')]
    #[TestWith(['0'], 'numeric string')]
    public function testZeroLockTokenIsValidAndPreservesOldIdentity(int|string $version): void
    {
        Item::updateAll(['version' => 0], ['id' => 2]);

        $item = VersionedItem::requireOne(2);

        $item->setAttribute('version', $version);

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            1,
            $item->getOldAttribute('version'),
            'The old version must hold the incremented value.',
        );
        self::assertSame(
            2,
            $item->getOldPrimaryKey(),
            'The old primary key must be kept.',
        );
        self::assertTrue(
            $item->getOldAttribute('isDeleted'),
            'The old marker must hold the written value.',
        );
    }

    public function testZeroRowsDoNotEmitSuccessEvents(): void
    {
        $item = Item::requireOne(2);

        self::forbidEvents(
            $item,
            [
                Item::EVENT_AFTER_DELETE,
                DeleteBehavior::EVENT_AFTER_SOFT_DELETE,
                DeleteBehavior::EVENT_AFTER_RESTORE,
            ],
        );

        self::assertSame(
            0,
            $item->restore(),
            'An active row must not be written.',
        );

        Item::deleteAll(['id' => 2]);

        self::assertSame(
            0,
            $item->softDelete(),
            'A missing row must not be marked.',
        );
        self::assertFalse(
            $item->isDeleted,
            'The model must not be marked.',
        );
        self::assertSame(
            0,
            $item->safeDelete(),
            'A missing row must not be deleted.',
        );
    }

    /**
     * @param Closure(Item): (int|false) $write
     */
    #[DataProviderExternal(DeleteBehaviorProvider::class, 'transitions')]
    public function testZeroRowWriteKeepsPartiallyLoadedRecordIntact(Closure $write): void
    {
        $item = Item::find()
            ->select(['id', 'name'])
            ->where(['id' => 2])
            ->one();

        self::assertInstanceOf(Item::class, $item, 'The partial record must be loaded.');

        $item->name = 'pending';

        Item::deleteAll(['id' => 2]);

        self::assertSame(
            0,
            $write($item),
            'No row may match.',
        );
        self::assertSame(
            ['name' => 'pending'],
            $item->getDirtyAttributes(),
            'Neither the marker nor unloaded columns may become dirty.',
        );
        self::assertSame(
            ['id' => 2, 'name' => 'item2'],
            $item->getOldAttributes(),
            'Old attributes must stay as loaded.',
        );
    }

    /**
     * Enables foreign keys and adds a `Child` row that references item `2` under the given constraint clause.
     */
    private function referenceItemFromChildTable(string $constraint): void
    {
        $db = Item::getDb();

        $db
            ->createCommand(
                <<<SQL
                PRAGMA foreign_keys = ON
                SQL
            )
            ->execute();
        $db
            ->createCommand(
                <<<SQL
                CREATE TABLE Child (itemId INTEGER REFERENCES Item(id) $constraint)
                SQL
            )
            ->execute();
        $db
            ->createCommand()
            ->insert('Child', ['itemId' => 2])
            ->execute();
    }
}

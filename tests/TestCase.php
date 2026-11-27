<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests;

use ArrayObject;
use Closure;
use LogicException;
use PHPUnit\Exception as PHPUnitException;
use Throwable;
use Yii;
use yii\base\Component;
use yii\console\Application;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Connection;
use yii2\extensions\softdelete\QueryBehavior;

/**
 * Base class for the SQLite unit tests, rebuilding an in-memory database with three `Category` rows and two `Item`
 * rows before every test and providing event and exception helpers.
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Runs the callable and returns the exception it throws, so a test can assert which instance propagated.
     *
     * PHPUnit exceptions, such as a failed assertion inside an event handler, are rethrown unchanged.
     *
     * @param Closure(): mixed $callable
     */
    protected static function catchThrowable(Closure $callable): Throwable
    {
        try {
            $callable();
        } catch (PHPUnitException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('An exception must be thrown.');
    }

    /**
     * Subscribes handlers that fail the test as soon as one of the given events is triggered.
     *
     * @param list<string> $names Event names.
     */
    protected static function forbidEvents(Component $component, array $names): void
    {
        foreach ($names as $name) {
            $component->on($name, static fn() => self::fail("Unexpected event '$name'."));
        }
    }

    /**
     * Replaces the application with one whose in-memory SQLite connection holds the fixture rows.
     *
     * @param array<string, mixed> $connection Connection configuration merged over the default one.
     */
    protected function mockApplication(array $connection = []): void
    {
        $application = new Application(
            [
                'id' => 'testapp',
                'basePath' => __DIR__,
                'vendorPath' => dirname(__DIR__) . '/vendor',
                'components' => [
                    'db' => [
                        'class' => Connection::class,
                        'dsn' => 'sqlite::memory:',
                        ...$connection,
                    ],
                ],
            ],
        );

        $this->setupTestDbData($application->getDb());
    }

    /**
     * @param ActiveQuery<covariant ActiveRecord> $query
     */
    protected function queryBehavior(ActiveQuery $query): QueryBehavior
    {
        $behavior = $query->getBehavior('softDelete');
        if (!$behavior instanceof QueryBehavior) {
            throw new LogicException('Expected a query behavior on the fixture.');
        }

        return $behavior;
    }

    /**
     * Subscribes to the given events and returns the log that receives each triggered event name, in order.
     *
     * @param list<string> $names Event names.
     * @return ArrayObject<int, string>
     */
    protected static function recordEvents(Component $component, array $names): ArrayObject
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        foreach ($names as $name) {
            $component->on($name, static function () use ($log, $name): void {
                $log[] = $name;
            });
        }

        return $log;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockApplication();
    }

    protected function tearDown(): void
    {
        // Yii resets this process-wide global between isolated applications.
        Yii::$app = null; // @phpstan-ignore assign.propertyType (The Yii static-analysis stub prohibits a null application.)

        parent::tearDown();
    }

    private function setupTestDbData(Connection $db): void
    {
        // Structure :

        $db->createCommand()
            ->createTable(
                'Category',
                [
                    'id' => 'pk',
                    'name' => 'string',
                    'isDeleted' => 'boolean',
                ],
            )
            ->execute();

        $db->createCommand()
            ->createTable(
                'Item',
                [
                    'id' => 'pk',
                    'categoryId' => 'integer',
                    'name' => 'string',
                    'isDeleted' => 'boolean DEFAULT 0',
                    'deletedAt' => 'integer',
                    'version' => 'integer',
                ],
            )
            ->execute();

        // Data :
        foreach (['category1', 'category2', 'category3'] as $name) {
            $db->createCommand()
                ->insert('Category', ['name' => $name, 'isDeleted' => false])
                ->execute();
        }

        $db->createCommand()
            ->batchInsert(
                'Item',
                ['name', 'categoryId'],
                [['item1', 1], ['item2', 2]],
            )->execute();
    }
}

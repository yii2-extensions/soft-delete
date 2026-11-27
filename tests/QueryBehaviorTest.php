<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests;

use AppendIterator;
use ArrayIterator;
use ArrayObject;
use Generator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\{DataProviderExternal, Group, TestWith};
use yii\base\InvalidConfigException;
use yii\db\{ActiveQuery, ActiveRecord, BaseActiveRecord, Expression, Query};
use yii2\extensions\softdelete\{Condition, DeleteBehavior, QueryBehavior, ScopedQuery};
use yii2\extensions\softdelete\exception\Message;
use yii2\extensions\softdelete\tests\data\{Category, CategoryQuery, Item};
use yii2\extensions\softdelete\tests\provider\QueryBehaviorProvider;

/**
 * Unit tests for {@see QueryBehavior}, {@see ScopedQuery} and {@see Condition} soft-delete query scoping on SQLite.
 *
 * {@see QueryBehaviorProvider} for test case data providers.
 */
#[Group('sqlite')]
class QueryBehaviorTest extends TestCase
{
    public function testAlias(): void
    {
        Category::requireOne(1)->softDelete();

        self::assertCount(
            1,
            Category::find()->alias('t1')->deleted()->all(),
            'The aliased scope must match one row.',
        );

        $rows = Category::find()
            ->alias('t1')
            ->deleted()
            ->leftJoin(Item::tableName() . ' t2', 't1.id=t2.categoryId')
            ->all();

        self::assertCount(
            1,
            $rows,
            'The aliased scope must stay unambiguous after a join.',
        );
    }

    public function testCloneKeepsItsOwnCopyOfTheConfiguredConditions(): void
    {
        $query = $this->queryWithDeletedCondition(['id' => 2], Category::class);

        $copy = clone $query;

        self::assertSame(
            ['id' => 2],
            $this->queryBehavior($copy)->deletedCondition,
            'The clone must keep the configured condition.',
        );

        $this->queryBehavior($copy)->deletedCondition = ['id' => 3];

        self::assertSame(
            [2],
            array_column($query->deleted()->all(), 'id'),
            'The original must keep its behavior and its condition.',
        );
        self::assertSame(
            [3],
            array_column($copy->deleted()->all(), 'id'),
            'The clone must apply its own condition.',
        );
    }

    public function testCompositeScopeKeepsEveryRowOfAnIterableOperandWithRepeatedKeys(): void
    {
        $rows = new AppendIterator();

        $rows->append(new ArrayIterator([new ArrayObject(['id' => 1, 'name' => 'category1'])]));
        $rows->append(new ArrayIterator([['id' => 2, 'name' => 'category2']]));

        $condition = (new Condition(['in', ['id', 'name'], $rows], '{{c}}'))->getExpressions();

        self::assertArrayHasKey(
            0,
            $condition,
            'The scope must hold the condition.',
        );

        $condition = $condition[0];

        self::assertIsArray(
            $condition,
            'The condition must stay in array format.',
        );
        self::assertArrayHasKey(
            2,
            $condition,
            'The condition must keep its values.',
        );
        self::assertSame(
            [
                ['{{c}}.[[id]]' => 1, '{{c}}.[[name]]' => 'category1'],
                ['{{c}}.[[id]]' => 2, '{{c}}.[[name]]' => 'category2'],
            ],
            $condition[2],
            'Rows that share an iterator key must all be kept.',
        );
    }

    public function testCompositeScopeLeavesSubqueryOperandIntact(): void
    {
        $subquery = (new Query())->select(['id' => 'id', 'name' => 'name'])->from('Category');
        $condition = (new Condition(['in', ['id', 'name'], $subquery], '{{c}}'))->getExpressions();

        self::assertArrayHasKey(
            0,
            $condition,
            'The scope must hold the condition.',
        );

        $condition = $condition[0];

        self::assertIsArray(
            $condition,
            'The condition must stay in array format.',
        );
        self::assertArrayHasKey(
            1,
            $condition,
            'The condition must keep its columns.',
        );
        self::assertArrayHasKey(
            2,
            $condition,
            'The condition must keep its values.',
        );
        self::assertSame(
            ['{{c}}.[[id]]', '{{c}}.[[name]]'],
            $condition[1],
            'Each column must carry the alias.',
        );
        self::assertSame(
            $subquery,
            $condition[2],
            'Yii must receive the original subquery and its bindings.',
        );
    }

    public function testCompositeScopeQualifiesAnIterableColumnTuple(): void
    {
        $columns = new ArrayIterator(['first' => 'id', 'second' => 'name']);

        $condition = (new Condition(['in', $columns, [['id' => 2, 'name' => 'category2']]], '{{c}}'))
            ->getExpressions();

        self::assertSame(
            [
                [
                    'in',
                    ['{{c}}.[[id]]', '{{c}}.[[name]]'],
                    [['{{c}}.[[id]]' => 2, '{{c}}.[[name]]' => 'category2']],
                ],
            ],
            $condition,
            'Every column of the tuple and of its rows must carry the alias.',
        );
    }

    #[TestWith(['array'])]
    #[TestWith(['iterator'])]
    public function testCompositeScopeQualifiesArrayAccessRowsWithoutChangingThem(string $storage): void
    {
        $row = new ArrayObject(['id' => 2, 'deletedAt' => null]);

        $rows = [$row, ['id' => 1]];

        $operand = $storage === 'iterator' ? new ArrayIterator($rows) : $rows;

        Item::updateAll(['deletedAt' => 123], ['id' => 1]);

        $query = $this->queryWithDeletedCondition(['in', ['id', 'deletedAt'], $operand], Item::class);

        $rows = $query->deleted()->alias('item')->all();

        self::assertSame(
            [2],
            array_column($rows, 'id'),
            'Only the matching row must be selected.',
        );
        self::assertSame(
            ['id' => 2, 'deletedAt' => null],
            $row->getArrayCopy(),
            'The row must not be modified.',
        );
    }

    public function testCompositeScopeReadsAGeneratorOperandOnce(): void
    {
        $reads = new ArrayObject();

        $rows = (
            static function () use ($reads): Generator {
                $reads->append('read');

                yield 'selected' => ['id' => 2, 'name' => 'category2'];
            }
        )();

        $condition = (new Condition(['in', ['id', 'name'], $rows], '{{c}}'))->getExpressions();

        self::assertArrayHasKey(
            0,
            $condition,
            'The scope must hold the condition.',
        );

        $condition = $condition[0];

        self::assertIsArray(
            $condition,
            'The condition must stay in array format.',
        );
        self::assertArrayHasKey(
            2,
            $condition,
            'The condition must keep its values.',
        );
        self::assertSame(
            [['{{c}}.[[id]]' => 2, '{{c}}.[[name]]' => 'category2']],
            $condition[2],
            'The generated rows must carry the alias.',
        );
        self::assertCount(
            1,
            $reads,
            'The source must be read once.',
        );
    }

    public function testCompositeScopeRequalifiesARewindableOperand(): void
    {
        $rows = new ArrayIterator([['id' => 1], ['id' => 2]]);

        $condition = (new Condition(['in', ['id'], $rows], '{{c}}'))
            ->withAlias('{{d}}')
            ->getExpressions();

        self::assertArrayHasKey(
            0,
            $condition,
            'The scope must hold the condition.',
        );

        $condition = $condition[0];

        self::assertIsArray(
            $condition,
            'The condition must stay in array format.',
        );
        self::assertArrayHasKey(
            2,
            $condition,
            'The condition must keep its values.',
        );
        self::assertSame(
            [
                ['{{d}}.[[id]]' => 1],
                ['{{d}}.[[id]]' => 2],
            ],
            $condition[2],
            "Rows must carry the latest alias '{{d}}'.",
        );
    }

    public function testDefaultConditionsKeepEveryMarkerAttribute(): void
    {
        $fixture = new class extends Item {
            public function behaviors(): array
            {
                return [
                    'softDelete' => [
                        'class' => DeleteBehavior::class,
                        'softDeleteAttributeValues' => ['isDeleted' => true, 'deletedAt' => 1],
                    ],
                ];
            }
        };

        $behavior = $this->queryBehavior(new ScopedQuery($fixture::class));

        self::assertSame(
            ['isDeleted' => true, 'deletedAt' => 1],
            $behavior->getDeletedCondition(),
            'Every marker must be matched.',
        );
        self::assertSame(
            ['isDeleted' => false, 'deletedAt' => 0],
            $behavior->getNotDeletedCondition(),
            'Every restore value must be matched.',
        );
    }

    public function testExplicitActivePredicateSupportsCompositeColumns(): void
    {
        $query = new ScopedQuery(Category::class);

        $query
            ->alias('c')
            ->joinWith('items', false);

        $behavior = $this->queryBehavior($query);

        $condition = [
            'and',
            [
                'in',
                [new Expression('c.id'), '[[name]]'],
                [['c.id' => 1, '[[name]]' => 'category1']],
            ],
            [
                '=',
                'c.isDeleted',
                false,
            ],
            [
                '=',
                new Expression('LENGTH(c.name)'),
                9,
            ],
        ];

        $behavior->setNotDeletedCondition($condition);

        self::assertSame(
            $condition,
            $behavior->getNotDeletedCondition(),
            'The condition must be stored as given.',
        );

        $category = $query
            ->notDeleted()
            ->one();

        self::assertInstanceOf(
            Category::class,
            $category,
            'A category must match.',
        );
        self::assertSame(
            1,
            $category->id,
            "Only 'category1' must match.",
        );
    }

    public function testExplicitStatesAndNullAreQueriedIndependently(): void
    {
        $fixture = new class extends Item {
            public function behaviors(): array
            {
                return ['marker' => [
                    'class' => DeleteBehavior::class,
                    'softDeleteAttributeValues' => ['version' => 5],
                    'restoreAttributeValues' => ['version' => 3],
                ]];
            }
        };

        Item::updateAll(['version' => 3], ['id' => 2]);

        $item = $fixture::requireOne(2);

        $query = new ScopedQuery($fixture::class);

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertCount(
            1,
            $query->deleted()->all(),
            'The marked row must count as deleted.',
        );
        self::assertCount(
            0,
            $query->notDeleted()->all(),
            "A 'null' marker must not count as active.",
        );
        self::assertCount(
            2,
            $query->withDeleted()->all(),
            'Both rows must be listed without the scope.',
        );
        self::assertSame(
            1,
            $item->restore(),
            'One row must be restored.',
        );
        self::assertSame(
            3,
            $item->version,
            'The restore marker must be assigned.',
        );
        self::assertCount(
            1,
            $query->notDeleted()->all(),
            'The restored row must count as active.',
        );
    }

    #[DataProviderExternal(QueryBehaviorProvider::class, 'filterDeleted')]
    public function testFilterDeleted(mixed $filterDeleted, int $expectedCount): void
    {
        Category::requireOne(1)->softDelete();

        self::assertCount(
            $expectedCount,
            Category::find()->filterDeleted($filterDeleted)->all(),
            'The filter value must select its scope.',
        );
    }

    public function testJoin(): void
    {
        Category::requireOne(1)->softDelete();

        $categories = Category::find()
            ->joinWith('items')
            ->deleted()
            ->all();

        self::assertCount(
            1,
            $categories,
            'One deleted category must match with its items joined.',
        );

        $items = Item::find()
            ->innerJoinWith(
                [
                    'category' => static function (CategoryQuery $query): void {
                        $query->deleted();
                    },
                ],
            )
            ->all();

        self::assertCount(
            1,
            $items,
            'Only the item of the deleted category must match.',
        );
    }

    public function testLateAliasAndCloneKeepIndependentScopeAndTenantCondition(): void
    {
        Category::updateAll(['isDeleted' => true], ['id' => 2]);

        $query = new ScopedQuery(Category::class);

        $query->onCondition(['name' => 'category1']);
        $query->notDeleted();

        $copy = clone $query;

        $query->alias('original');
        $copy->alias('copy');

        $originalSql = $query->createCommand()->getRawSql();
        $copySql = $copy->createCommand()->getRawSql();

        self::assertStringContainsString(
            'original',
            $originalSql,
            'The original must use its alias.',
        );
        self::assertStringNotContainsString(
            'original',
            $copySql,
            'The clone must not share the alias.',
        );
        self::assertCount(
            1,
            $copy->filterDeleted('all')->all(),
            'The clone must keep the tenant condition.',
        );
        self::assertCount(
            1,
            $query->all(),
            'The original must keep its active scope.',
        );
        self::assertCount(
            0,
            $query->filterDeleted(true)->all(),
            'The tenant category must not count as deleted.',
        );
    }

    public function testMultipleWriteMarkersCombineTheirPredicates(): void
    {
        $fixture = new class extends Item {
            public function behaviors(): array
            {
                return [
                    'softDelete' => [
                        'class' => DeleteBehavior::class,
                        'softDeleteAttributeValues' => [
                            'name' => 'strlen',
                            'isDeleted' => true,
                            'deletedAt' => static fn(): int => 123,
                            'version' => static fn(): int => 9,
                        ],
                        'restoreAttributeValues' => [
                            'name' => 'item2',
                            'isDeleted' => false,
                            'deletedAt' => null,
                            'version' => null,
                        ],
                    ],
                ];
            }
        };

        Item::updateAll(['name' => 'strlen', 'isDeleted' => true, 'deletedAt' => 123], ['id' => 1]);

        $item = $fixture::requireOne(2);

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );
        self::assertSame(
            'strlen',
            $item->name,
            'A callable string must be written literally.',
        );

        $query = new ScopedQuery($fixture::class);

        self::assertSame(
            [
                'and',
                ['name' => 'strlen', 'isDeleted' => true],
                ['not', ['deletedAt' => null]],
                ['not', ['version' => null]],
            ],
            $this->queryBehavior($query)->getDeletedCondition(),
            "Literal markers must be matched by value and computed markers by 'NOT NULL'.",
        );
        self::assertSame(
            [2],
            array_column($query->deleted()->all(), 'id'),
            'Only the fully marked row may match.',
        );

        Item::updateAll(['isDeleted' => false], ['id' => 2]);

        self::assertCount(
            0,
            $query->deleted()->all(),
            'A row missing one marker must not match.',
        );
    }

    public function testNestedOperatorConditionQualifiesJoinedColumn(): void
    {
        Category::updateAll(['isDeleted' => true], ['id' => 2]);

        $query = new ScopedQuery(Category::class);

        $behavior = $this->queryBehavior($query);

        $condition = [
            'AND',
            ['NOT', ['isDeleted' => false]],
            ['=', 'id', 2],
        ];

        $behavior->setDeletedCondition($condition);

        self::assertSame(
            $condition,
            $behavior->getDeletedCondition(),
            'The condition must be stored as given.',
        );

        $query
            ->deleted()
            ->alias('{{category}}')
            ->joinWith('items', false);

        self::assertCount(
            1,
            $query->all(),
            'One category must match.',
        );

        $category = $query->one();

        self::assertInstanceOf(
            Category::class,
            $category,
            'A category must match.',
        );
        self::assertSame(
            2,
            $category->id,
            "Only 'category2' must match.",
        );
    }

    public function testQueryBehaviorSubclassCanExtendScopeConstruction(): void
    {
        $behavior = new class extends QueryBehavior {
            /** @var list<string> */
            public array $calls = [];

            protected function addFilterCondition($condition): ActiveQuery
            {
                $this->calls[] = 'apply';
                return parent::addFilterCondition($condition);
            }

            protected function defaultDeletedCondition(): array
            {
                $this->calls[] = 'deleted';
                return parent::defaultDeletedCondition();
            }

            protected function defaultNotDeletedCondition(): array
            {
                $this->calls[] = 'active';
                return parent::defaultNotDeletedCondition();
            }

            protected function getModelInstance(): BaseActiveRecord
            {
                $this->calls[] = 'model';
                return parent::getModelInstance();
            }

            protected function normalizeFilterCondition($condition): Condition
            {
                $this->calls[] = 'qualify';
                return parent::normalizeFilterCondition($condition);
            }
        };

        $query = new ScopedQuery(Category::class);

        $query->attachBehavior('softDelete', $behavior::class);

        $attachedBehavior = $this->queryBehavior($query);

        self::assertInstanceOf(
            $behavior::class,
            $attachedBehavior,
            'The subclass must be attached.',
        );
        self::assertCount(
            0,
            $query->deleted()->all(),
            'No category may count as deleted.',
        );
        self::assertCount(
            3,
            $query->notDeleted()->all(),
            'Every category must count as active.',
        );
        self::assertSame(
            ['deleted', 'model', 'apply', 'qualify', 'active', 'model', 'apply', 'qualify'],
            $attachedBehavior->calls,
            'Each override must run in construction order.',
        );
    }

    public function testRemovingScopeKeepsEveryOnCondition(): void
    {
        $query = new ScopedQuery(Category::class);

        $query->onCondition(['>=', 'id', 2]);
        $query->notDeleted()->andOnCondition(['<=', 'id', 2]);

        self::assertSame(
            ['category2'],
            $query->withDeleted()->select('name')->column(),
            'Both bounds must remain.',
        );
    }

    /**
     * @param array<array-key, mixed> $condition
     */
    #[DataProviderExternal(QueryBehaviorProvider::class, 'inConditionShapes')]
    public function testScopeKeepsTheShapeOfInConditionOperands(array $condition): void
    {
        $query = $this->queryWithDeletedCondition($condition, Category::class);

        self::assertSame(
            ['category2'],
            $query->deleted()->select('name')->column(),
            "Only 'category2' must match.",
        );
    }

    /**
     * @param array<array-key, mixed> $condition
     * @param array<array-key, mixed> $qualified
     * @param list<int> $ids
     */
    #[DataProviderExternal(QueryBehaviorProvider::class, 'qualifiedOperators')]
    public function testScopeQualifiesColumnsOfEveryOperatorFamily(array $condition, array $qualified, array $ids): void
    {
        self::assertSame(
            [$qualified],
            (new Condition($condition, '{{c}}'))->getExpressions(),
            'Bare columns must carry the alias.',
        );

        $query = $this->queryWithDeletedCondition($condition, Category::class);

        $rows = $query
            ->alias('c')
            ->joinWith('items', false)
            ->deleted()
            ->orderBy('c.id')
            ->all();

        self::assertSame(
            $ids,
            array_column($rows, 'id'),
            'Columns shared with the joined table must not be ambiguous.',
        );
    }

    #[TestWith(['ilike'])]
    #[TestWith(['not ilike'])]
    #[TestWith(['or ilike'])]
    #[TestWith(['or not ilike'])]
    public function testScopeQualifiesTheColumnOfCaseInsensitiveLikeOperators(string $operator): void
    {
        self::assertSame(
            [[$operator, '{{c}}.[[name]]', 'category']],
            (new Condition([$operator, 'name', 'category'], '{{c}}'))->getExpressions(),
            'The bare column must carry the alias.',
        );
    }

    public function testThrowInvalidArgumentExceptionForInvalidConditionOperator(): void
    {
        $query = $this->queryWithDeletedCondition([99], Category::class);

        $this->expectException(InvalidArgumentException::class);

        $query->deleted();
    }

    #[TestWith(['1abc'], 'non-numeric string')]
    #[TestWith([-1], 'negative integer')]
    #[TestWith([['all']], 'non-empty array')]
    public function testThrowInvalidArgumentExceptionForUnknownFilterValue(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Category::find()->filterDeleted($value);
    }

    public function testThrowInvalidConfigExceptionForComputedMarkerWithNonNullActiveValue(): void
    {
        $query = $this->queryForComputedDeletedAtMarker(1);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::COMPUTED_MARKER_REQUIRES_DELETED_CONDITION->getMessage(),
        );

        $query->deleted();
    }

    public function testThrowInvalidConfigExceptionForComputedRestoreValue(): void
    {
        $query = $this->queryForComputedDeletedAtMarker(static fn(): int => 100);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::COMPUTED_RESTORE_VALUE_REQUIRES_NOT_DELETED_CONDITION->getMessage(),
        );

        $query->notDeleted();
    }

    public function testThrowInvalidConfigExceptionForModelWithoutDeleteBehavior(): void
    {
        $fixture = new class extends Item {
            public function behaviors(): array
            {
                return [];
            }
        };

        $query = new ScopedQuery($fixture::class);

        $this->expectException(InvalidConfigException::class);

        $query->deleted();
    }

    public function testThrowInvalidConfigExceptionForQueryBehaviorWithoutSqlQuery(): void
    {
        $this->expectException(InvalidConfigException::class);

        (new QueryBehavior())->withDeleted();
    }

    public function testThrowInvalidConfigExceptionForUnsupportedModel(): void
    {
        $model = new class {
            public static function instance(): self
            {
                return new self();
            }
        };

        $this->expectException(InvalidConfigException::class);

        (new ScopedQuery($model::class))->deleted(); // @phpstan-ignore argument.type (Intentionally configure a class that is not ActiveRecord.)
    }

    public function testTimestampScopeDoesNotExecuteWriteCallback(): void
    {
        $fixture = new class extends Item {
            public function behaviors(): array
            {
                return ['softDelete' => [
                    'class' => DeleteBehavior::class,
                    'softDeleteAttributeValues' => ['deletedAt' => static function (Item $item): int {
                        self::checkPersisted($item);
                        return 123;
                    }],
                ]];
            }

            private static function checkPersisted(Item $item): void
            {
                if ($item->isNewRecord) {
                    throw new LogicException('The write callback needs a saved row.');
                }
            }
        };

        $item = $fixture::requireOne(2);

        self::assertSame(
            1,
            $item->softDelete(),
            'One row must be marked.',
        );

        $query = new ScopedQuery($fixture::class);

        self::assertSame(
            ['not', ['deletedAt' => null]],
            $this->queryBehavior($query)->getDeletedCondition(),
            "A single computed marker must yield a bare 'NOT NULL' condition.",
        );
        self::assertCount(
            1,
            $query->deleted()->all(),
            'The marked row must count as deleted.',
        );
        self::assertCount(
            1,
            $query->notDeleted()->all(),
            'The other row must count as active.',
        );
        self::assertSame(
            1,
            $item->restore(),
            'One row must be restored.',
        );
        self::assertCount(
            0,
            $query->deleted()->all(),
            'No row may count as deleted after restoration.',
        );
    }

    public function testWhereDeleted(): void
    {
        self::assertSame(
            ['isDeleted' => true],
            $this->queryBehavior(Category::find())->getDeletedCondition(),
            'The default condition must match the soft-delete value.',
        );
        self::assertCount(
            0,
            Category::find()->deleted()->all(),
            'No category may count as deleted before marking.',
        );

        Category::requireOne(1)->softDelete();

        self::assertCount(
            1,
            Category::find()->deleted()->all(),
            'The marked category must count as deleted.',
        );
    }

    public function testWhereNotDeleted(): void
    {
        self::assertCount(
            (int) Category::find()->count(),
            Category::find()->notDeleted()->all(),
            'Every category must count as active before marking.',
        );

        Category::requireOne(1)->softDelete();

        self::assertCount(
            (int) Category::find()->count() - 1,
            Category::find()->notDeleted()->all(),
            'The marked category must not count as active.',
        );
    }

    /**
     * Returns a query for an Item fixture whose `deletedAt` marker is computed and restores to the given value.
     *
     * @return ScopedQuery<Item>
     */
    private function queryForComputedDeletedAtMarker(mixed $restoreValue): ScopedQuery
    {
        $fixture = new class extends Item {
            public function behaviors(): array
            {
                return ['softDelete' => [
                    'class' => DeleteBehavior::class,
                    'softDeleteAttributeValues' => ['deletedAt' => static fn(): int => 200],
                ]];
            }
        };

        // The query reads the conditions from the cached model instance.
        $fixture::instance()->deleteBehavior()->restoreAttributeValues = ['deletedAt' => $restoreValue];

        return new ScopedQuery($fixture::class);
    }

    /**
     * Returns a scoped query for the model class whose behavior selects deleted rows with the given condition.
     *
     * @param array<array-key, mixed> $deletedCondition
     * @param class-string<ActiveRecord> $modelClass
     * @return ScopedQuery<ActiveRecord>
     */
    private function queryWithDeletedCondition(array $deletedCondition, string $modelClass): ScopedQuery
    {
        $query = new ScopedQuery($modelClass);

        $this->queryBehavior($query)->deletedCondition = $deletedCondition;

        return $query;
    }
}

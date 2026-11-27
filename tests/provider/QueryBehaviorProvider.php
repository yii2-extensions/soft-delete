<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\provider;

use yii\db\Expression;
use yii2\extensions\softdelete\tests\QueryBehaviorTest;

/**
 * Data provider for {@see QueryBehaviorTest} test cases.
 */
final class QueryBehaviorProvider
{
    /**
     * @return array<string, array{array{}|bool|int|string|null, int}>
     */
    public static function filterDeleted(): array
    {
        return [
            'all' => ['all', 3],
            'empty array' => [[], 2],
            'empty string' => ['', 2],
            'false' => [false, 3],
            'integer one' => [1, 1],
            'integer zero' => [0, 3],
            'null' => [null, 2],
            'string one' => ['1', 1],
            'string zero' => ['0', 3],
            'true' => [true, 1],
        ];
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function inConditionShapes(): array
    {
        return [
            'expression and column reading the same key' => [
                ['in', ['id', new Expression('id')], [['id' => 2]]],
            ],
            'expression as single column' => [
                ['in', new Expression('ABS(id)'), [2]],
            ],
            'single column list with scalar values' => [
                ['in', ['id'], [2]],
            ],
        ];
    }

    /**
     * @return array<string, array{array<array-key, mixed>, array<array-key, mixed>, list<int>}>
     */
    public static function qualifiedOperators(): array
    {
        return [
            'or' => [
                ['or', ['id' => 1], ['name' => 'category3']],
                ['or', ['{{c}}.[[id]]' => 1], ['{{c}}.[[name]]' => 'category3']],
                [1, 3],
            ],
            'comparison' => [
                ['>=', 'id', 2],
                ['>=', '{{c}}.[[id]]', 2],
                [2, 3],
            ],
            'between' => [
                ['between', 'id', 1, 2],
                ['between', '{{c}}.[[id]]', 1, 2],
                [1, 2],
            ],
            'like' => [
                ['like', 'name', 'category2'],
                ['like', '{{c}}.[[name]]', 'category2'],
                [2],
            ],
            'not in' => [
                ['not in', 'id', [1, 3]],
                ['not in', '{{c}}.[[id]]', [1, 3]],
                [2],
            ],
        ];
    }
}

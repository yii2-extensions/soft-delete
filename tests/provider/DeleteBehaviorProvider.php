<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\provider;

use Closure;
use yii2\extensions\softdelete\tests\data\Item;
use yii2\extensions\softdelete\tests\DeleteBehaviorTest;

/**
 * Data provider for {@see DeleteBehaviorTest} test cases.
 */
final class DeleteBehaviorProvider
{
    /**
     * @return array<string, array{Closure(Item): (false|int)}>
     */
    public static function transitions(): array
    {
        return [
            'soft delete' => [static fn(Item $item): int|false => $item->softDelete()],
            'restore' => [static fn(Item $item): int|false => $item->restore()],
        ];
    }

    /**
     * @return array<string, array{Closure(Item): (false|int), bool}>
     */
    public static function transitionsWithTargetMarker(): array
    {
        return [
            'soft delete' => [
                static fn(Item $item): int|false => $item->softDelete(),
                true,
            ],
            'restore' => [
                static fn(Item $item): int|false => $item->restore(),
                false,
            ],
        ];
    }
}

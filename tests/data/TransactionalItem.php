<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\data;

/**
 * Item whose default scenario declares the operations in {@see $operations} transactional.
 */
class TransactionalItem extends Item
{
    /**
     * @var int ActiveRecord `OP_*` constants combined with `|`, or `0` to declare no operation transactional.
     */
    public int $operations = 0;

    /**
     * @return array<string, int>
     */
    public function transactions(): array
    {
        return [self::SCENARIO_DEFAULT => $this->operations];
    }
}

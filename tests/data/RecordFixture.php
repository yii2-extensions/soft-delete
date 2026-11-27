<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\data;

use LogicException;
use yii2\extensions\softdelete\DeleteBehavior;

trait RecordFixture
{
    public function deleteBehavior(): DeleteBehavior
    {
        $behavior = $this->getBehavior('softDelete');

        if (!$behavior instanceof DeleteBehavior) {
            throw new LogicException('Expected a delete behavior on the fixture.');
        }

        return $behavior;
    }

    /**
     * @param array<string, int>|int $condition
     */
    public static function requireOne(int|array $condition): static
    {
        return static::findOne($condition) ?? throw new LogicException('Expected fixture record.');
    }
}

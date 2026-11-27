<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\data;

use yii\db\ActiveQuery;

/**
 * @template T of Category
 * @extends ActiveQuery<T>
 * @method $this deleted()
 * @method $this filterDeleted(mixed $deleted)
 * @method $this notDeleted()
 */
final class CategoryQuery extends ActiveQuery {}

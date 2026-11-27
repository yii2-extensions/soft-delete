<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

use function is_array;

/**
 * SQL ActiveQuery that retains its soft-delete scope when {@see alias()} changes the primary table alias.
 *
 * {@see from()} does not re-qualify the scope: rename the primary table with {@see alias()}, or call {@see from()}
 * before choosing the scope.
 *
 * @template T of ActiveRecord
 * @extends ActiveQuery<T>
 * @method $this deleted()
 * @method $this filterDeleted(mixed $deleted)
 * @method $this notDeleted()
 * @method $this withDeleted()
 */
class ScopedQuery extends ActiveQuery
{
    /**
     * Sets the primary table alias and re-qualifies the soft-delete scope with it.
     *
     * @param string $alias Table alias, with or without `{{` and `}}` quoting.
     */
    public function alias($alias): static
    {
        parent::alias($alias);

        if (is_array($this->on)) {
            $qualifiedAlias = str_starts_with($alias, '{{') ? $alias : '{{' . $alias . '}}';

            $this->on = Condition::map(
                $this->on,
                static fn(Condition $condition): Condition => $condition->withAlias($qualifiedAlias),
            );
        }

        return $this;
    }

    /**
     * Declares {@see QueryBehavior} as the `softDelete` behavior.
     *
     * @return array<string, class-string<QueryBehavior>>
     */
    public function behaviors(): array
    {
        return ['softDelete' => QueryBehavior::class];
    }
}

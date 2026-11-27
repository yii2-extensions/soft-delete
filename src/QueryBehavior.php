<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete;

use InvalidArgumentException;
use yii\base\{Behavior, InvalidConfigException};
use yii\db\{ActiveQuery, BaseActiveRecord};
use yii2\extensions\softdelete\exception\Message;

use function array_key_exists;
use function count;
use function is_array;
use function is_scalar;

/**
 * Scopes SQL {@see ActiveQuery} results to records that are, or are not, marked as deleted by {@see DeleteBehavior}.
 *
 * The query model must have a {@see DeleteBehavior} attached; the default conditions derive from its soft-delete and
 * restore values. Return a {@see ScopedQuery}, or a subclass of it, from `find()`: it declares this behavior, so the
 * behavior survives query cloning, and it re-qualifies the scope when the table alias changes.
 *
 * @property array<array-key, mixed> $deletedCondition Condition that selects deleted records.
 * @property array<array-key, mixed> $notDeletedCondition Condition that selects records that are not deleted.
 */
class QueryBehavior extends Behavior
{
    /**
     * @var array<array-key, mixed>|null Condition that selects deleted records, or `null` to build the default one.
     */
    private array|null $_deletedCondition = null;
    /**
     * @var array<array-key, mixed>|null Condition that selects records that are not deleted, or `null` to build the
     * default one.
     */
    private array|null $_notDeletedCondition = null;

    /**
     * Restricts the owner query to deleted records, replacing any soft-delete scope applied before.
     *
     * @throws InvalidConfigException When the default condition cannot be built for the query model.
     * @return ActiveQuery The owner query.
     */
    public function deleted(): ActiveQuery
    {
        return $this->addFilterCondition($this->getDeletedCondition());
    }

    /**
     * Applies the soft-delete scope selected by a filter value.
     *
     * The values `''`, `null` and `[]` select records that are not deleted; `true`, `1` and `'1'` select deleted
     * records; `false`, `0`, `'0'` and `'all'` select all records. Values are compared strictly, so validate request
     * input before passing it.
     *
     * @throws InvalidArgumentException When the value is not one of the accepted values.
     * @throws InvalidConfigException When the default condition cannot be built for the query model.
     * @return ActiveQuery The owner query.
     */
    public function filterDeleted(mixed $deleted): ActiveQuery
    {
        return match ($deleted) {
            '', null, [] => $this->notDeleted(),
            true, 1, '1' => $this->deleted(),
            false, 0, '0', 'all' => $this->withDeleted(),
            default => throw new InvalidArgumentException(Message::FILTER_VALUE_UNKNOWN->getMessage()),
        };
    }

    /**
     * Returns the condition that selects deleted records, building the default one on first use.
     *
     * @throws InvalidConfigException When the default condition cannot be built for the query model.
     * @return array<array-key, mixed> Condition that selects deleted records.
     */
    public function getDeletedCondition(): array
    {
        return $this->_deletedCondition ??= $this->defaultDeletedCondition();
    }

    /**
     * Returns the condition that selects records that are not deleted, building the default one on first use.
     *
     * @throws InvalidConfigException When the default condition cannot be built for the query model.
     * @return array<array-key, mixed> Condition that selects records that are not deleted.
     */
    public function getNotDeletedCondition(): array
    {
        return $this->_notDeletedCondition ??= $this->defaultNotDeletedCondition();
    }

    /**
     * Restricts the owner query to records that are not deleted, replacing any soft-delete scope applied before.
     *
     * @throws InvalidConfigException When the default condition cannot be built for the query model.
     * @return ActiveQuery The owner query.
     */
    public function notDeleted(): ActiveQuery
    {
        return $this->addFilterCondition($this->getNotDeletedCondition());
    }

    /**
     * Sets the condition used to select soft-deleted records.
     *
     * @param array<array-key, mixed>|null $deletedCondition Condition that selects deleted records, or `null` to use
     * the default one.
     */
    public function setDeletedCondition(array|null $deletedCondition): void
    {
        $this->_deletedCondition = $deletedCondition;
    }

    /**
     * Sets the condition used to select records that are not soft-deleted.
     *
     * @param array<array-key, mixed>|null $notDeletedCondition Condition that selects records that are not deleted, or
     * `null` to use the default one.
     */
    public function setNotDeletedCondition(array|null $notDeletedCondition): void
    {
        $this->_notDeletedCondition = $notDeletedCondition;
    }

    /**
     * Removes the soft-delete scope from the owner query and keeps its other conditions.
     *
     * @throws InvalidConfigException When the behavior is not attached to a SQL ActiveQuery.
     */
    public function withDeleted(): ActiveQuery
    {
        $query = $this->query();

        if (is_array($query->on)) {
            $query->on = Condition::map($query->on, static fn() => null);
        }

        return $query;
    }

    /**
     * Replaces the soft-delete scope of the owner query with the given condition.
     *
     * @param array<array-key, mixed> $condition Condition that selects the scoped records.
     * @return ActiveQuery The owner query.
     */
    protected function addFilterCondition(array $condition): ActiveQuery
    {
        return $this->withDeleted()->andOnCondition(['and', $this->normalizeFilterCondition($condition)]);
    }

    /**
     * Builds the condition that matches the model's soft-delete values.
     *
     * A computed value cannot be matched, so it becomes a `NOT NULL` check, which requires its restore value to be
     * `null`.
     *
     * @throws InvalidConfigException When the query model has no {@see DeleteBehavior}, or a computed value has a
     * restore value other than `null`.
     * @return array<array-key, mixed>
     */
    protected function defaultDeletedCondition(): array
    {
        $behavior = $this->getModelBehavior();

        $values = [];
        $conditions = [];

        foreach ($behavior->softDeleteAttributeValues as $attribute => $value) {
            if (!is_scalar($value) && is_callable($value)) {
                $restore = $behavior->resolveRestoreAttributeValues();
                if (!array_key_exists($attribute, $restore) || $restore[$attribute] !== null) {
                    throw new InvalidConfigException(
                        Message::COMPUTED_MARKER_REQUIRES_DELETED_CONDITION->getMessage(),
                    );
                }
                $conditions[] = ['not', [$attribute => null]];
            } else {
                $values[$attribute] = $value;
            }
        }

        if ($values !== []) {
            array_unshift($conditions, $values);
        }

        return count($conditions) > 1
            ? ['and', ...$conditions]
            : $conditions[0] ?? [];
    }

    /**
     * Builds the condition that matches the model's restore values without invoking any computed value.
     *
     * @throws InvalidConfigException When the query model has no {@see DeleteBehavior}, its restore values cannot be
     * inferred, or one of them is computed.
     * @return array<array-key, mixed>
     */
    protected function defaultNotDeletedCondition(): array
    {
        $values = $this->getModelBehavior()->resolveRestoreAttributeValues();

        foreach ($values as $value) {
            if (!is_scalar($value) && is_callable($value)) {
                throw new InvalidConfigException(
                    Message::COMPUTED_RESTORE_VALUE_REQUIRES_NOT_DELETED_CONDITION->getMessage(),
                );
            }
        }

        return $values;
    }

    /**
     * Returns the model instance of the owner query.
     *
     * @throws InvalidConfigException When the query model is not an ActiveRecord.
     */
    protected function getModelInstance(): BaseActiveRecord
    {
        $model = $this->query()->modelClass::instance();

        if (!$model instanceof BaseActiveRecord) {
            throw new InvalidConfigException(
                Message::QUERY_MODEL_MUST_EXTEND_BASE_ACTIVE_RECORD->getMessage(),
            );
        }

        return $model;
    }

    /**
     * Wraps the condition as a soft-delete scope qualified with the current primary table alias.
     *
     * @param array<array-key, mixed> $condition Condition that selects the scoped records.
     */
    protected function normalizeFilterCondition(array $condition): Condition
    {
        $alias = array_key_first($this->query()->getTablesUsedInFrom());

        return new Condition($condition, is_string($alias) ? $alias : null);
    }

    /**
     * @throws InvalidConfigException When the query model has no {@see DeleteBehavior}.
     */
    private function getModelBehavior(): DeleteBehavior
    {
        foreach ($this->getModelInstance()->getBehaviors() as $behavior) {
            if ($behavior instanceof DeleteBehavior) {
                return $behavior;
            }
        }

        throw new InvalidConfigException(
            Message::QUERY_MODEL_MUST_HAVE_DELETE_BEHAVIOR->getMessage(),
        );
    }

    /**
     * @throws InvalidConfigException When the behavior is not attached to a SQL ActiveQuery.
     */
    private function query(): ActiveQuery
    {
        if (!$this->owner instanceof ActiveQuery) {
            throw new InvalidConfigException(
                Message::QUERY_BEHAVIOR_REQUIRES_ACTIVE_QUERY_OWNER->getMessage(),
            );
        }

        return $this->owner;
    }
}

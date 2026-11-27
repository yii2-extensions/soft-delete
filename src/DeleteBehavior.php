<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete;

use Closure;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use yii\base\{Behavior, Event, InvalidConfigException, ModelEvent};
use yii\db\{ActiveRecord, BaseActiveRecord, Connection, IntegrityException, StaleObjectException};
use yii2\extensions\softdelete\exception\Message;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;

/**
 * Marks ActiveRecord models as deleted instead of removing their rows, and restores them from that state.
 *
 * {@see softDelete()} writes {@see softDeleteAttributeValues}, {@see restore()} writes the restore values, and
 * {@see safeDelete()} attempts a physical delete first and marks the record only when the database rejects it with
 * {@see deleteFallbackException}. Yii's own {@see BaseActiveRecord::delete()} keeps deleting the row physically.
 * {@see ScopedQuery} and {@see QueryBehavior} provide query scoping.
 *
 * The owner may declare public `beforeSoftDelete()`, `afterSoftDelete()`, `beforeRestore()` and `afterRestore()`
 * methods; the behavior calls them by name before triggering the matching event. A before-method returning a falsy
 * value cancels the operation.
 *
 * Every write targets the row by its persisted primary key and honors {@see BaseActiveRecord::optimisticLock()},
 * whose value must be a non-negative integer that still matches the row and that each update increments. A record
 * without its primary key loaded, a hook that is not public, or a transactional operation on an owner without a Yii
 * SQL connection raises {@see InvalidConfigException}.
 *
 * Exceptions from hooks, event handlers and the database propagate. A rolled-back transaction first returns the owner
 * attributes to their state before the call; when the rollback itself fails, {@see RuntimeException} reports it with
 * the exception that caused the rollback as its previous one.
 */
class DeleteBehavior extends Behavior
{
    /**
     * @event Event Triggered after a record is restored.
     */
    public const string EVENT_AFTER_RESTORE = 'afterRestore';
    /**
     * @event Event Triggered after a record is marked as deleted.
     */
    public const string EVENT_AFTER_SOFT_DELETE = 'afterSoftDelete';
    /**
     * @event ModelEvent Triggered before a record is restored; set {@see ModelEvent::$isValid} to `false` to cancel.
     */
    public const string EVENT_BEFORE_RESTORE = 'beforeRestore';
    /**
     * @event ModelEvent Triggered before a record is marked as deleted; set {@see ModelEvent::$isValid} to `false` to
     * cancel.
     */
    public const string EVENT_BEFORE_SOFT_DELETE = 'beforeSoftDelete';
    /**
     * @var mixed Callback that decides whether {@see softDelete()} deletes the row physically instead of marking it,
     * or `null` to always mark it. The callback receives the owner and must return a `bool`; any other callback
     * result, or a value that is not callable, makes {@see softDelete()} throw {@see InvalidConfigException}.
     *
     * ```php
     * static fn (\yii\db\BaseActiveRecord $user): bool => $user->lastLoginDate === null
     * ```
     */
    public mixed $allowDeleteCallback = null;
    /**
     * @var string Class name of the {@see Throwable} that makes {@see safeDelete()} fall back to a soft delete when
     * the physical delete raises it or one of its subclasses. The default handles database integrity failures such
     * as a restrictive foreign key. A value that does not name a {@see Throwable} type makes {@see safeDelete()}
     * throw {@see InvalidConfigException}.
     */
    public string $deleteFallbackException = IntegrityException::class;
    /**
     * @var bool Whether {@see softDelete()} runs the owner's {@see BaseActiveRecord::beforeDelete()} and
     * {@see BaseActiveRecord::afterDelete()}. The soft-delete hooks and events run in either case.
     */
    public bool $invokeDeleteEvents = true;
    /**
     * @var array<string, mixed>|null Values written on restoration, by attribute name. When `null`,
     * {@see resolveRestoreAttributeValues()} infers them from {@see softDeleteAttributeValues}.
     */
    public array|null $restoreAttributeValues = null;
    /**
     * @var array<string, mixed> Values written on soft deletion, by attribute name, such as a flag or a status:
     *
     * ```php
     * ['isDeleted' => true]
     * ['statusId' => Item::STATUS_DELETED]
     * ```
     *
     * A non-scalar callable is a computed value: it receives the owner and returns the value to write. A string is
     * always a literal value, even when it names a function.
     *
     * ```php
     * ['deletedAt' => static fn (\yii\db\BaseActiveRecord $model): int => time()]
     * ```
     */
    public array $softDeleteAttributeValues = [
        'isDeleted' => true,
    ];
    /**
     * @var bool Whether inserting a record fills its `null` attributes with the restore values.
     */
    public bool $useRestoreAttributeValuesAsDefaults = false;

    /**
     * Runs the owner's public `afterRestore()`, when declared, and triggers {@see EVENT_AFTER_RESTORE}.
     *
     * @throws InvalidConfigException When the owner declares `afterRestore()` with a visibility other than public.
     */
    public function afterRestore(): void
    {
        $owner = $this->record();
        $this->ownerHook($owner, __FUNCTION__)?->__invoke();
        $owner->trigger(self::EVENT_AFTER_RESTORE);
    }

    /**
     * Runs the owner's public `afterSoftDelete()`, when declared, and triggers {@see EVENT_AFTER_SOFT_DELETE}.
     *
     * @throws InvalidConfigException When the owner declares `afterSoftDelete()` with a visibility other than public.
     */
    public function afterSoftDelete(): void
    {
        $owner = $this->record();
        $this->ownerHook($owner, __FUNCTION__)?->__invoke();
        $owner->trigger(self::EVENT_AFTER_SOFT_DELETE);
    }

    /**
     * Fills the `null` attributes of a record being inserted with the restore values while
     * {@see useRestoreAttributeValuesAsDefaults} is enabled; handles {@see BaseActiveRecord::EVENT_BEFORE_INSERT}.
     *
     * @throws InvalidConfigException When the restore values cannot be inferred.
     */
    public function beforeInsert(Event $event): void
    {
        if (!$this->useRestoreAttributeValuesAsDefaults) {
            return;
        }

        $owner = $this->record();

        foreach ($this->resolveRestoreAttributeValues() as $attribute => $value) {
            if ($owner->getAttribute($attribute) === null) {
                $owner->setAttribute($attribute, $this->resolveValue($owner, $value));
            }
        }
    }

    /**
     * Runs the owner's public `beforeRestore()`, when declared, and triggers {@see EVENT_BEFORE_RESTORE}.
     *
     * @return bool Whether the restoration may proceed; `false` when the owner method returns a falsy value, in which
     * case the event is not triggered, or when an event handler invalidates the event.
     * @throws InvalidConfigException When the owner declares `beforeRestore()` with a visibility other than public.
     */
    public function beforeRestore(): bool
    {
        return $this->beforeTransition($this->record(), __FUNCTION__, self::EVENT_BEFORE_RESTORE);
    }

    /**
     * Runs the owner's public `beforeSoftDelete()`, when declared, and triggers {@see EVENT_BEFORE_SOFT_DELETE}.
     *
     * @return bool Whether the soft deletion may proceed; `false` when the owner method returns a falsy value, in
     * which case the event is not triggered, or when an event handler invalidates the event.
     * @throws InvalidConfigException When the owner declares `beforeSoftDelete()` with a visibility other than public.
     */
    public function beforeSoftDelete(): bool
    {
        return $this->beforeTransition($this->record(), __FUNCTION__, self::EVENT_BEFORE_SOFT_DELETE);
    }

    /**
     * @return array<string, string> Owner events handled by the behavior.
     */
    public function events(): array
    {
        return [BaseActiveRecord::EVENT_BEFORE_INSERT => 'beforeInsert'];
    }

    /**
     * Returns the values written on restoration.
     *
     * Returns {@see restoreAttributeValues} when set. Otherwise infers each value from
     * {@see softDeleteAttributeValues}: a `bool` is negated, `0` and `1` swap, and a computed value restores to `null`.
     *
     * @return array<string, mixed> Values by attribute name.
     * @throws InvalidConfigException When a soft-delete value has no inferable counterpart.
     */
    public function resolveRestoreAttributeValues(): array
    {
        if ($this->restoreAttributeValues !== null) {
            return $this->restoreAttributeValues;
        }

        $restoreAttributeValues = [];

        foreach ($this->softDeleteAttributeValues as $name => $value) {
            $restoreAttributeValues[$name] = match (true) {
                is_bool($value) => !$value,
                $value === 0 || $value === 1 => 1 - $value,
                !is_scalar($value) && is_callable($value) => null,
                default => throw new InvalidConfigException(
                    Message::RESTORE_ATTRIBUTE_VALUES_NOT_INFERABLE->getMessage(get_class($this)),
                ),
            };
        }

        return $restoreAttributeValues;
    }

    /**
     * Restores a soft-deleted record by writing the restore values together with its other changed attributes.
     *
     * Runs inside a transaction when the owner declares {@see ActiveRecord::OP_UPDATE} transactional.
     *
     * @return int|false Number of restored rows; `0` for an unsaved record, when no value differs from the stored
     * row, or when no row matched; `false` when a hook or event handler cancels the restoration.
     * @throws InvalidConfigException When the restore values cannot be inferred or an owner requirement is not met.
     * @throws StaleObjectException When the optimistic lock value is invalid, cannot be incremented, or is outdated.
     * @throws RuntimeException When a rollback fails after an exception.
     */
    public function restore(): int|false
    {
        $owner = $this->record();

        if ($owner->getIsNewRecord()) {
            return 0;
        }

        return $this->runOperation(
            $owner,
            ActiveRecord::OP_UPDATE,
            function () {
                if (!$this->beforeRestore()) {
                    return false;
                }
                $result = $this->restoreInternal();
                if ($result > 0) {
                    $this->afterRestore();
                }
                return $result;
            },
        );
    }

    /**
     * Deletes the row physically and marks the record as deleted instead when the database rejects the delete with
     * {@see deleteFallbackException}.
     *
     * Requires a SQL {@see ActiveRecord} owner. The physical attempt runs {@see BaseActiveRecord::beforeDelete()} and
     * {@see BaseActiveRecord::afterDelete()} inside a transaction. When the physical delete raises an eligible
     * exception, that transaction is rolled back and the soft deletion runs in a new one. An exception raised by a
     * hook is never eligible.
     *
     * @return int|false Number of deleted or marked rows; `0` for an unsaved record or when no row matched; `false`
     * when a hook or event handler cancels the operation.
     * @throws InvalidConfigException When the owner or {@see deleteFallbackException} is invalid.
     * @throws StaleObjectException When the optimistic lock value is invalid, cannot be incremented, or is outdated.
     * @throws RuntimeException When a rollback fails after an exception.
     */
    public function safeDelete(): int|false
    {
        $owner = $this->record();

        if (!$owner instanceof ActiveRecord) {
            throw new InvalidConfigException(
                Message::SAFE_DELETE_REQUIRES_ACTIVE_RECORD->getMessage(),
            );
        }

        if ($owner->getIsNewRecord()) {
            return 0;
        }

        $fallbackClass = $this->deleteFallbackException;

        if (!is_a($fallbackClass, Throwable::class, true)) {
            throw new InvalidConfigException(
                Message::DELETE_FALLBACK_EXCEPTION_INVALID->getMessage(),
            );
        }

        $result = $this->runInTransaction($owner, fn() => $this->deletePhysically($owner, $fallbackClass));

        return $result instanceof Throwable
            ? $this->runInTransaction($owner, $this->softDeleteInternal(...))
            : $result;
    }

    /**
     * Marks the record as deleted by writing {@see softDeleteAttributeValues} together with its other changed
     * attributes, or deletes it physically when {@see allowDeleteCallback} allows it.
     *
     * Runs inside a transaction when the owner declares {@see ActiveRecord::OP_DELETE} or
     * {@see ActiveRecord::OP_UPDATE} transactional.
     *
     * @return int|false Number of affected rows; `0` for an unsaved record, when no value differs from the stored
     * row, or when no row matched; `false` when a hook or event handler cancels the operation.
     * @throws InvalidConfigException When {@see allowDeleteCallback} is invalid or an owner requirement is not met.
     * @throws StaleObjectException When the optimistic lock value is invalid, cannot be incremented, or is outdated.
     * @throws RuntimeException When a rollback fails after an exception.
     */
    public function softDelete(): int|false
    {
        $owner = $this->record();
        if ($owner->getIsNewRecord()) {
            return 0;
        }
        if ($this->isDeleteAllowed()) {
            return $owner->delete();
        }

        return $this->runOperation(
            $owner,
            ActiveRecord::OP_DELETE | ActiveRecord::OP_UPDATE,
            function () use ($owner) {
                if ($this->invokeDeleteEvents && !$owner->beforeDelete()) {
                    return false;
                }

                $result = $this->softDeleteInternal();

                if ($result > 0 && $this->invokeDeleteEvents) {
                    $owner->afterDelete();
                }

                return $result;
            },
        );
    }

    /**
     * Returns whether {@see softDelete()} deletes the row physically, as decided by {@see allowDeleteCallback}.
     *
     * @return bool Whether the physical delete is allowed; `false` when no callback is configured.
     * @throws InvalidConfigException When the callback is not callable or does not return a `bool`.
     */
    protected function isDeleteAllowed(): bool
    {
        $callback = $this->allowDeleteCallback;

        if ($callback === null) {
            return false;
        }

        $allowed = is_callable($callback) ? $callback($this->record()) : null;

        if (!is_bool($allowed)) {
            throw new InvalidConfigException(Message::ALLOW_DELETE_CALLBACK_INVALID->getMessage());
        }

        return $allowed;
    }

    /**
     * Writes the restore values together with the other changed owner attributes.
     *
     * @return int Number of restored rows.
     * @throws InvalidConfigException When the restore values cannot be inferred or the primary key is not loaded.
     * @throws StaleObjectException When the optimistic lock value is invalid, cannot be incremented, or is outdated.
     */
    protected function restoreInternal(): int
    {
        $values = $this->resolveRestoreAttributeValues();
        $owner = $this->record();

        return $this->updateAttributes($owner, $this->transitionAttributes($owner, $values));
    }

    /**
     * Runs the soft-delete hooks around writing {@see softDeleteAttributeValues} with the other changed attributes.
     *
     * @return int|false Number of rows marked as deleted, or `false` when a hook or event handler cancels.
     * @throws InvalidConfigException When the primary key is not loaded or an owner hook is not public.
     * @throws StaleObjectException When the optimistic lock value is invalid, cannot be incremented, or is outdated.
     */
    protected function softDeleteInternal(): int|false
    {
        if (!$this->beforeSoftDelete()) {
            return false;
        }

        $owner = $this->record();
        $attributes = $this->transitionAttributes($owner, $this->softDeleteAttributeValues);
        $result = $this->updateAttributes($owner, $attributes);

        if ($result > 0) {
            $this->afterSoftDelete();
        }

        return $result;
    }

    /**
     * Runs the owner's public before-hook of the given name, when declared, and triggers the given event unless the
     * hook returns a falsy value.
     */
    private function beforeTransition(BaseActiveRecord $owner, string $hook, string $event): bool
    {
        $method = $this->ownerHook($owner, $hook);

        // Not `!$method()`: strict rules reject negating `mixed`, and the explicit comparison keeps the cast testable.
        if ($method !== null && (bool) $method() === false) {
            return false;
        }

        $modelEvent = new ModelEvent();

        $owner->trigger($event, $modelEvent);

        return $modelEvent->isValid;
    }

    /**
     * Deletes the owner row physically between its `beforeDelete()` and `afterDelete()` hooks.
     *
     * An exception of the fallback class raised by `deleteAll()` is returned rather than thrown, so
     * {@see runInTransaction()} completes the rollback before {@see safeDelete()} starts the fallback. Exceptions
     * raised anywhere else propagate.
     *
     * @param class-string<Throwable> $fallbackClass
     */
    private function deletePhysically(BaseActiveRecord $owner, string $fallbackClass): int|false|Throwable
    {
        if (!$owner->beforeDelete()) {
            return false;
        }

        $condition = $this->oldPrimaryKey($owner);
        $lock = $this->lockCondition($owner);

        try {
            $deleted = $owner::deleteAll(array_replace($condition, $lock));
        } catch (Throwable $exception) {
            if ($exception instanceof $fallbackClass) {
                return $exception;
            }

            throw $exception;
        }

        if ($lock !== [] && $deleted === 0) {
            throw new StaleObjectException(
                Message::DELETED_OBJECT_OUTDATED->getMessage(),
            );
        }

        $owner->setOldAttributes(null);

        if ($deleted > 0) {
            $owner->afterDelete();
        }

        return $deleted;
    }

    /**
     * Returns the lock column mapped to its non-negative version, or an empty array when locking is disabled.
     *
     * @return array<string, int>
     */
    private function lockCondition(BaseActiveRecord $owner): array
    {
        $lock = $owner->optimisticLock();

        if ($lock === null) {
            return [];
        }

        $value = $owner->getAttribute($lock);

        $version = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        if ($version === false || $version < 0) {
            throw new StaleObjectException(
                Message::OPTIMISTIC_LOCK_MUST_BE_NON_NEGATIVE_INTEGER->getMessage(),
            );
        }

        return [$lock => $version];
    }

    /**
     * Returns the persisted primary key, refusing an empty or incomplete one so a write never targets other rows.
     *
     * @return array<array-key, mixed>
     */
    private function oldPrimaryKey(BaseActiveRecord $owner): array
    {
        $condition = $owner->getOldPrimaryKey(true);

        if (!is_array($condition) || $condition === [] || in_array(null, $condition, true)) {
            throw new InvalidConfigException(Message::PERSISTED_PRIMARY_KEY_REQUIRED->getMessage());
        }

        return $condition;
    }

    /**
     * Returns the owner's public method of the given name, or `null` when the owner does not declare it.
     *
     * The visibility check is required: a non-public method would otherwise reach {@see \yii\base\Component::__call()},
     * which dispatches back to the behavior method of the same name and recurses.
     *
     * @return (Closure(): mixed)|null
     */
    private function ownerHook(BaseActiveRecord $owner, string $name): Closure|null
    {
        if (!method_exists($owner, $name)) {
            return null;
        }

        $hook = new ReflectionMethod($owner, $name);

        if (!$hook->isPublic()) {
            throw new InvalidConfigException(
                Message::HOOK_MUST_BE_PUBLIC->getMessage($name),
            );
        }

        return $hook->getClosure($owner);
    }

    /**
     * Returns the owner, refusing a behavior that is not attached to an ActiveRecord.
     */
    private function record(): BaseActiveRecord
    {
        if (!$this->owner instanceof BaseActiveRecord) {
            throw new InvalidConfigException(
                Message::DELETE_BEHAVIOR_REQUIRES_ACTIVE_RECORD_OWNER->getMessage(),
            );
        }

        return $this->owner;
    }

    /**
     * Returns the value to write, invoking a computed value with the owner; a string is always literal.
     */
    private function resolveValue(BaseActiveRecord $owner, mixed $value): mixed
    {
        return !is_scalar($value) && is_callable($value) ? $value($owner) : $value;
    }

    /**
     * Returns the owner attributes to the state captured before a rolled-back operation, removing those absent from
     * it so attributes that were never loaded stay absent.
     *
     * @param array<array-key, mixed> $attributes Captured attribute values.
     * @param array<array-key, mixed> $oldAttributes Captured old attribute values.
     */
    private function restoreState(BaseActiveRecord $owner, array $attributes, array $oldAttributes): void
    {
        foreach ($owner->attributes() as $name) {
            if (array_key_exists($name, $attributes)) {
                $owner->setAttribute($name, $attributes[$name]);
            } else {
                // BaseActiveRecord removes a loaded attribute only through its magic unset.
                $owner->__unset($name);
            }
        }

        $owner->setOldAttributes($oldAttributes);
    }

    /**
     * Creates the exception that reports a rollback failure, keeping its cause as the previous exception.
     */
    private function rollbackFailureException(Throwable $failure, Throwable $cause): RuntimeException
    {
        return new RuntimeException(
            Message::ROLLBACK_FAILED->getMessage($failure->getMessage()),
            0,
            $cause,
        );
    }

    /**
     * Runs the operation inside a transaction and rolls it back unless the operation returns a row count.
     *
     * The owner attributes are captured before the transaction starts and restored after each rollback, including a
     * failed commit of the outermost transaction: its PDO transaction is rolled back when the driver left it open, and
     * the attributes are restored whether or not the driver did. A commit listener that fails after the commit leaves
     * the committed state in place. A rollback failure is wrapped in {@see RuntimeException} when an exception or a
     * returned {@see Throwable} caused the rollback, and rethrown unchanged when the operation returned `false`.
     *
     * @template T of int|false|Throwable
     * @param callable(): T $operation
     * @return T
     */
    private function runInTransaction(BaseActiveRecord $owner, callable $operation): int|false|Throwable
    {
        $db = $owner::getDb();

        if (!$db instanceof Connection) {
            throw new InvalidConfigException(
                Message::TRANSACTION_REQUIRES_SQL_CONNECTION->getMessage(),
            );
        }

        $oldAttributes = $owner->getOldAttributes();

        $attributes = array_replace($oldAttributes, $owner->getDirtyAttributes());

        $transaction = $db->beginTransaction();
        $level = $transaction->getLevel();

        // Yii raises the commit event only after PDO has committed the outermost transaction.
        $committed = false;
        $markCommitted = static function () use (&$committed): void {
            $committed = true;
        };

        $db->on(Connection::EVENT_COMMIT_TRANSACTION, $markCommitted, null, false);

        try {
            $result = $operation();

            if (is_int($result)) {
                $transaction->commit();
                return $result;
            }
        } catch (Throwable $exception) {
            try {
                if ($transaction->getLevel() === $level) {
                    $transaction->rollBack();

                    $this->restoreState($owner, $attributes, $oldAttributes);
                } elseif ($transaction->getLevel() === 0) {
                    // Yii closes its outermost level before PDO commits, and only some drivers keep a transaction
                    // open after its commit failed.
                    $pdo = $db->pdo;

                    if ($pdo?->inTransaction() === true) {
                        $pdo->rollBack();
                    }

                    if (!$committed) {
                        $this->restoreState($owner, $attributes, $oldAttributes);
                    }
                }
            } catch (Throwable $rollbackFailure) {
                throw $this->rollbackFailureException($rollbackFailure, $exception);
            }

            throw $exception;
        } finally {
            $db->off(Connection::EVENT_COMMIT_TRANSACTION, $markCommitted);
        }

        try {
            $transaction->rollBack();
        } catch (Throwable $rollbackFailure) {
            throw $result instanceof Throwable
                ? $this->rollbackFailureException($rollbackFailure, $result)
                : $rollbackFailure;
        }
        $this->restoreState($owner, $attributes, $oldAttributes);

        return $result;
    }

    /**
     * Runs the operation directly, or inside a transaction when the owner declares any of the given operations
     * transactional in its current scenario.
     *
     * @param int $operations {@see ActiveRecord} `OP_*` constants combined with `|`.
     * @param callable(): (int|false) $operation
     */
    private function runOperation(BaseActiveRecord $owner, int $operations, callable $operation): int|false
    {
        return $owner instanceof ActiveRecord && $owner->isTransactional($operations)
            ? $this->runInTransaction($owner, $operation)
            : $operation();
    }

    /**
     * Returns the changed owner attributes merged with the given state values, resolving computed values.
     *
     * @param array<array-key, mixed> $values State values by attribute name.
     * @return array<array-key, mixed>
     */
    private function transitionAttributes(BaseActiveRecord $owner, array $values): array
    {
        $attributes = $owner->getDirtyAttributes();

        foreach ($values as $attribute => $value) {
            $attributes[$attribute] = $this->resolveValue($owner, $value);
        }

        return $attributes;
    }

    /**
     * Writes the given attributes to the owner row, honoring {@see BaseActiveRecord::optimisticLock()}.
     *
     * The primary key and the lock are validated first, so an invalid record throws even with nothing to write. Only
     * values that differ from the stored ones are written, together with the next lock version. The owner receives
     * the values once the stored row holds them: after the UPDATE affected a row, or when no value differed, so a
     * pending edit that contradicts them never stays dirty.
     *
     * @param array<array-key, mixed> $attributes Values to persist, by attribute name.
     * @return int Number of affected rows; `0` when no value differs from the stored one, or when no row matched
     * without optimistic locking.
     */
    private function updateAttributes(BaseActiveRecord $owner, array $attributes): int
    {
        $condition = $this->oldPrimaryKey($owner);
        $lock = $this->lockCondition($owner);

        $old = $owner->getOldAttributes();

        // The UPDATE writes $changes; the model receives $values, whose unchanged values still replace pending edits.
        $values = array_diff_key($attributes, $lock);
        $changes = array_filter(
            $values,
            static fn(mixed $value, int|string $name): bool => !array_key_exists($name, $old) || $old[$name] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        $rows = 0;

        if ($changes !== []) {
            // At most one lock column: the WHERE clause keeps its current version and the row receives the next one.
            foreach ($lock as $name => $version) {
                if ($version === PHP_INT_MAX) {
                    throw new StaleObjectException(
                        Message::OPTIMISTIC_LOCK_CANNOT_BE_INCREMENTED->getMessage(),
                    );
                }

                $changes[$name] = $version + 1;
                $values[$name] = $changes[$name];
            }

            $rows = $owner::updateAll($changes, array_replace($condition, $lock));

            if ($rows === 0) {
                if ($lock !== []) {
                    throw new StaleObjectException(Message::UPDATED_OBJECT_OUTDATED->getMessage());
                }

                return 0;
            }
        }

        foreach ($values as $name => $value) {
            $owner->setAttribute((string) $name, $value);
        }

        $owner->setOldAttributes(array_replace($old, $values));

        return $rows;
    }
}

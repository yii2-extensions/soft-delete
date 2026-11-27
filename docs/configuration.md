# Configuration guide

## Deletion and restoration

Attach `yii2\extensions\softdelete\DeleteBehavior` to an ActiveRecord model. Set `softDeleteAttributeValues` to the
values written by `softDelete()`. Without `restoreAttributeValues`, each restore value is derived from its marker: a
boolean restores to its opposite, the integers `0` and `1` restore to each other, and a callable restores to `null`. Any
other value, such as `2` or `'archived'`, raises `InvalidConfigException` unless `restoreAttributeValues` is set. Set
`useRestoreAttributeValuesAsDefaults` to apply restore values to new records without overwriting explicitly assigned
values.

Call `softDelete()` or `restore()` explicitly. A normal ActiveRecord `delete()` still removes the row. If
`allowDeleteCallback` returns true, `softDelete()` delegates to Yii's native physical delete, which runs in a
transaction only when the model declares `OP_DELETE` transactional. Set `invokeDeleteEvents` to `false` to stop
`softDelete()` from calling the model's `beforeDelete()` and `afterDelete()`. `softDelete()`, `restore()`, and
`safeDelete()` need the primary key, and the lock column when optimistic locking is used, to be loaded; a record loaded
without its primary key raises `InvalidConfigException`.

When the model declares `optimisticLock()`, every write matches the loaded version and stores the next one. A version
that no longer matches the row, is not a non-negative integer, or cannot be incremented raises `StaleObjectException`.

## Query scopes

Use `ScopedQuery` in a model's `find()` and call `notDeleted()` to apply an active-only default. Use `deleted()` or
`withDeleted()` to replace or clear that scope without dropping other query filters. On a plain ActiveQuery with
`QueryBehavior` attached, set the table alias before applying the scope. `ScopedQuery` also handles changes to the
primary alias made with `alias()` after choosing the scope. `from()` does not adjust the scope, so rename the primary
table with `alias()`, or call `from()` before the scope method. Calling `onCondition()` after a scope method discards
the scope, and `orOnCondition()` nests it where `withDeleted()`, `deleted()`, and a later `alias()` no longer adjust it.

`filterDeleted()` maps a filter value to a scope: `''`, `null`, and `[]` select records that are not deleted; `true`,
`1`, and `'1'` select deleted records; `false`, `0`, `'0'`, and `'all'` select all records. Any other value throws
`InvalidArgumentException`, so validate request input before passing it.

`findOne()`, `findAll()`, `refresh()`, and relations build their queries through `find()` and inherit its default scope,
so `findOne()` does not find a soft-deleted record and `refresh()` returns `false` for it. `updateAll()`, `deleteAll()`,
and database cascades bypass the scope. For computed deletion markers, a null restore value defaults to `IS NOT NULL`
for deleted rows. For other rules, set the `deletedCondition` and `notDeletedCondition` properties of `QueryBehavior`
before applying a scope. Distinct active and deleted predicates can leave intermediate states outside both scopes. Give
the rows of a composite `in` condition as an array or a rewindable iterator: a generator is read once and cannot be read
again after a later `alias()`.

## Foreign keys and transactions

On SQL ActiveRecord, `safeDelete()` attempts a physical delete using the record's primary key and optimistic lock.
`deleteFallbackException` names the `Throwable` class that triggers the fallback when the physical statement raises it;
the default is `yii\db\IntegrityException`. An immediate restrictive foreign key then allows the row to be marked
instead. Exceptions from hooks, commit, and rollback are not treated as eligible fallback errors. Review model overrides
of instance-level deletion before using this method.

On Oracle, Yii does not map `ORA-02292` to `IntegrityException` by default. If you want restricted deletes to fall back
to soft deletion, add `'ORA-02292:' => yii\db\IntegrityException::class` to the Oracle schema's `exceptionMap`. Without
that mapping, `safeDelete()` propagates the foreign-key error. Assigning `exceptionMap` replaces Yii's default Oracle
entry, `'ORA-00001: unique constraint'`, so add the new key to the existing map instead of overwriting it.

`safeDelete()` uses transactions. Inside an existing transaction, savepoints must be supported. Explicit soft deletion
and restoration keep Yii's configured transaction policy. A veto returns `false`; an unsaved record or a write affecting
zero rows returns `0` and emits no success event. When the operation rolls back its own transaction or savepoint,
including a transaction whose commit fails, the model returns to its state before the call. Inside an existing
transaction a successful operation only releases its savepoint: if that outer transaction rolls back later, the model
is not restored, so reload it before using it again. A constraint checked at commit, such as a deferred foreign key,
raises the driver's exception and does not trigger the fallback.

A model whose loaded values already equal the target values is not written: `softDelete()` and `restore()` return `0`
and the model keeps those values. As with Yii's `update()`, the stored row is not read again and the optimistic lock is
not checked, so reload a model that another process may have changed. Values are compared strictly, so configure markers
with the type Yii loads for the column. On MySQL a `TINYINT(1)` column loads as an integer, which makes `1` and `0` the
matching markers.

## Lifecycle hooks

Use public model methods `beforeSoftDelete()`, `afterSoftDelete()`, `beforeRestore()`, and `afterRestore()`, or
subscribe on the model to `DeleteBehavior::EVENT_BEFORE_SOFT_DELETE`, `EVENT_AFTER_SOFT_DELETE`, `EVENT_BEFORE_RESTORE`,
and `EVENT_AFTER_RESTORE`. A `beforeSoftDelete()` or `beforeRestore()` method that returns a falsy value cancels the
operation without triggering its event, and a hook declared with another visibility raises `InvalidConfigException`.
Setting `isValid` to `false` on a before event also cancels the operation. Hooks can change dirty attributes, which are
persisted without validation or normal save events. Validate pending input first. Defer irreversible external actions
until after the enclosing database transaction commits.

[Back to documentation](../README.md#documentation)

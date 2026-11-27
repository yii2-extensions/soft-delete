---
name: yii2-soft-delete
description: Configure, query, restore, or troubleshoot soft-deleted Yii ActiveRecord models using yii2-extensions/soft-delete. Use for deletion markers, alias-aware scopes, and physical deletes with a soft-delete fallback. Not for ar-softdelete packages or general database deletion.
license: BSD-3-Clause
---

# Soft delete for Yii ActiveRecord

## Resolve the installed API

Use the actual `yii2-extensions/soft-delete` and `yiisoft/yii2` installations. For a root package checkout, inspect
`composer.json`, Git HEAD, and the working files. For a dependency, use Composer's installed metadata to obtain its real
path, version, and selected reference. Inspect the actual files for path repositories; a lock entry or path reference
alone does not establish which code is installed. These instructions describe the 0.1 API (PHP `>=8.3`, Yii
`~2.0.55 || ^22.0@dev`).

Use the matching installation, configuration, examples or testing guide under `docs/`. The portal
<https://yii2-framework.github.io/docs/extensions/soft-delete/> and the
[package repository](https://github.com/yii2-extensions/soft-delete) can supply matching docs if available. Verify the
revision before using hosted docs. If a distributed package omits `docs/` or the network is unavailable, use the
installed source, PHPDoc, and changelog. The skill has no dependency on a sibling checkout.

## Choose transitions and scopes

- Attach `yii2\extensions\softdelete\DeleteBehavior` to the model. Match marker values to the types Yii loads. Specify
  `softDeleteAttributeValues`; set `restoreAttributeValues` when the inverse is not obvious, and use
  `useRestoreAttributeValuesAsDefaults` only to fill unassigned markers on new rows. With computed markers, verify both
  query predicates. A computed timestamp can use `null` as its active value.
- Call `softDelete()` and `restore()` explicitly. Ordinary `delete()`, bulk writes and database cascades are not made
  soft by this extension. `allowDeleteCallback` can allow `softDelete()` to delete physically; leave it off unless that
  is the requested policy. Distinguish a veto (`false`), no change (`0`), and an affected row.
- Use `ScopedQuery` with `notDeleted()` in the model's `find()` for active-only queries. `deleted()` replaces that
  filter and `withDeleted()` clears it without dropping the rest of the query. Retrieve an archived record through one
  of those scopes before restoring it. `findOne()`, `refresh()`, and relations inherit `find()`.
- On a plain ActiveQuery with `QueryBehavior`, set the alias before choosing the scope. `ScopedQuery::alias()` can
  re-qualify it afterward; `from()` does not. Apply `from()` or `onCondition()` before the scope. Do not place the scope
  inside `orOnCondition()` if later scope switching or alias changes need to find it. Normalize and validate user input
  before calling `filterDeleted()`.
- Use `safeDelete()` only when physical deletion is desired first. An eligible statement exception, by default
  `yii\db\IntegrityException`, can fall back to a soft delete in a separate transaction. Hook, commit and rollback
  failures do not trigger fallback. If an outer transaction exists, verify savepoint support and driver exception
  mapping. Load the persisted primary key and, when configured, the optimistic-lock column.

## Keep intended behavior

These contracts can look like defects. Do not change the package or work around them in the application:

- `softDelete()` and `restore()` return `0` without a query when the loaded marker values already equal the target. The
  stored row is not read again and the optimistic lock is not checked, so reload a model that may be stale.
- A rolled-back call returns the model to its state before the call. A successful call inside an outer transaction only
  releases its savepoint: if the outer transaction rolls back later, reload the model.
- Pass the rows of a composite `in` scope as an array or a rewindable iterator. A generator cannot be read again when
  `alias()` re-qualifies the scope.

## Verify the task

Use a disposable fixture on the affected driver. For an archive/restore feature, verify the row remains in the database,
is absent from the active scope, is visible through the deleted scope, and reappears after restoration. Keep a
non-soft-delete filter in the test so switching scopes cannot accidentally remove it. For a physical fallback feature,
test both successful physical deletion and a restrictive foreign key on the real driver. A SQLite pass does not verify
PostgreSQL deferred constraints, Oracle exception mapping, or another driver's transaction behavior. Do not run a
mutation or migration against production data as a verification step.

# Usage examples

## Scope active and deleted records

This model assumes the `item` table stores `0` for active rows and `1` for deleted rows.

```php
use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;
use yii2\extensions\softdelete\ScopedQuery;

final class Item extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'item';
    }

    public function behaviors(): array
    {
        return [
            'softDelete' => [
                'class' => DeleteBehavior::class,
                'softDeleteAttributeValues' => ['deleted' => 1],
            ],
        ];
    }

    public static function find(): ScopedQuery
    {
        return (new ScopedQuery(static::class))->notDeleted();
    }
}
```

## Delete and restore a record

```php
$item = Item::find()->one();

if ($item !== null) {
    $item->softDelete();
    $item->restore();
}
```

## Select all records or only deleted records

`withDeleted()` returns active and deleted records. `deleted()` returns only deleted records.

```php
$all = Item::find()->withDeleted()->all();
$deleted = Item::find()->alias('i')->deleted()->all();
```

## Mark records with a timestamp

A computed marker receives the model and returns the value to write. Its restore value defaults to `null`, so the scopes
select deleted rows with `IS NOT NULL` and active rows with `IS NULL`.

```php
use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;
use yii2\extensions\softdelete\ScopedQuery;

final class Post extends ActiveRecord
{
    public function behaviors(): array
    {
        return [
            'softDelete' => [
                'class' => DeleteBehavior::class,
                'softDeleteAttributeValues' => ['deleted_at' => static fn(): int => time()],
            ],
        ];
    }

    public static function find(): ScopedQuery
    {
        return (new ScopedQuery(static::class))->notDeleted();
    }
}
```

## Delete permanently when a rule allows it

`allowDeleteCallback` receives the model and must return a `bool`. When it returns `true`, `softDelete()` removes the
row with Yii's `delete()` instead of marking it.

```php
use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;

final class Comment extends ActiveRecord
{
    public function behaviors(): array
    {
        return [
            'softDelete' => [
                'class' => DeleteBehavior::class,
                'softDeleteAttributeValues' => ['is_deleted' => true],
                'allowDeleteCallback' => static fn(Comment $comment): bool => $comment->reply_count === 0,
            ],
        ];
    }
}
```

## Keep a row that other rows still reference

`safeDelete()` removes the row when the database allows it and marks it when a restrictive foreign key rejects the
delete. Both outcomes return `1`; `getIsNewRecord()` is `true` only after the row was removed.

```php
$customer = Customer::findOne(1);

if ($customer !== null && $customer->safeDelete() === 1) {
    $removed = $customer->getIsNewRecord();
}
```

On Oracle, map `ORA-02292` as described in the [Configuration guide](configuration.md#foreign-keys-and-transactions).

## Filter by a request value

`filterDeleted()` selects the scope from a filter value: an empty value selects active records, `'1'` deleted records,
and `'0'` or `'all'` every record. Validate the input first, because any other value throws `InvalidArgumentException`.

```php
use yii\base\Model;
use yii\data\ActiveDataProvider;

final class ItemSearch extends Model
{
    public mixed $deleted = '';

    public function rules(): array
    {
        return [
            ['deleted', 'in', 'range' => ['0', '1', 'all'], 'strict' => true],
        ];
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Item::find();

        if ($this->load($params) && $this->validate()) {
            $query->filterDeleted($this->deleted);
        }

        return new ActiveDataProvider(['query' => $query]);
    }
}
```

## Run code around deletion and restoration

Declare public `beforeSoftDelete()`, `afterSoftDelete()`, `beforeRestore()` or `afterRestore()` methods on the model. A
before-method that returns a falsy value cancels the operation, which then returns `false`.

```php
use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;

final class Document extends ActiveRecord
{
    public function behaviors(): array
    {
        return [
            'softDelete' => [
                'class' => DeleteBehavior::class,
                'softDeleteAttributeValues' => ['is_deleted' => true],
            ],
        ];
    }

    public function beforeSoftDelete(): bool
    {
        return !$this->is_locked;
    }

    public function afterRestore(): void
    {
        Yii::info("Document {$this->id} was restored.", __METHOD__);
    }
}
```

Event handlers work the same way. Set `isValid` to `false` on a before event to cancel:

```php
use yii\base\ModelEvent;
use yii2\extensions\softdelete\DeleteBehavior;

$document->on(
    DeleteBehavior::EVENT_BEFORE_RESTORE,
    static function (ModelEvent $event): void {
        $event->isValid = false;
    },
);

$restored = $document->restore(); // false
```

## Use optimistic locking

When the model declares `optimisticLock()`, each write matches the loaded version and increments it. The lock column
must hold a non-negative integer, so give it a default of `0`.

```php
use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;

final class Article extends ActiveRecord
{
    public function behaviors(): array
    {
        return [
            'softDelete' => [
                'class' => DeleteBehavior::class,
                'softDeleteAttributeValues' => ['is_deleted' => true],
            ],
        ];
    }

    public function optimisticLock(): string
    {
        return 'version';
    }
}
```

A row that another request changed after it was loaded raises `StaleObjectException`:

```php
use yii\db\StaleObjectException;

try {
    $article->softDelete();
} catch (StaleObjectException) {
    // Reload the record and decide again.
}
```

## Annotate the behavior methods

Yii calls behavior methods through `__call()`. Declare them on the model so IDEs and static analyzers know their
signatures. `ScopedQuery` already declares its scope methods.

```php
/**
 * @method int|false softDelete()
 * @method int|false restore()
 * @method int|false safeDelete()
 */
final class Item extends ActiveRecord
{
    // ...
}
```

[Back to documentation](../README.md#documentation)

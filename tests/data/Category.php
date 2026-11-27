<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\data;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;
use yii2\extensions\softdelete\QueryBehavior;

/**
 * @property int $id
 * @property string $name
 * @property bool $isDeleted
 *
 * @property list<Item> $items
 * @method false|int softDelete()
 * @method false|int restore()
 * @method false|int safeDelete()
 */
class Category extends ActiveRecord
{
    use RecordFixture;

    public function behaviors()
    {
        return [
            'softDelete' => [
                'class' => DeleteBehavior::class,
                'softDeleteAttributeValues' => [
                    'isDeleted' => true,
                ],
                'useRestoreAttributeValuesAsDefaults' => true,
            ],
        ];
    }

    /**
     * @return CategoryQuery<static>
     */
    public static function find(): CategoryQuery
    {
        $query = new CategoryQuery(static::class);

        $query->attachBehavior(
            'softDelete',
            ['class' => QueryBehavior::class],
        );

        return $query;
    }

    /**
     * @return ActiveQuery<Item>
     */
    public function getItems(): ActiveQuery
    {
        return $this->hasMany(Item::class, ['categoryId' => 'id']);
    }

    public static function tableName()
    {
        return 'Category';
    }
}

<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\data;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;

/**
 * @property int $id
 * @property int|null $categoryId
 * @property string $name
 * @property bool $isDeleted
 * @property int|null $deletedAt
 * @property int|null $version
 *
 * @property Category|null $category
 * @method false|int softDelete()
 * @method false|int restore()
 * @method false|int safeDelete()
 */
class Item extends ActiveRecord
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
                'allowDeleteCallback' => static function (Item $model): bool {
                    return $model->name === 'allow-delete';
                },
            ],
        ];
    }

    /**
     * @return ActiveQuery<Category>
     */
    public function getCategory(): ActiveQuery
    {
        return $this->hasOne(Category::class, ['id' => 'categoryId']);
    }

    public static function tableName()
    {
        return 'Item';
    }
}

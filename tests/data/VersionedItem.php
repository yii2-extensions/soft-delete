<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\data;

use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;

/**
 * @property int $id
 * @property string $name
 * @property bool $isDeleted
 * @property int $deletedAt
 * @property int $version
 * @method int|false softDelete()
 * @method int|false restore()
 * @method int|false safeDelete()
 */
class VersionedItem extends ActiveRecord
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
            ],
        ];
    }

    public function optimisticLock(): string
    {
        return 'version';
    }

    public static function tableName()
    {
        return 'Item';
    }
}

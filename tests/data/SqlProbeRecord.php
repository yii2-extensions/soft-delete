<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\data;

use yii\db\ActiveRecord;
use yii2\extensions\softdelete\DeleteBehavior;

/**
 * @property int $deleted
 * @method int|false safeDelete()
 * @method int|false softDelete()
 * @method int|false restore()
 */
final class SqlProbeRecord extends ActiveRecord
{
    use RecordFixture;

    public function behaviors(): array
    {
        return [
            'softDelete' => [
                'class' => DeleteBehavior::class,
                'softDeleteAttributeValues' => ['deleted' => 1],
            ],
        ];
    }

    public static function tableName(): string
    {
        return '{{%item}}';
    }
}

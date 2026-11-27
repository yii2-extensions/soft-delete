<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\oracle;

use PHPUnit\Framework\Attributes\Group;
use yii2\extensions\softdelete\tests\base\AbstractSqlIntegrity;
use yii2\extensions\softdelete\tests\support\DatabaseConnection;

/**
 * Unit tests for the {@see AbstractSqlIntegrity} soft-delete SQL integrity checks on the Oracle driver.
 */
#[Group('oci')]
final class SqlIntegrityTest extends AbstractSqlIntegrity
{
    protected function database(): DatabaseConnection
    {
        return DatabaseConnection::ORACLE;
    }
}

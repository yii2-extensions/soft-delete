<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\sqlite;

use PHPUnit\Framework\Attributes\Group;
use yii2\extensions\softdelete\tests\base\AbstractSqlIntegrity;
use yii2\extensions\softdelete\tests\support\DatabaseConnection;

/**
 * Unit tests for the {@see AbstractSqlIntegrity} soft-delete SQL integrity checks on the SQLite driver.
 */
#[Group('sqlite')]
final class SqlIntegrityTest extends AbstractSqlIntegrity
{
    protected function database(): DatabaseConnection
    {
        return DatabaseConnection::SQLITE;
    }
}

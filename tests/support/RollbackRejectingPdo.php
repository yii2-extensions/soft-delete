<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\support;

use PDO;
use PDOException;

/**
 * PDO connection whose rollback fails and leaves the transaction open.
 */
final class RollbackRejectingPdo extends PDO
{
    public function rollBack(): bool
    {
        throw new PDOException('Rollback rejected.');
    }
}

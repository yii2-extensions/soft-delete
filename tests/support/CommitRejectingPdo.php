<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\support;

use PDO;
use PDOException;

/**
 * PDO connection whose commit fails after the transaction is already discarded, as PostgreSQL reports a deferred
 * constraint violation.
 */
final class CommitRejectingPdo extends PDO
{
    public function commit(): bool
    {
        parent::rollBack();

        throw new PDOException('Commit rejected.');
    }
}

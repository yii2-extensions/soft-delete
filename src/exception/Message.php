<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\exception;

use function sprintf;

/**
 * Defines soft-delete exception message templates.
 *
 * Use {@see Message::getMessage()} to format the template with `sprintf()` arguments.
 */
enum Message: string
{
    /**
     * Error when the delete callback is not callable or does not return a boolean.
     */
    case ALLOW_DELETE_CALLBACK_INVALID = "'allowDeleteCallback' must be 'callable' and return a 'boolean'.";

    /**
     * Error when a computed soft-delete marker has a non-null restore value and no explicit deleted condition.
     */
    case COMPUTED_MARKER_REQUIRES_DELETED_CONDITION = "'deletedCondition' must be configured for a computed marker "
        . 'with a non-null active value.';

    /**
     * Error when a restore value is computed and no explicit not-deleted condition is configured.
     */
    case COMPUTED_RESTORE_VALUE_REQUIRES_NOT_DELETED_CONDITION = "'notDeletedCondition' must be configured when "
        . 'restore values are computed.';

    /**
     * Error when a soft-delete condition operator is not a string.
     */
    case CONDITION_OPERATOR_MUST_BE_STRING = "Soft-delete condition operator must be a 'string'.";

    /**
     * Error when the soft-delete behavior is not attached to an ActiveRecord.
     */
    case DELETE_BEHAVIOR_REQUIRES_ACTIVE_RECORD_OWNER = "'DeleteBehavior' requires a 'BaseActiveRecord' owner.";

    /**
     * Error when the delete fallback exception does not name a throwable type.
     */
    case DELETE_FALLBACK_EXCEPTION_INVALID = "'deleteFallbackException' must name a 'Throwable' type.";

    /**
     * Error when the optimistic lock no longer matches the row being deleted.
     */
    case DELETED_OBJECT_OUTDATED = 'The object being deleted is outdated.';

    /**
     * Error when a soft-delete filter value is not one of the accepted values.
     */
    case FILTER_VALUE_UNKNOWN = 'Unknown soft-delete filter value.';

    /**
     * Error when an owner hook is declared with a visibility other than public.
     */
    case HOOK_MUST_BE_PUBLIC = "ActiveRecord hook '%s' must be public.";

    /**
     * Error when the optimistic lock value cannot be incremented.
     */
    case OPTIMISTIC_LOCK_CANNOT_BE_INCREMENTED = 'Optimistic lock value cannot be incremented.';

    /**
     * Error when the optimistic lock value is not a non-negative integer.
     */
    case OPTIMISTIC_LOCK_MUST_BE_NON_NEGATIVE_INTEGER = "Optimistic lock value must be a non-negative 'integer'.";

    /**
     * Error when the record has no complete persisted primary key.
     */
    case PERSISTED_PRIMARY_KEY_REQUIRED = 'Persisted primary key is required for deletion or restoration.';

    /**
     * Error when the query behavior is not attached to a SQL ActiveQuery.
     */
    case QUERY_BEHAVIOR_REQUIRES_ACTIVE_QUERY_OWNER = "'QueryBehavior' requires a SQL 'ActiveQuery' owner.";

    /**
     * Error when the query model does not extend BaseActiveRecord.
     */
    case QUERY_MODEL_MUST_EXTEND_BASE_ACTIVE_RECORD = "The query model must extend 'BaseActiveRecord'.";

    /**
     * Error when the query model has no soft-delete behavior.
     */
    case QUERY_MODEL_MUST_HAVE_DELETE_BEHAVIOR = "The query model must have a 'DeleteBehavior'.";

    /**
     * Error when the restore values cannot be inferred from the soft-delete values.
     */
    case RESTORE_ATTRIBUTE_VALUES_NOT_INFERABLE = 'Unable to determine restore attribute values automatically; '
        . "'%s::restoreAttributeValues' must be set explicitly.";

    /**
     * Error when a rollback fails after the exception that caused it.
     */
    case ROLLBACK_FAILED = "Unable to complete rollback; '%s'.";

    /**
     * Error when a safe delete is requested on a record that is not a SQL ActiveRecord.
     */
    case SAFE_DELETE_REQUIRES_ACTIVE_RECORD = "'safeDelete()' requires a SQL 'ActiveRecord' owner.";

    /**
     * Error when a transactional operation runs on an owner without a Yii SQL connection.
     */
    case TRANSACTION_REQUIRES_SQL_CONNECTION = "Transactional soft delete or restore requires a Yii SQL 'Connection'.";

    /**
     * Error when the optimistic lock no longer matches the row being updated.
     */
    case UPDATED_OBJECT_OUTDATED = 'The object being updated is outdated.';

    /**
     * Returns the formatted message string for the error case.
     *
     * @param int|string ...$argument Values to insert into the message template.
     */
    public function getMessage(int|string ...$argument): string
    {
        return sprintf($this->value, ...$argument);
    }
}

<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete;

use ArrayAccess;
use Closure;
use InvalidArgumentException;
use Traversable;
use yii\db\conditions\AndCondition;
use yii\db\Expression;
use yii2\extensions\softdelete\exception\Message;

use function array_slice;
use function in_array;
use function is_array;
use function is_string;

/**
 * Marks a query condition as owned by the soft-delete scope and qualifies its bare column names with a table alias.
 */
final class Condition extends AndCondition
{
    /**
     * Operators whose first operand is a column name.
     */
    private const array COLUMN_OPERATORS = [
        '=',
        '!=',
        '<>',
        '>',
        '>=',
        '<',
        '<=',
        'is',
        'is not',
        'in',
        'not in',
        'between',
        'not between',
        'like',
        'not like',
        'or like',
        'or not like',
        'ilike',
        'not ilike',
        'or ilike',
        'or not ilike',
    ];
    /**
     * Operators whose operands are nested conditions.
     */
    private const array LOGICAL_OPERATORS = ['and', 'or', 'not'];
    /**
     * Operators whose first operand may be a column tuple.
     */
    private const array TUPLE_OPERATORS = ['in', 'not in'];

    /**
     * Creates a condition with optional table-alias qualification.
     *
     * @param array<array-key, mixed> $condition Condition in Yii array format, kept unqualified for
     * {@see withAlias()}. The rows of an `in` column tuple given as a {@see Traversable} are read each time the
     * condition is qualified, so pass an array or a rewindable iterator: a generator cannot be read again by a later
     * {@see withAlias()}.
     * @param string|null $alias Table alias that qualifies bare column names, or `null` to keep them unqualified.
     */
    public function __construct(private readonly array $condition, string|null $alias = null)
    {
        parent::__construct([$alias === null ? $condition : self::qualify($condition, $alias)]);
    }

    /**
     * Applies the callback to every soft-delete scope inside an `and` condition, recursing into nested `and` operands.
     *
     * A scope the callback maps to `null` is removed, together with any nested `and` left without operands. Other
     * operands, and conditions that do not start with `and`, are returned unchanged.
     *
     * @param array<array-key, mixed> $condition Query condition in Yii array format.
     * @param Closure(self): (?self) $map Callback that returns the replacement scope, or `null` to remove it.
     * @return array<array-key, mixed>|null The mapped condition, or `null` when no operand remains.
     */
    public static function map(array $condition, Closure $map): array|null
    {
        if (($condition[0] ?? null) !== 'and') {
            return $condition;
        }

        $parts = [];

        foreach (array_slice($condition, 1) as $part) {
            if ($part instanceof self) {
                $part = $map($part);
            } elseif (is_array($part)) {
                $part = self::map($part, $map);
            }

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts === []
            ? null
            : ['and', ...$parts];
    }

    /**
     * Returns a new scope with the original condition qualified with the given table alias.
     */
    public function withAlias(string $alias): self
    {
        return new self($this->condition, $alias);
    }

    /**
     * @param array<array-key, mixed> $condition
     * @return array<array-key, mixed>
     */
    private static function qualify(array $condition, string $alias): array
    {
        if (!isset($condition[0])) {
            $result = [];

            foreach ($condition as $column => $value) {
                $result[is_string($column) ? self::qualifyColumn($column, $alias) : $column] = $value;
            }

            return $result;
        }

        if (!is_string($condition[0])) {
            throw new InvalidArgumentException(Message::CONDITION_OPERATOR_MUST_BE_STRING->getMessage());
        }

        $operator = strtolower($condition[0]);

        if (in_array($operator, self::LOGICAL_OPERATORS, true)) {
            foreach ($condition as $key => $operand) {
                if (is_array($operand)) {
                    $condition[$key] = self::qualify($operand, $alias);
                }
            }
        } elseif (in_array($operator, self::COLUMN_OPERATORS, true)) {
            $column = $condition[1] ?? null;

            $tuple = in_array($operator, self::TUPLE_OPERATORS, true);

            if ($tuple && $column instanceof Traversable) {
                $column = iterator_to_array($column, false);
            }

            if (is_string($column)) {
                $condition[1] = self::qualifyColumn($column, $alias);
            } elseif ($tuple && is_array($column)) {
                $condition = self::qualifyTuple($condition, $column, $alias);
            }
        }

        return $condition;
    }

    private static function qualifyColumn(string $column, string $alias): string
    {
        if (str_contains($column, '.') || str_contains($column, '(')) {
            return $column;
        }

        return str_starts_with($column, '[[')
            ? "$alias.$column"
            : "$alias.[[$column]]";
    }

    /**
     * @param array<array-key, mixed> $condition
     * @param array<array-key, mixed> $columns
     * @return array<array-key, mixed>
     */
    private static function qualifyTuple(array $condition, array $columns, string $alias): array
    {
        $keys = [];

        foreach ($columns as $index => $column) {
            if (is_string($column)) {
                $qualified = self::qualifyColumn($column, $alias);
                $columns[$index] = $qualified;
                $keys[$qualified] = $column;
            } elseif ($column instanceof Expression) {
                $keys[$column->expression] = $column->expression;
            }
        }

        $condition[1] = $columns;
        $rows = $condition[2] ?? null;

        if ($rows instanceof Traversable) {
            // Yii reads the rows without their keys, and an iterator may repeat a key for different rows.
            $rows = iterator_to_array($rows, false);
        }

        if (is_array($rows)) {
            $condition[2] = array_map(self::rowQualifier($keys), $rows);
        }

        return $condition;
    }

    /**
     * Returns a callback that maps each row of an `in` tuple to the qualified column names.
     *
     * A row that is neither an array nor {@see ArrayAccess} is returned unchanged.
     *
     * @param array<string, string> $keys Original column names by qualified column name.
     * @return Closure(mixed): mixed
     */
    private static function rowQualifier(array $keys): Closure
    {
        return static function (mixed $row) use ($keys): mixed {
            if (!is_array($row) && !$row instanceof ArrayAccess) {
                return $row;
            }

            $values = [];

            foreach ($keys as $qualified => $name) {
                if (isset($row[$name])) {
                    $values[$qualified] = $row[$name];
                }
            }

            return $values;
        };
    }
}

<?php

namespace App\Services\Sync;

use InvalidArgumentException;

/**
 * Validon emrat e tabelave/kolonave nga config-u para se te perdoren ne DDL.
 * Emrat duhet te jene te sigurt, dhe sync-u nuk mund te preke tabelat e veta te app-it.
 */
final class SchemaGuard
{
    // Columns the sync manages itself on every target table.
    public const SYSTEM_COLUMNS = ['id', 'sync_mapping_id', 'synced_at'];

    // Max 40 chars so generated index names stay under MySQL's 64 character limit. */
    private const TABLE_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    private const COLUMN_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    /**
     * @param  array<int, string>  $protectedTables
     *
     * @throws InvalidArgumentException
     */
    public static function assertTable(string $table, array $protectedTables = []): void
    {
        if (! preg_match(self::TABLE_PATTERN, $table)) {
            throw new InvalidArgumentException(
                "Invalid table name '{$table}'. Use lowercase letters, digits and underscores, starting with a letter (max 40 characters)."
            );
        }

        $protected = array_map('strtolower', $protectedTables);

        if (in_array($table, $protected, true) || str_starts_with($table, 'sync_')) {
            throw new InvalidArgumentException("Table '{$table}' is reserved and cannot be used as a sync target.");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertColumn(string $column): void
    {
        if (! preg_match(self::COLUMN_PATTERN, $column)) {
            throw new InvalidArgumentException(
                "Invalid column name '{$column}'. Use lowercase letters, digits and underscores, starting with a letter."
            );
        }

        if (in_array($column, self::SYSTEM_COLUMNS, true)) {
            throw new InvalidArgumentException("Column '{$column}' is managed by the sync. Pick a different target column name.");
        }
    }
}
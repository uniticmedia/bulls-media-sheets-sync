<?php

namespace App\Services\Sync;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Krijon tabelen qe nuk ekziston dhe ben add t'gjitha kolonat qe i duhen mapp-it.
 *
 *  - nese tabela mungon -> krijohet (id, sync_mapping_id, synced_at + kolonat e mapuara)
 *  - nese kolona mungon -> shtohet me ALTER TABLE (rreshtat ekzistues marrin NULL derisa sync-i i ardhshem t'i mbush)
 *
 *  Nuk fshijme / nuk ndryshojme tipe. Cdo kolone eshte nullable, sepse nje cell ne sheet mund te jete bosh.
 *
 * Duhet thirrur JASHTE nje transaksioni te databazes: ne MySQL, DDL-t bejne commit automatikisht.
 */
final class TableManager
{
    /**
     * @param  array<int, array{target_column: string, data_type: string}>  $columns
     * @return array<int, string> names of the columns that were created (all of them for a new table)
     */
    public function ensure(string $table, array $columns): array
    {
        SchemaGuard::assertTable($table, (array) config('sheets-sync.protected_tables', []));

        foreach ($columns as $column) {
            SchemaGuard::assertColumn($column['target_column']);
        }

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint) use ($columns) {
                $blueprint->id();
                $blueprint->unsignedBigInteger('sync_mapping_id')->nullable()->index();
                $blueprint->timestamp('synced_at')->nullable();

                foreach ($columns as $column) {
                    $this->define($blueprint, $column['target_column'], $column['data_type']);
                }
            });

            return array_column($columns, 'target_column');
        }

        $existing = array_map('strtolower', Schema::getColumnListing($table));

        $missing = array_values(array_filter(
            $columns,
            fn (array $column) => ! in_array($column['target_column'], $existing, true)
        ));
        $needsTrackingColumn = ! in_array('sync_mapping_id', $existing, true);
        $needsSyncedAt = ! in_array('synced_at', $existing, true);

        if ($missing !== [] || $needsTrackingColumn || $needsSyncedAt) {
            Schema::table($table, function (Blueprint $blueprint) use ($missing, $needsTrackingColumn, $needsSyncedAt) {
                if ($needsTrackingColumn) {
                    $blueprint->unsignedBigInteger('sync_mapping_id')->nullable()->index();
                }

                if ($needsSyncedAt) {
                    $blueprint->timestamp('synced_at')->nullable();
                }

                foreach ($missing as $column) {
                    $this->define($blueprint, $column['target_column'], $column['data_type']);
                }
            });
        }

        return array_column($missing, 'target_column');
    }

    private function define(Blueprint $blueprint, string $name, string $type): ColumnDefinition
    {
        $definition = match ($type) {
            'string' => $blueprint->string($name),
            'text' => $blueprint->text($name),
            'integer' => $blueprint->bigInteger($name),
            'decimal' => $blueprint->decimal($name, 18, 4),
            'boolean' => $blueprint->boolean($name),
            'date' => $blueprint->date($name),
            'datetime' => $blueprint->dateTime($name),
            default => throw new InvalidArgumentException("Unknown data type '{$type}' for column '{$name}'."),
        };

        return $definition->nullable();
    }
}
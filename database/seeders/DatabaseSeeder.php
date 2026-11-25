<?php

namespace Database\Seeders;

use App\Models\SyncMapping;
use App\Models\SyncSource;
use Illuminate\Database\Seeder;

/**
 * Demo configuration that mirrors the example from the task description:
 * columns [date, product_name, price, amount] -> table `sales` [date, name, product_price, amount].
 *
 * Replace REPLACE_WITH_SPREADSHEET_ID with a real spreadsheet ID (or edit the source in the UI).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $source = SyncSource::firstOrCreate(
            ['spreadsheet_id' => 'REPLACE_WITH_SPREADSHEET_ID'],
            ['name' => 'Demo sales file', 'is_active' => true],
        );

        $mapping = SyncMapping::firstOrCreate(
            ['source_id' => $source->id, 'tab_name' => 'Sheet1', 'target_table' => 'sales'],
            ['header_row' => 1, 'is_active' => true],
        );

        foreach ([
            ['date', 'date', 'date'],
            ['product_name', 'name', 'string'],
            ['price', 'product_price', 'decimal'],
            ['amount', 'amount', 'integer'],
        ] as [$sourceColumn, $targetColumn, $type]) {
            $mapping->columns()->firstOrCreate(
                ['target_column' => $targetColumn],
                ['source_column' => $sourceColumn, 'data_type' => $type],
            );
        }
    }
}

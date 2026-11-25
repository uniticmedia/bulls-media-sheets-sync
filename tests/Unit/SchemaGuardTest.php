<?php

namespace Tests\Unit;

use App\Services\Sync\SchemaGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SchemaGuardTest extends TestCase
{
    public function test_accepts_safe_table_names(): void
    {
        foreach (['sales', 'product_prices', 'q4_2026', str_repeat('a', 40)] as $name) {
            SchemaGuard::assertTable($name, ['users']);
            $this->assertTrue(true);
        }
    }

    public function test_rejects_unsafe_table_names(): void
    {
        foreach (['Sales', '1sales', 'sales-2026', 'sales; drop table users', 'sales table', '', str_repeat('a', 41)] as $name) {
            try {
                SchemaGuard::assertTable($name);
                $this->fail("'{$name}' should have been rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_protected_and_internal_tables_are_rejected(): void
    {
        foreach (['users', 'migrations', 'sync_runs', 'sync_anything'] as $name) {
            try {
                SchemaGuard::assertTable($name, ['users', 'migrations']);
                $this->fail("'{$name}' should have been rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }

    public function test_column_names(): void
    {
        SchemaGuard::assertColumn('product_price');
        SchemaGuard::assertColumn('date');
        $this->assertTrue(true);

        foreach (['Price', '1st', 'a-b', 'id', 'sync_mapping_id', 'synced_at', ''] as $name) {
            try {
                SchemaGuard::assertColumn($name);
                $this->fail("'{$name}' should have been rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }
}
<?php

namespace Tests\Feature;

use App\Models\SyncMapping;
use App\Models\SyncRun;
use App\Models\SyncSource;
use App\Services\Sheets\SheetsClient;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeSheetsClient;
use Tests\TestCase;

class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    /** [sheet column, database column, type] - the example from the task description. */
    private const SALES = [
        ['date', 'date', 'date'],
        ['product_name', 'name', 'string'],
        ['price', 'product_price', 'decimal'],
        ['amount', 'amount', 'integer'],
    ];

    private FakeSheetsClient $sheets;

    private SyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sheets = new FakeSheetsClient();
        $this->app->instance(SheetsClient::class, $this->sheets);
        $this->sync = $this->app->make(SyncService::class);
    }

    private function mapping(
        string $spreadsheetId,
        string $table = 'sales',
        string $tab = 'Sheet1',
        ?array $columns = null,
        ?SyncSource $source = null,
    ): SyncMapping {
        $source ??= SyncSource::create(['name' => "File {$spreadsheetId}", 'spreadsheet_id' => $spreadsheetId]);
        $mapping = $source->mappings()->create(['tab_name' => $tab, 'target_table' => $table, 'header_row' => 1]);

        foreach ($columns ?? self::SALES as [$sheetColumn, $dbColumn, $type]) {
            $mapping->columns()->create(['source_column' => $sheetColumn, 'target_column' => $dbColumn, 'data_type' => $type]);
        }

        return $mapping;
    }

    public function test_files_with_different_layouts_end_up_in_the_same_table(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [
            ['date', 'product_name', 'price', 'amount'],
            [46023, 'Widget', 9.5, 2],
            [46024, 'Gadget', 3, 1],
        ]);
        // Different column order, extra columns, different header capitalisation.
        $this->sheets->set('file-b', 'Sheet1', [
            ['Internal ID', 'Amount', 'Notes', 'Price', 'Product Name', 'Date'],
            ['x1', 7, 'n/a', '1,200.00', 'Doohickey', 46025],
        ]);

        $a = $this->mapping('file-a');
        $b = $this->mapping('file-b');

        $runs = $this->sync->syncAll();

        $this->assertCount(2, $runs);
        foreach ($runs as $run) {
            $this->assertSame(SyncRun::SUCCESS, $run->status, (string) $run->message);
        }

        $rows = DB::table('sales')->orderBy('date')->get();

        $this->assertCount(3, $rows);
        $this->assertSame('Doohickey', $rows[2]->name);
        $this->assertEquals(1200.0, (float) $rows[2]->product_price);
        $this->assertSame(7, (int) $rows[2]->amount);
        $this->assertSame('2026-01-03', substr((string) $rows[2]->date, 0, 10));
        $this->assertSame(
            [$a->id, $a->id, $b->id],
            $rows->pluck('sync_mapping_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_one_tab_can_feed_several_tables_with_different_structures(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [
            ['date', 'product_name', 'price', 'amount'],
            [46023, 'Widget', 9.5, 2],
        ]);

        $source = SyncSource::create(['name' => 'File A', 'spreadsheet_id' => 'file-a']);
        $this->mapping('file-a', 'sales', 'Sheet1', null, $source);
        $this->mapping('file-a', 'product_catalog', 'Sheet1', [
            ['product_name', 'title', 'string'],
            ['price', 'list_price', 'decimal'],
        ], $source);

        $runs = $this->sync->syncSource($source);

        $this->assertSame([SyncRun::SUCCESS, SyncRun::SUCCESS], array_map(fn ($run) => $run->status, $runs));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame('Widget', DB::table('product_catalog')->value('title'));
        $this->assertEquals(9.5, (float) DB::table('product_catalog')->value('list_price'));
        $this->assertSame(1, $this->sheets->calls, 'The tab should be downloaded once and reused.');
    }

    public function test_a_new_configured_column_is_added_to_the_table_and_filled(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [
            ['date', 'product_name', 'price', 'amount', 'region'],
            [46023, 'Widget', 9.5, 2, 'EU'],
        ]);

        $mapping = $this->mapping('file-a');
        $first = $this->sync->runMapping($mapping);

        $this->assertSame(['date', 'name', 'product_price', 'amount'], $first->columns_added, 'A new table is created with all mapped columns.');
        $this->assertFalse(Schema::hasColumn('sales', 'sales_region'));

        // Someone adds a column to the configuration later on.
        $mapping->columns()->create(['source_column' => 'region', 'target_column' => 'sales_region', 'data_type' => 'string']);
        $second = $this->sync->runMapping($mapping->fresh());

        $this->assertSame(SyncRun::SUCCESS, $second->status, (string) $second->message);
        $this->assertSame(['sales_region'], $second->columns_added);
        $this->assertTrue(Schema::hasColumn('sales', 'sales_region'));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame('EU', DB::table('sales')->value('sales_region'), 'Existing data is back-filled for the new column.');
    }

    public function test_running_twice_does_not_duplicate_rows_and_mirrors_changes_from_the_sheet(): void
    {
        $header = ['date', 'product_name', 'price', 'amount'];
        $this->sheets->set('file-a', 'Sheet1', [$header, [46023, 'Widget', 9.5, 2], [46024, 'Gadget', 3, 1]]);
        $this->sheets->set('file-b', 'Sheet1', [$header, [46025, 'Other file', 1, 1]]);

        $this->mapping('file-a');
        $other = $this->mapping('file-b');

        $this->sync->syncAll();
        $this->sync->syncAll();

        $this->assertSame(3, DB::table('sales')->count());

        // A row is deleted and another one edited in file A.
        $this->sheets->set('file-a', 'Sheet1', [$header, [46023, 'Widget', 10, 2]]);
        $this->sync->syncAll();

        $this->assertSame(2, DB::table('sales')->count());
        $this->assertEquals(10.0, (float) DB::table('sales')->where('name', 'Widget')->value('product_price'));
        $this->assertSame(1, DB::table('sales')->where('sync_mapping_id', $other->id)->count(), 'Rows of other files stay untouched.');
    }

    public function test_application_tables_can_never_be_a_target(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [['product_name'], ['Widget']]);

        $mapping = $this->mapping('file-a', 'users', 'Sheet1', [['product_name', 'name', 'string']]);
        $run = $this->sync->runMapping($mapping);

        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('reserved', (string) $run->message);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_a_missing_sheet_column_is_reported_as_a_warning_and_stored_as_null(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [
            ['date', 'product_name', 'amount'],
            [46023, 'Widget', 2],
        ]);

        $run = $this->sync->runMapping($this->mapping('file-a'));

        $this->assertSame(SyncRun::WARNING, $run->status);
        $this->assertStringContainsString('price', (string) $run->message);
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertNull(DB::table('sales')->value('product_price'));
        $this->assertSame('Widget', DB::table('sales')->value('name'));
    }

    public function test_an_unreadable_tab_fails_only_its_own_mapping(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [
            ['date', 'product_name', 'price', 'amount'],
            [46023, 'Widget', 9.5, 2],
        ]);

        $source = SyncSource::create(['name' => 'File A', 'spreadsheet_id' => 'file-a']);
        $this->mapping('file-a', 'sales_ghost', 'Ghost tab', null, $source);
        $this->mapping('file-a', 'sales', 'Sheet1', null, $source);

        $runs = $this->sync->syncSource($source);

        $this->assertSame([SyncRun::FAILED, SyncRun::SUCCESS], array_map(fn ($run) => $run->status, $runs));
        $this->assertStringContainsString("Tab 'Ghost tab' was not found", (string) $runs[0]->message);
        $this->assertFalse(Schema::hasTable('sales_ghost'), 'A failed read must not create tables.');
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_paused_sources_and_mappings_are_skipped(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [['date', 'product_name', 'price', 'amount'], [46023, 'Widget', 9.5, 2]]);

        $mapping = $this->mapping('file-a');
        $mapping->source->update(['is_active' => false]);

        $this->assertSame([], $this->sync->syncAll());

        $mapping->source->update(['is_active' => true]);
        $mapping->update(['is_active' => false]);

        $this->assertSame([], $this->sync->syncAll());
        $this->assertFalse(Schema::hasTable('sales'));
    }

    public function test_values_are_converted_to_the_configured_column_types(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [
            ['when', 'at', 'qty', 'total', 'paid', 'note'],
            [46023, 46023.5, '1,000', '12,5', 'Yes', 'hello'],
        ]);

        $mapping = $this->mapping('file-a', 'typed', 'Sheet1', [
            ['when', 'happened_on', 'date'],
            ['at', 'happened_at', 'datetime'],
            ['qty', 'quantity', 'integer'],
            ['total', 'total', 'decimal'],
            ['paid', 'is_paid', 'boolean'],
            ['note', 'note', 'text'],
        ]);

        $run = $this->sync->runMapping($mapping);
        $this->assertSame(SyncRun::SUCCESS, $run->status, (string) $run->message);
        $row = DB::table('typed')->first();

        $this->assertSame(SyncRun::SUCCESS, $run->status, (string) $run->message);
        $this->assertSame('2026-01-01', substr((string) $row->happened_on, 0, 10));
        $this->assertSame('2026-01-01 12:00:00', substr((string) $row->happened_at, 0, 19));
        $this->assertSame(1000, (int) $row->quantity);
        $this->assertEquals(12.5, (float) $row->total);
        $this->assertSame(1, (int) $row->is_paid);
        $this->assertSame('hello', $row->note);
    }

    public function test_artisan_command_syncs_and_reports_failures_through_its_exit_code(): void
    {
        $this->sheets->set('file-a', 'Sheet1', [['date', 'product_name', 'price', 'amount'], [46023, 'Widget', 9.5, 2]]);
        $this->mapping('file-a');

        $this->artisan('sheets:sync')->assertExitCode(0);
        $this->assertSame(1, DB::table('sales')->count());

        $this->mapping('file-missing', 'other_table'); // the fake has no such spreadsheet
        $this->artisan('sheets:sync')->assertExitCode(1);
    }

    public function test_the_daily_sync_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('sheets:sync')->assertExitCode(0);
    }
}
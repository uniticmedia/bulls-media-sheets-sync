<?php

namespace Tests\Feature;

use App\Models\SyncMapping;
use App\Models\SyncSource;
use App\Services\Sheets\SheetsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeSheetsClient;
use Tests\TestCase;

class AdminUiTest extends TestCase
{
    use RefreshDatabase;

    private FakeSheetsClient $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sheets-sync.admin.user' => 'admin', 'sheets-sync.admin.password' => 'secret']);

        $this->sheets = new FakeSheetsClient();
        $this->app->instance(SheetsClient::class, $this->sheets);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Basic '.base64_encode('admin:secret')];
    }

    /** @return array<string, mixed> */
    private function validMapping(array $overrides = []): array
    {
        return array_merge([
            'tab_name' => 'Sheet1',
            'target_table' => 'sales',
            'header_row' => 1,
            'is_active' => 1,
            'columns' => [
                ['source_column' => 'date', 'target_column' => 'date', 'data_type' => 'date'],
                ['source_column' => 'product_name', 'target_column' => 'name', 'data_type' => 'string'],
            ],
        ], $overrides);
    }

    public function test_the_ui_requires_credentials(): void
    {
        $this->get('/')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('admin:wrong')])->get('/')->assertStatus(401);
        $this->withHeaders($this->auth())->get('/')->assertOk();
    }

    public function test_the_ui_stays_closed_in_production_until_a_password_is_set(): void
    {
        config(['sheets-sync.admin.password' => null]);
        $this->app['env'] = 'production';

        $this->get('/')->assertForbidden();
    }

    public function test_a_source_can_be_created_from_a_pasted_url(): void
    {
        $response = $this->withHeaders($this->auth())->post('/sources', [
            'name' => 'Sales Q1',
            'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/1AbC_dEf-123/edit#gid=0',
            'is_active' => 1,
        ]);

        $source = SyncSource::firstOrFail();
        $response->assertRedirect(route('sources.show', $source));
        $this->assertSame('1AbC_dEf-123', $source->spreadsheet_id);
        $this->assertTrue($source->is_active);
    }

    public function test_the_same_spreadsheet_cannot_be_added_twice(): void
    {
        SyncSource::create(['name' => 'First', 'spreadsheet_id' => 'abc123']);

        $this->withHeaders($this->auth())
            ->post('/sources', ['name' => 'Second', 'spreadsheet_id' => 'abc123'])
            ->assertSessionHasErrors('spreadsheet_id');

        $this->assertSame(1, SyncSource::count());
    }

    public function test_a_mapping_can_be_created_edited_and_run_from_the_ui(): void
    {
        $this->sheets->set('abc123', 'Sheet1', [
            ['Name', 'Date', 'Product_Name'],
            ['ignored', 46023, 'Widget'],
        ]);
        $source = SyncSource::create(['name' => 'Sales', 'spreadsheet_id' => 'abc123']);

        $this->withHeaders($this->auth())
            ->post("/sources/{$source->id}/mappings", $this->validMapping())
            ->assertRedirect(route('sources.show', $source));

        $mapping = SyncMapping::firstOrFail();
        $this->assertSame(2, $mapping->columns()->count());

        // Edit: add a third column.
        $this->withHeaders($this->auth())
            ->put("/mappings/{$mapping->id}", $this->validMapping([
                'columns' => [
                    ['source_column' => 'date', 'target_column' => 'date', 'data_type' => 'date'],
                    ['source_column' => 'product_name', 'target_column' => 'name', 'data_type' => 'string'],
                    ['source_column' => 'name', 'target_column' => 'label', 'data_type' => 'string'],
                ],
            ]))
            ->assertRedirect(route('sources.show', $source));

        $this->assertSame(3, $mapping->columns()->count());

        // Run it.
        $this->withHeaders($this->auth())
            ->post("/mappings/{$mapping->id}/run")
            ->assertSessionHas('status_level', 'success');

        $row = DB::table('sales')->first();
        $this->assertSame('Widget', $row->name);
        $this->assertSame('ignored', $row->label);

        // The pages render and show the result.
        $this->withHeaders($this->auth())->get("/sources/{$source->id}")->assertOk()->assertSee('sales');
        $this->withHeaders($this->auth())->get('/sources')->assertOk()->assertSee('Sales');
        $this->withHeaders($this->auth())->get("/sources/{$source->id}/mappings/create")->assertOk();
        $this->withHeaders($this->auth())->get("/mappings/{$mapping->id}/edit")->assertOk()->assertSee('product_name');
        $this->withHeaders($this->auth())->get('/runs')->assertOk()->assertSee('success');
        $this->withHeaders($this->auth())->get('/')->assertOk();
    }

    public function test_invalid_mappings_are_rejected(): void
    {
        $source = SyncSource::create(['name' => 'Sales', 'spreadsheet_id' => 'abc123']);

        $this->withHeaders($this->auth())
            ->post("/sources/{$source->id}/mappings", $this->validMapping(['target_table' => 'users']))
            ->assertSessionHasErrors('target_table');

        $this->withHeaders($this->auth())
            ->post("/sources/{$source->id}/mappings", $this->validMapping(['target_table' => 'sales; drop table users']))
            ->assertSessionHasErrors('target_table');

        $this->withHeaders($this->auth())
            ->post("/sources/{$source->id}/mappings", $this->validMapping(['columns' => [
                ['source_column' => 'id', 'target_column' => 'id', 'data_type' => 'integer'],
            ]]))
            ->assertSessionHasErrors('columns.0.target_column');

        $this->withHeaders($this->auth())
            ->post("/sources/{$source->id}/mappings", $this->validMapping(['columns' => [
                ['source_column' => 'a', 'target_column' => 'dup', 'data_type' => 'string'],
                ['source_column' => 'b', 'target_column' => 'dup', 'data_type' => 'string'],
            ]]))
            ->assertSessionHasErrors();

        $this->withHeaders($this->auth())
            ->post("/sources/{$source->id}/mappings", $this->validMapping(['columns' => [
                ['source_column' => 'a', 'target_column' => 'a', 'data_type' => 'blob'],
            ]]))
            ->assertSessionHasErrors('columns.0.data_type');

        $this->assertSame(0, SyncMapping::count());
    }

    public function test_blank_column_rows_are_ignored_and_names_are_normalised(): void
    {
        $source = SyncSource::create(['name' => 'Sales', 'spreadsheet_id' => 'abc123']);

        $this->withHeaders($this->auth())
            ->post("/sources/{$source->id}/mappings", $this->validMapping([
                'target_table' => 'Sales',
                'columns' => [
                    ['source_column' => ' Product Name ', 'target_column' => 'Name', 'data_type' => 'string'],
                    ['source_column' => '', 'target_column' => '', 'data_type' => 'string'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $mapping = SyncMapping::firstOrFail();
        $this->assertSame('sales', $mapping->target_table);
        $this->assertSame(1, $mapping->columns()->count());
        $this->assertSame('name', $mapping->columns()->first()->target_column);
        $this->assertSame('Product Name', $mapping->columns()->first()->source_column);
    }

    public function test_deleting_a_source_keeps_already_stored_data(): void
    {
        $this->sheets->set('abc123', 'Sheet1', [['Name', 'Date'], ['Widget', 46023]]);
        $source = SyncSource::create(['name' => 'Sales', 'spreadsheet_id' => 'abc123']);

        $this->withHeaders($this->auth())->post("/sources/{$source->id}/mappings", $this->validMapping([
            'columns' => [
                ['source_column' => 'name', 'target_column' => 'name', 'data_type' => 'string'],
                ['source_column' => 'date', 'target_column' => 'date', 'data_type' => 'date'],
            ],
        ]));
        $this->withHeaders($this->auth())->post("/sources/{$source->id}/run");
        $this->assertSame(1, DB::table('sales')->count());

        $this->withHeaders($this->auth())->delete("/sources/{$source->id}")->assertRedirect(route('sources.index'));

        $this->assertSame(0, SyncSource::count());
        $this->assertSame(0, SyncMapping::count());
        $this->assertSame(1, DB::table('sales')->count());
    }
}
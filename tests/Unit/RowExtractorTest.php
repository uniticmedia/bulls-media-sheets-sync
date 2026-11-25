<?php

namespace Tests\Unit;

use App\Services\Sync\HeaderMatcher;
use App\Services\Sync\RowExtractor;
use App\Services\Sync\ValueCaster;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RowExtractorTest extends TestCase
{
    private RowExtractor $extractor;

    /** @var array<int, array{source_column: string, target_column: string, data_type: string}> */
    private array $columns = [
        ['source_column' => 'date', 'target_column' => 'date', 'data_type' => 'date'],
        ['source_column' => 'product_name', 'target_column' => 'name', 'data_type' => 'string'],
        ['source_column' => 'price', 'target_column' => 'product_price', 'data_type' => 'decimal'],
        ['source_column' => 'amount', 'target_column' => 'amount', 'data_type' => 'integer'],
    ];

    protected function setUp(): void
    {
        $this->extractor = new RowExtractor(new ValueCaster());
    }

    public function test_header_names_are_normalised(): void
    {
        $this->assertSame('product name', HeaderMatcher::normalize(' Product_Name '));
        $this->assertSame('product name', HeaderMatcher::normalize('PRODUCT-NAME'));
        $this->assertSame('prix unitaire', HeaderMatcher::normalize('Prix   Unitaire'));
        $this->assertSame(['a' => 0, 'b' => 2], HeaderMatcher::index(['A', '', 'b', 'a']));
    }

    public function test_extracts_by_header_name_not_by_position(): void
    {
        $sheet = [
            ['Notes', 'Amount', 'Price', 'Product Name', 'Date', 'Internal ID'],
            ['n1', 2, 9.5, 'Widget', 46023, 'x1'],
            ['n2', 7, '1,200.00', 'Gadget', 46024, 'x2'],
        ];

        $result = $this->extractor->extract($sheet, 1, $this->columns);

        $this->assertSame([
            ['date' => '2026-01-01', 'name' => 'Widget', 'product_price' => '9.5', 'amount' => 2],
            ['date' => '2026-01-02', 'name' => 'Gadget', 'product_price' => '1200.00', 'amount' => 7],
        ], $result['rows']);
        $this->assertSame(2, $result['read']);
        $this->assertSame([], $result['missing_columns']);
        $this->assertSame(0, $result['cast_errors']);
    }

    public function test_same_data_in_a_different_layout_gives_the_same_rows(): void
    {
        $a = [['date', 'product_name', 'price', 'amount'], [46023, 'Widget', 9.5, 2]];
        $b = [['amount', 'extra', 'price', 'product_name', 'date'], [2, 'zzz', 9.5, 'Widget', 46023]];

        $this->assertSame(
            $this->extractor->extract($a, 1, $this->columns)['rows'],
            $this->extractor->extract($b, 1, $this->columns)['rows'],
        );
    }

    public function test_header_row_can_be_below_the_first_row(): void
    {
        $sheet = [
            ['Quarterly report'],
            [],
            ['date', 'product_name', 'price', 'amount'],
            [46023, 'Widget', 9.5, 2],
        ];

        $result = $this->extractor->extract($sheet, 3, $this->columns);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Widget', $result['rows'][0]['name']);
    }

    public function test_empty_rows_are_skipped_and_short_rows_are_padded(): void
    {
        $sheet = [
            ['date', 'product_name', 'price', 'amount'],
            [46023, 'Widget'],          // short row: price and amount missing
            [],                         // completely empty
            ['', '', '', ''],           // blank cells
            [46024, 'Gadget', 3, 1],
        ];

        $result = $this->extractor->extract($sheet, 1, $this->columns);

        $this->assertCount(2, $result['rows']);
        $this->assertNull($result['rows'][0]['product_price']);
        $this->assertNull($result['rows'][0]['amount']);
        $this->assertSame(4, $result['read']);
        $this->assertSame(2, $result['skipped_empty']);
    }

    public function test_a_missing_column_is_reported_and_filled_with_null(): void
    {
        $sheet = [['date', 'product_name', 'amount'], [46023, 'Widget', 2]];

        $result = $this->extractor->extract($sheet, 1, $this->columns);

        $this->assertSame(['price'], $result['missing_columns']);
        $this->assertNull($result['rows'][0]['product_price']);
        $this->assertSame('Widget', $result['rows'][0]['name']);
    }

    public function test_fails_when_no_configured_column_is_found(): void
    {
        $this->expectException(RuntimeException::class);

        $this->extractor->extract([['foo', 'bar'], [1, 2]], 1, $this->columns);
    }

    public function test_fails_when_the_header_row_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);

        $this->extractor->extract([], 1, $this->columns);
    }

    public function test_unconvertible_values_become_null_and_are_counted(): void
    {
        $sheet = [
            ['date', 'product_name', 'price', 'amount'],
            [46023, 'Widget', 'n/a', 2],
            [46024, 'Gadget', 3, 'two'],
        ];

        $result = $this->extractor->extract($sheet, 1, $this->columns);

        $this->assertCount(2, $result['rows']);
        $this->assertNull($result['rows'][0]['product_price']);
        $this->assertNull($result['rows'][1]['amount']);
        $this->assertSame(2, $result['cast_errors']);
        $this->assertCount(2, $result['error_samples']);
        $this->assertStringContainsString('row 2', $result['error_samples'][0]);
    }
}
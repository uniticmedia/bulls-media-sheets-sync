<?php

namespace Tests\Unit;

use App\Services\Sync\ValueCaster;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ValueCasterTest extends TestCase
{
    private ValueCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new ValueCaster();
    }

    public function test_empty_values_become_null_for_every_type(): void
    {
        foreach (ValueCaster::TYPES as $type) {
            $this->assertNull($this->caster->cast(null, $type), $type);
            $this->assertNull($this->caster->cast('', $type), $type);
            $this->assertNull($this->caster->cast("   \t", $type), $type);
        }
    }

    public function test_dates_from_google_serial_numbers(): void
    {
        $this->assertSame('2026-01-01', $this->caster->cast(46023, 'date'));
        $this->assertSame('2026-01-01', $this->caster->cast(46023.75, 'date'));
        $this->assertSame('2026-01-01 12:00:00', $this->caster->cast(46023.5, 'datetime'));
        $this->assertSame('1899-12-30', $this->caster->cast(0, 'date'));
    }

    public function test_dates_from_text(): void
    {
        $this->assertSame('2026-03-04', $this->caster->cast('2026-03-04', 'date'));
        $this->assertSame('2026-03-04 09:30:00', $this->caster->cast('2026-03-04 09:30', 'datetime'));
    }

    public function test_invalid_dates_are_rejected(): void
    {
        foreach (['not a date', '2026-02-30', true] as $bad) {
            try {
                $this->caster->cast($bad, 'date');
                $this->fail('Expected an exception for '.var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_decimals(): void
    {
        $this->assertSame('19.99', $this->caster->cast(19.99, 'decimal'));
        $this->assertSame('5', $this->caster->cast(5, 'decimal'));
        $this->assertSame('1234.50', $this->caster->cast('1,234.50', 'decimal'));
        $this->assertSame('1234.56', $this->caster->cast('1.234,56', 'decimal'));
        $this->assertSame('12.5', $this->caster->cast('12,5', 'decimal'));
        $this->assertSame('99', $this->caster->cast('$ 99', 'decimal'));
        $this->assertSame('1234567', $this->caster->cast('1,234,567', 'decimal'));
        $this->assertSame('-3.25', $this->caster->cast('-3.25', 'decimal'));
    }

    public function test_invalid_decimals_are_rejected(): void
    {
        foreach (['abc', '1.2.3x', true] as $bad) {
            try {
                $this->caster->cast($bad, 'decimal');
                $this->fail('Expected an exception for '.var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_integers(): void
    {
        $this->assertSame(3, $this->caster->cast(3, 'integer'));
        $this->assertSame(3, $this->caster->cast(3.0, 'integer'));
        $this->assertSame(1000, $this->caster->cast('1,000', 'integer'));
        $this->assertSame(-7, $this->caster->cast('-7', 'integer'));
    }

    public function test_invalid_integers_are_rejected(): void
    {
        foreach ([3.5, '12x', '1.5', true] as $bad) {
            try {
                $this->caster->cast($bad, 'integer');
                $this->fail('Expected an exception for '.var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_booleans(): void
    {
        foreach ([true, 1, 1.0, 'TRUE', 'Yes', 'y', '1'] as $truthy) {
            $this->assertSame(1, $this->caster->cast($truthy, 'boolean'), var_export($truthy, true));
        }

        foreach ([false, 0, 'FALSE', 'No', 'n', '0'] as $falsy) {
            $this->assertSame(0, $this->caster->cast($falsy, 'boolean'), var_export($falsy, true));
        }
    }

    public function test_invalid_booleans_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->caster->cast('maybe', 'boolean');
    }

    public function test_strings(): void
    {
        $this->assertSame('hello', $this->caster->cast('  hello ', 'string'));
        $this->assertSame('5', $this->caster->cast(5.0, 'string'));
        $this->assertSame('0.25', $this->caster->cast(0.25, 'text'));
        $this->assertSame('true', $this->caster->cast(true, 'string'));
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->caster->cast('x', 'blob');
    }
}
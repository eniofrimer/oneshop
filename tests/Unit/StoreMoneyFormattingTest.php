<?php

namespace Tests\Unit;

use App\Storefront\StoreCatalog;
use PHPUnit\Framework\TestCase;

class StoreMoneyFormattingTest extends TestCase
{
    public function test_eur_minor_units_keep_cents_sign_and_grouping(): void
    {
        foreach ([
            [0, '0,00 €'], [1, '0,01 €'], [9, '0,09 €'], [99, '0,99 €'],
            [100, '1,00 €'], [101, '1,01 €'], [999, '9,99 €'], [1000, '10,00 €'],
            [100000, '1.000,00 €'], [123456789, '1.234.567,89 €'],
            [-1, '-0,01 €'], [-99, '-0,99 €'], [-100, '-1,00 €'], [-101, '-1,01 €'],
            [-123456789, '-1.234.567,89 €'],
        ] as [$minor, $expected]) {
            $this->assertSame($expected, StoreCatalog::money($minor), (string) $minor);
        }
    }

    public function test_integer_boundaries_do_not_round_away_real_cents(): void
    {
        $this->assertSame(PHP_INT_SIZE === 8 ? '92.233.720.368.547.758,07 €' : '21.474.836,47 €', StoreCatalog::money(PHP_INT_MAX));
        $this->assertSame(PHP_INT_SIZE === 8 ? '-92.233.720.368.547.758,08 €' : '-21.474.836,48 €', StoreCatalog::money(PHP_INT_MIN));
        if (PHP_INT_SIZE === 8) {
            // The previous float division displays 92 cents for this integer.
            $this->assertSame('90.071.992.547.409,93 €', StoreCatalog::money(9007199254740993));
            $this->assertSame('-90.071.992.547.409,93 €', StoreCatalog::money(-9007199254740993));
        }
    }
}

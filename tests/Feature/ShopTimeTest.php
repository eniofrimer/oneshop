<?php

namespace Tests\Feature;

use App\Support\ShopTime;
use Carbon\Carbon;
use Tests\TestCase;

class ShopTimeTest extends TestCase
{
    public function test_display_handles_winter_summer_and_dst_without_mutating_the_source_instant(): void
    {
        config(['oneshop.display_timezone' => 'Europe/Zagreb']);
        foreach (['2026-10-30 21:35:00' => '30.10.2026. 22:35', '2026-07-10 21:35:00' => '10.07.2026. 23:35',
            '2026-10-25 00:30:00' => '25.10.2026. 02:30', '2026-10-25 01:30:00' => '25.10.2026. 02:30'] as $instant => $display) {
            $date = Carbon::parse($instant, 'UTC');
            $this->assertSame($display, ShopTime::format($date));
            $this->assertSame($instant, $date->format('Y-m-d H:i:s'));
            $this->assertSame('UTC', $date->timezoneName);
        }
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('—', ShopTime::format(null));
    }

    public function test_installation_can_select_another_display_zone(): void
    {
        config(['oneshop.display_timezone' => 'America/New_York']);
        $this->assertSame('30.10.2026. 17:35', ShopTime::format(Carbon::parse('2026-10-30 21:35:00', 'UTC')));
    }

    public function test_invalid_time_zone_is_rejected_instead_of_silently_changing_dates(): void
    {
        config(['oneshop.display_timezone' => 'not-an-iana-zone']);
        $this->expectException(\LogicException::class);
        ShopTime::zone();
    }
}

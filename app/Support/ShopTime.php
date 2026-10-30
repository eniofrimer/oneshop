<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;

/** Presentation only; application/database timestamps remain in UTC. */
class ShopTime
{
    public static function zone(): string
    {
        $zone = config('oneshop.display_timezone');
        if (! is_string($zone) || ! in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new \LogicException('Configure ONESHOP_DISPLAY_TIMEZONE with a valid IANA time zone.');
        }

        return $zone;
    }

    public static function format(?CarbonInterface $date, string $format = 'd.m.Y. H:i'): string
    {
        return $date?->copy()->timezone(self::zone())->format($format) ?? '—';
    }
}

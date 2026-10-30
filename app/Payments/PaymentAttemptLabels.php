<?php

namespace App\Payments;

use Brick\Math\BigDecimal;

class PaymentAttemptLabels
{
    public const STATUSES = ['prepared' => 'Pripremljeno', 'pending' => 'Čeka potvrdu',
        'unknown' => 'Nepoznat ishod', 'paid' => 'Testno plaćeno', 'expired' => 'Potvrđeno isteklo', 'review' => 'Potrebna provjera'];

    public static function money(int $minor, string $currency): string
    {
        // Only EUR has a supported frozen precision in this milestone. Other
        // actual capture currencies remain explicit integer minor units.
        return $currency === 'EUR' ? str_replace('.', ',', (string) BigDecimal::ofUnscaledValue($minor, 2)).' EUR'
            : $minor.' '.$currency.' (najmanje jedinice)';
    }

    public static function reason(?string $reason): string
    {
        return match ($reason) {
            'creation-window-closed' => 'Istekao je siguran prozor ponavljanja. Potrebno je usklađivanje s procesorom.',
            'provider-account-mismatch' => 'Konfigurirani račun ne odgovara računu procesora.',
            'provider-session-mismatch' => 'Objekt procesora ne odgovara ovom pokušaju.',
            'capture-amount-mismatch', 'session-total-mismatch', 'provider-capture-inconsistent' => 'Potvrđeni iznosi procesora zahtijevaju provjeru.',
            'capture-currency-mismatch' => 'Valuta stvarne potvrde razlikuje se od očekivane valute.',
            'capture-after-terminal-state' => 'Potvrđena naplata stigla je nakon razriješenog stanja. Isporuka nije dodijeljena.',
            'inventory-allocation-failed' => 'Potvrđena naplata nije sigurno povezana sa zalihom.',
            'provider-reversal-observed' => 'Procesor prikazuje povrat ili osporavanje. Zaliha nije automatski vraćena.',
            'order-snapshot-mismatch', 'order-mode-mismatch', 'order-already-captured', 'session-state-mismatch' => 'Spremljena narudžba ili stanje naplate zahtijeva usklađivanje.',
            null => '—',
            default => 'Potrebno je usklađivanje.',
        };
    }
}

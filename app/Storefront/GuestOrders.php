<?php

namespace App\Storefront;

use App\Checkout\DevelopmentCheckoutService;
use App\Checkout\StripeSandboxCheckout;
use App\Models\DevelopmentCheckout;
use App\Models\PaymentAttempt;
use App\Orders\OrderMoney;
use App\Payments\PaymentAttemptLabels;
use App\Payments\StripeSandboxConfiguration;
use App\Support\ShopTime;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lunar\Models\Order;

/** Only the existing server-side guest capabilities; no email/URL ownership lookup. */
class GuestOrders
{
    public static function enabled(): bool
    {
        return DevelopmentCheckoutService::enabled() || StripeSandboxConfiguration::available();
    }

    /** @return Collection<int, array{reference: string, amount: string, status: string, state: string, mode: string, url: string, placed_at: string, sort_at: string}> */
    public function recent(Session $session): Collection
    {
        abort_unless(self::enabled(), 404);
        $rows = collect();
        if (DevelopmentCheckoutService::enabled()) {
            $checkouts = DevelopmentCheckout::query()->whereIn('id', $this->ownedIds($session, DevelopmentCheckoutService::OWNED_KEY))
                ->whereHas('order', fn ($query) => $query->whereNotNull('placed_at')->where('meta->oneshop_mode', 'demo'))
                ->with('order.currency')->get();
            foreach ($checkouts as $checkout) {
                $status = match ($checkout->status) {
                    'paid' => 'Testno potvrđena', 'cancelled' => 'Testno otkazana', 'expired' => 'Test je istekao',
                    'declined' => 'Testno odbijena', default => 'Čeka testnu potvrdu',
                };
                $rows->push($this->row($checkout->order, $status, $checkout->status, 'Testna simulacija', route('store.checkout.payment', $checkout->id)));
            }
        }
        if (StripeSandboxConfiguration::available()) {
            $identity = app(StripeSandboxConfiguration::class)->identity();
            $attempts = PaymentAttempt::query()->whereIn('id', $this->ownedIds($session, StripeSandboxCheckout::OWNED_KEY))
                ->where('provider', $identity->provider)->where('provider_account', $identity->account)->where('environment', $identity->environment)
                ->whereHas('order', fn ($query) => $query->whereNotNull('placed_at')->where('meta->oneshop_mode', 'stripe-test'))
                ->with('order.currency')->get();
            foreach ($attempts as $attempt) {
                $rows->push($this->row($attempt->order, PaymentAttemptLabels::STATUSES[$attempt->status] ?? 'Potrebna provjera',
                    $attempt->status, 'Stripe sandbox', route('store.stripe.return', $attempt->id)));
            }
        }

        return $rows->sortByDesc('sort_at')->values();
    }

    /** @return list<string> */
    private function ownedIds(Session $session, string $key): array
    {
        $ids = $session->get($key, []);
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_slice($ids, -20), fn ($id) => is_string($id) && Str::isUuid($id))));
    }

    /** @return array{reference: string, amount: string, status: string, state: string, mode: string, url: string, placed_at: string, sort_at: string} */
    private function row(Order $order, string $status, string $state, string $mode, string $url): array
    {
        return ['reference' => (string) $order->reference, 'amount' => OrderMoney::format($order, 'total'),
            'status' => $status, 'state' => $state, 'mode' => $mode, 'url' => $url,
            'placed_at' => ShopTime::format($order->placed_at, 'd.m.Y. · H:i'),
            'sort_at' => $order->placed_at->utc()->format('Y-m-d H:i:s.u')];
    }
}

@extends('storefront.layout')
@section('title', 'Stripe testno plaćanje — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.home') }}">← Na kolekciju</a>
    <x-storefront.checkout-progress :step="3" payment="Stripe test" />
    <div class="payment-panel">
        <p class="eyebrow">STRIPE SANDBOX · BEZ STVARNOG NOVCA</p>
        @if($attempt->status === 'paid')
            <span class="payment-symbol" aria-hidden="true">✓</span>
            <h1>Stripe testna kupnja je potvrđena<span>.</span></h1>
            <p>Testni procesor potvrdio je naplatu, a narudžba je zabilježena. Stvarni novac nije naplaćen i paket se ne šalje.</p>
        @elseif($attempt->status === 'expired')
            <h1>Stripe je potvrdio istek.</h1>
            <p>Ovaj pokušaj više se ne može platiti. Rezervirane količine ponovno su raspoložive.</p>
        @elseif($attempt->status === 'review')
            <h1>Narudžba zahtijeva provjeru.</h1>
            <p>Nemoj pokretati novo plaćanje za ovu narudžbu. Potvrda kupnje i isporuka nisu automatski dovršene.</p>
        @elseif($attempt->status === 'unknown')
            <h1>Čekamo pouzdanu potvrdu.</h1>
            <p>Trenutačno ne možemo potvrditi konačan ishod. Zaliha ostaje rezervirana. Nastavi provjeru ovog pokušaja.</p>
        @else
            <h1>{{ $attempt->status === 'prepared' ? 'Spremno za Stripe test.' : 'Čekamo Stripeovu potvrdu.' }}</h1>
            <p>Testno plaćanje otvara se na Stripeovoj stranici. Koristi isključivo testnu karticu. Povratak na ovu stranicu sam po sebi ne potvrđuje naplatu.</p>
        @endif
        <div class="payment-reference"><span>Narudžba <strong>{{ $order->reference }}</strong></span><span>{{ \App\Payments\PaymentAttemptLabels::money($attempt->amount_minor, $attempt->currency_code) }}</span></div>
        @include('storefront.partials.order-snapshot', ['order' => $order])
        @if(in_array($attempt->status, ['prepared', 'pending', 'unknown'], true))
            <div class="simulation-actions">
                <form method="post" action="{{ route('store.stripe.launch', $attempt->id) }}">@csrf<button class="button">Nastavi na Stripe test ↗</button></form>
                <form method="post" action="{{ route('store.stripe.refresh', $attempt->id) }}">@csrf<button class="secondary-button">Provjeri status</button></form>
                <form method="post" action="{{ route('store.stripe.cancel', $attempt->id) }}">@csrf<button class="text-button">Zatraži otkazivanje</button></form>
            </div>
            <p class="price-note">Zaliha se oslobađa tek kad Stripe potvrdi istek. Zatvaranje preglednika ne otkazuje ovaj pokušaj.</p>
        @else
            <a class="button" href="{{ route('store.home') }}#kolekcija">Istraži kolekciju ↗</a>
        @endif
        <p class="price-note">Ovo nije potvrda stvarne naplate niti fiskalni račun.</p>
        <p><a class="text-link" href="{{ route('store.orders') }}">Sve moje testne narudžbe ↗</a></p>
    </div>
@endsection

@extends('storefront.layout')
@section('title', 'Testno plaćanje — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.home') }}">← Na kolekciju</a>
    <div class="checkout-steps"><span>01 Adresa</span><span>02 Pregled</span><strong>03 Testno plaćanje</strong></div>
    <div class="payment-panel"><p class="eyebrow">RAZVOJNI SIMULATOR · BEZ STVARNOG NOVCA</p>
        @if($checkout->status === 'paid')<span class="payment-symbol" aria-hidden="true">✓</span><h1>Testna kupnja je uspjela<span>.</span></h1><p>Narudžba je zabilježena. Testna transakcija i promjena zalihe vidljive su u administraciji.</p>
        @elseif($checkout->status === 'cancelled')<h1>Testna kupnja je otkazana.</h1><p>Testna narudžba je zatvorena.@if($checkout->uses_stock_reservations) Rezervirane količine ponovno su raspoložive.@endif</p>
        @elseif($checkout->status === 'expired' || $checkout->expires_at->isPast())<h1>Vrijeme za test je isteklo.</h1><p>Za novu probu pokreni novu kupnju iz kolekcije.</p>
        @else<h1>{{ $checkout->status === 'declined' ? 'Plaćanje je testno odbijeno.' : 'Još jedan testni korak.' }}</h1><p>{{ $checkout->status === 'declined' ? ($checkout->uses_stock_reservations ? 'Rezervacija ostaje za ponovni pokušaj do isteka testa.' : 'Zaliha nije promijenjena. Možeš ponovno pokušati s istom narudžbom.') : 'Odaberi ishod za provjeru kupnje. Ne unosi kartične podatke.' }}</p>@endif
        <div class="payment-reference"><span>Narudžba <strong>{{ $order->reference }}</strong></span><span>{{ \App\Orders\OrderMoney::format($order, 'total') }}</span></div>
        @include('storefront.partials.order-snapshot', ['order' => $order])
        @if(in_array($checkout->status, ['pending', 'declined'], true) && !$checkout->expires_at->isPast())
            <form method="post" action="{{ route('store.checkout.simulate', $checkout->id) }}" class="simulation-actions">@csrf<button class="button" name="outcome" value="paid">Simuliraj uspješno plaćanje ✓</button><button class="secondary-button" name="outcome" value="declined">Simuliraj odbijanje</button><button class="text-button" name="outcome" value="cancelled">Otkaži testnu narudžbu</button></form>
            <p class="price-note">Testna narudžba vrijedi do {{ $checkout->expires_at->timezone('Europe/Zagreb')->format('H:i') }} po hrvatskom vremenu.
                @if($checkout->uses_stock_reservations)Praćena zaliha čuva se rezervacijom tijekom testa. Otkazivanjem oslobađaš rezervirane količine.
                @else Zaliha se u ovoj simulaciji provjerava pri potvrdi, bez rezervacije.@endif</p>
        @else<a class="button" href="{{ route('store.home') }}#kolekcija">Istraži kolekciju ↗</a>@endif
        <p class="price-note">Ovo nije potvrda stvarne naplate niti fiskalni račun.</p>
    </div>
@endsection

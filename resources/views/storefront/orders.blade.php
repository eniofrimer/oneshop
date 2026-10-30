@extends('storefront.layout')
@section('title', 'Moje testne narudžbe — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.home') }}#kolekcija">← Natrag na kolekciju</a>
    <div class="page-heading"><p class="eyebrow">TVOJE KUPOVINE NA JEDNOM MJESTU</p><h1>Moje narudžbe<span>.</span></h1><p>Vrati se na potvrdu ili nastavi nedovršenu testnu kupnju.</p></div>
    @if($orders->isEmpty())
        <div class="empty-state order-empty"><span class="empty-symbol" aria-hidden="true">↗</span><h2>Prvi favorit tek te čeka.</h2><p>Ovdje ćeš vidjeti testne narudžbe predane u ovom pregledniku.</p><a class="button" href="{{ route('store.home') }}#kolekcija">Istraži kolekciju ↗</a></div>
    @else
        <div class="guest-orders">
            @foreach($orders as $order)
                <article class="guest-order">
                    <div><p class="eyebrow">{{ $order['mode'] }}</p><h2>Narudžba {{ $order['reference'] }}</h2><p class="muted">{{ $order['placed_at'] }} · {{ \App\Support\ShopTime::zone() }}</p></div>
                    <span class="order-state order-state-{{ in_array($order['state'], ['paid', 'pending', 'declined', 'cancelled', 'expired', 'prepared', 'unknown', 'review'], true) ? $order['state'] : 'review' }}">{{ $order['status'] }}</span>
                    <strong class="guest-order-total">{{ $order['amount'] }}</strong>
                    <a class="secondary-button" href="{{ $order['url'] }}">{{ $order['state'] === 'paid' ? 'Otvori potvrdu' : 'Pogledaj narudžbu' }} <span class="sr-only">{{ $order['reference'] }}</span><span aria-hidden="true">↗</span></a>
                </article>
            @endforeach
        </div>
    @endif
    <div class="guest-orders-note"><p>Ovo su ogledne narudžbe bez stvarne naplate i dostave. Prikaz je vezan uz ovu sesiju preglednika; brisanjem kolačića ili promjenom preglednika nestaje pristup ovoj listi.</p><p>Za aktualno stanje otvori pojedinu narudžbu. Nova kupnja ne mijenja već predanu narudžbu.</p></div>
@endsection

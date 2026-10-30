@extends('storefront.layout')
@section('title', 'Tvoja košarica — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.home') }}#kolekcija">← Nastavi istraživati</a>
    <div class="page-heading"><p class="eyebrow">JOŠ MALO DO DOBRIH STVARI</p><h1>Tvoja košarica<span>.</span></h1></div>
    @if($warning)<div class="notice notice-error" role="alert">{{ $warning }}</div>@endif
    @if($lines === [])
        <div class="empty-state"><h2>Ovdje još nema favorita.</h2><p>Odaberi nešto za svoju svakodnevicu.</p><a class="button" href="{{ route('store.home') }}#kolekcija">Pogledaj kolekciju ↗</a></div>
    @else
        <div class="cart-layout"><div class="cart-lines">@foreach($lines as $line)
            @php($lineLabel = $line['name'].' — '.($line['option'] !== '' ? $line['option'] : $line['sku']))
            <article class="cart-line" aria-labelledby="cart-name-{{ $line['id'] }}{{ $line['option'] !== '' ? ' cart-option-'.$line['id'] : '' }}">
                <div class="cart-product">
                    @if($line['image'])<img class="cart-thumbnail" src="{{ $line['image'] }}" alt="" width="64" height="64" loading="lazy" decoding="async">@endif
                    <div class="cart-product-copy">
                        <h2 id="cart-name-{{ $line['id'] }}">@if($line['url'])<a href="{{ $line['url'] }}">{{ $line['name'] }}</a>@else{{ $line['name'] }}@endif</h2>
                        @if($line['option'] !== '')<p class="cart-option" id="cart-option-{{ $line['id'] }}">{{ $line['option'] }}</p>@endif
                        <p class="sku">{{ $line['sku'] }}</p>
                    </div>
                </div>
                <div class="line-controls">
                    <form method="post" action="{{ route('store.cart.update', $line['id']) }}">
                        @csrf @method('PATCH')
                        <label class="sr-only" for="qty-{{ $line['id'] }}">Količina za {{ $lineLabel }}</label>
                        <input id="qty-{{ $line['id'] }}" type="number" name="quantity" value="{{ $line['quantity'] }}" min="1" max="99" required>
                        <button class="text-button" aria-label="Ažuriraj količinu za {{ $lineLabel }}">Ažuriraj</button>
                    </form>
                    <form method="post" action="{{ route('store.cart.remove', $line['id']) }}">
                        @csrf @method('DELETE')<button class="text-button muted" aria-label="Ukloni {{ $lineLabel }} iz košarice">Ukloni</button>
                    </form>
                </div>
                <strong>{{ $line['total'] === null ? '—' : \App\Storefront\StoreCatalog::money($line['total']) }}</strong>
            </article>
        @endforeach</div><aside class="order-summary"><h2>Pregled košarice</h2>@if(!$warning)<dl><div><dt>Artikli s porezom</dt><dd>{{ \App\Storefront\StoreCatalog::money(($cart->total?->value ?? 0) - ($cart->shippingTotal?->value ?? 0)) }}</dd></div><div><dt>Dostava</dt><dd>U sljedećem koraku</dd></div></dl>@endif<p>Prije predaje narudžbe provjeri adresu, dostavu i konačan iznos.</p>@if(\App\Checkout\DevelopmentCheckoutService::enabled() && !$warning)<a class="button" href="{{ route('store.checkout') }}">Na završetak kupnje ↗</a>@elseif(!\App\Payments\StripeSandboxConfiguration::available())<p class="price-note">Završetak testne kupnje je u pripremi.</p>@endif@if(\App\Payments\StripeSandboxConfiguration::available() && !$warning)<a class="secondary-button" href="{{ route('store.stripe') }}">Stripe sandbox checkout ↗</a>@endif</aside></div>
    @endif
@endsection

@extends('storefront.layout')
@section('title', $item['name'].' — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.home') }}#kolekcija">← Natrag na kolekciju</a>
    <div class="product-detail">
        <div class="product-art detail-art">@if($item['image'])<img src="{{ $item['image'] }}" alt="{{ $item['name'] }}">@else<span class="no-image">ONE</span>@endif</div>
        <section class="product-copy"><p class="eyebrow">ONE / KOLEKCIJA</p><h1>{{ $item['name'] }}</h1>
            @if($item['variants']->isNotEmpty())<p class="detail-price">{{ $item['variants']->pluck('price')->unique()->count() > 1 ? 'od ' : '' }}{{ \App\Storefront\StoreCatalog::money($item['variants']->min('price')) }}</p>@endif
            <p class="product-description">{{ $item['description'] }}</p>
            <form method="post" action="{{ route('store.cart.add') }}" class="product-form">@csrf
                <label for="variant">{{ $item['variants']->count() > 1 ? 'Odaberi varijantu' : 'Proizvod' }}</label>
                <select name="variant_id" id="variant" required>
                    @if($item['variants']->count() > 1)<option value="">Odaberi boju i veličinu</option>@endif
                    @foreach($item['variants'] as $variant)<option value="{{ $variant['id'] }}" @disabled(!$variant['available']) @selected((string)old('variant_id') === (string)$variant['id'])>{{ $variant['label'] }} · {{ \App\Storefront\StoreCatalog::money($variant['price']) }}{{ !$variant['available'] ? ' · Rasprodano' : '' }}</option>@endforeach
                </select>
                <label for="quantity">Količina</label><input id="quantity" name="quantity" type="number" value="{{ old('quantity', 1) }}" min="1" max="99" required>
                <button class="button" @disabled(!$item['variants']->contains('available', true))>Dodaj u košaricu <span aria-hidden="true">+</span></button>
            </form>
            <p class="price-note">Cijene uključuju porez prema zadanoj zoni. Dostava se obračunava pri završetku kupnje.</p>
        </section>
    </div>
@endsection

@extends('storefront.layout')
@section('title', $item['name'].' — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.home') }}#kolekcija">← Natrag na kolekciju</a>
    <div class="product-detail">
        <div class="product-gallery" data-product-gallery><div class="product-art detail-art">@if($item['image'])<img src="{{ $gallery[0]['url'] ?? $item['image'] }}" width="800" height="800" decoding="async" fetchpriority="high" alt="{{ $gallery[0]['alt'] ?? $item['name'] }}" data-gallery-main>@else<span class="no-image">ONE</span>@endif</div>
            @if(count($gallery) > 1)<div class="gallery-thumbnails" aria-label="Fotografije proizvoda">@foreach($gallery as $image)<a href="{{ $image['url'] }}" data-gallery-thumbnail data-alt="{{ $image['alt'] }}" aria-label="Prikaži fotografiju {{ $loop->iteration }}"><img src="{{ $image['thumbnail'] }}" alt="{{ $image['alt'] }}" width="75" height="75" loading="lazy" decoding="async"></a>@endforeach</div>@endif
        </div>
        <section class="product-copy"><p class="eyebrow">ONE / KOLEKCIJA</p><h1>{{ $item['name'] }}</h1>
            @if($item['variants']->isNotEmpty())<p class="detail-price" data-selected-price>{{ $item['variants']->pluck('price')->unique()->count() > 1 ? 'od ' : '' }}{{ \App\Storefront\StoreCatalog::money($item['variants']->min('price')) }}</p>@endif
            <p class="product-description">{{ $item['description'] }}</p>
            <form method="post" action="{{ route('store.cart.add') }}" class="product-form" data-variant-picker>@csrf
                <script type="application/json" data-variant-data>@json($selection)</script>
                @if($selection['options'] !== [])<div class="option-controls" data-option-controls hidden>
                    @foreach($selection['options'] as $option)
                        @if($option['display'] === 'radio')
                            <fieldset><legend>{{ $option['name'] }}</legend><div class="option-radios">@foreach($option['values'] as $value)<label><input type="radio" name="option_{{ $option['id'] }}" value="{{ $value['id'] }}" data-option-id="{{ $option['id'] }}"><span>{{ $value['name'] }}</span></label>@endforeach</div></fieldset>
                        @else
                            <label for="option-{{ $option['id'] }}">{{ $option['name'] }}</label><select id="option-{{ $option['id'] }}" data-option-id="{{ $option['id'] }}"><option value="">Odaberi {{ mb_strtolower($option['name']) }}</option>@foreach($option['values'] as $value)<option value="{{ $value['id'] }}">{{ $value['name'] }}</option>@endforeach</select>
                        @endif
                    @endforeach
                    <p class="selection-status" data-selection-status role="status" aria-live="polite">Odaberi sve opcije za prikaz cijene i dostupnosti.</p><button type="button" class="text-button" data-selection-reset>Poništi odabir</button>
                </div>@endif
                <div data-variant-fallback><label for="variant">{{ $item['variants']->count() > 1 ? 'Odaberi varijantu' : 'Proizvod' }}</label>
                <select name="variant_id" id="variant" required>
                    @if($item['variants']->count() > 1)<option value="">Odaberi kombinaciju</option>@endif
                    @foreach($item['variants'] as $variant)<option value="{{ $variant['id'] }}" @disabled(!$variant['available']) @selected((string)old('variant_id') === (string)$variant['id'])>{{ $variant['label'] }} · {{ \App\Storefront\StoreCatalog::money($variant['price']) }}{{ !$variant['available'] ? ' · Rasprodano' : '' }}</option>@endforeach
                </select></div>
                <label for="quantity">Količina</label><input id="quantity" name="quantity" type="number" value="{{ old('quantity', 1) }}" min="1" max="99" required>
                <button class="button" data-add-button @disabled(!$item['variants']->contains('available', true))>Dodaj u košaricu <span aria-hidden="true">+</span></button>
            </form>
            <p class="price-note">Cijene uključuju porez prema zadanoj zoni. Dostava se obračunava pri završetku kupnje.</p>
        </section>
    </div>
@endsection

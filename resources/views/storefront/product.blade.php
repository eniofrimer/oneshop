@extends('storefront.layout')
@section('title', $item['name'].' — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.home') }}#kolekcija">← Natrag na kolekciju</a>
    <div class="product-detail">
        @php($selectedVariantId = \App\Storefront\FormValues::old('variant_id', $item['variants']->count() === 1 ? $item['variants']->first()['id'] : ''))
        @php($selectedPhoto = collect($selection['variants'])->first(fn ($variant) => (string) $variant['id'] === $selectedVariantId)['photo'] ?? null)
        @php($mainImage = $selectedPhoto ?? $gallery[0] ?? null)
        @php($imageSources = $mainImage ? $mainImage['srcset'] : $item['image_srcset'])
        <div class="product-gallery" data-product-gallery>
            <div class="product-art detail-art">
                @if($item['image'])
                    <img src="{{ $mainImage['preview'] ?? $item['image'] }}" @if($imageSources) srcset="{{ $imageSources }}" @endif sizes="(max-width: 540px) calc(100vw - 40px), (max-width: 800px) 50vw, 620px" width="800" height="800" decoding="async" fetchpriority="high" alt="{{ $mainImage['alt'] ?? $item['image_alt'] }}" data-gallery-main>
                @else
                    <span class="no-image">ONE</span>
                @endif
            </div>
            @if(count($gallery) > 1)
                <div class="gallery-thumbnails" aria-label="Fotografije proizvoda">
                    @foreach($gallery as $image)
                        <a href="{{ $image['url'] }}" data-gallery-thumbnail data-preview="{{ $image['preview'] }}" data-srcset="{{ $image['srcset'] ?? '' }}" data-alt="{{ $image['alt'] }}" aria-label="Prikaži fotografiju {{ $loop->iteration }}" @if($image['url'] === ($mainImage['url'] ?? null)) aria-current="true" @endif><img src="{{ $image['thumbnail'] }}" alt="{{ $image['alt'] }}" width="75" height="75" loading="lazy" decoding="async"></a>
                    @endforeach
                </div>
            @endif
            @if($mainImage)
                <a class="gallery-original text-link" href="{{ $mainImage['url'] }}" data-gallery-original target="_blank" rel="noopener noreferrer">Otvori fotografiju u punoj veličini ↗<span class="sr-only"> (nova kartica)</span></a>
            @endif
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
                    @foreach($item['variants'] as $variant)<option value="{{ $variant['id'] }}" @disabled(!$variant['available']) @selected($selectedVariantId === (string)$variant['id'])>{{ $variant['label'] }} · {{ \App\Storefront\StoreCatalog::money($variant['price']) }}{{ !$variant['available'] ? ' · Rasprodano' : '' }}</option>@endforeach
                </select></div>
                <label for="quantity">Količina</label><input id="quantity" name="quantity" type="number" value="{{ \App\Storefront\FormValues::old('quantity', 1) }}" min="1" max="99" required @if($errors->has('quantity')) aria-invalid="true" aria-describedby="quantity-error" @endif><x-storefront.error name="quantity" />
                <button class="button" data-add-button @disabled(!$item['variants']->contains('available', true))>Dodaj u košaricu <span aria-hidden="true">+</span></button>
            </form>
            <p class="price-note">Cijene uključuju porez prema zadanoj zoni. Dostava se obračunava pri završetku kupnje.</p>
        </section>
    </div>
@endsection

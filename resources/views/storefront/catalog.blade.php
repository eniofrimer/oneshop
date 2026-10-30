@extends('storefront.layout')
@section('content')
    <section class="store-hero">
        <div class="hero-copy"><p class="eyebrow">JEDNOSTAVNO. SVAKI DAN.</p><h1>Dobre stvari.<br><em>Bez viška.</em></h1><p>Od prve jutarnje kave do posljednjeg plana u danu. Otkrij male favorite koje ćeš rado koristiti.</p><a class="button" href="#kolekcija">Istraži kolekciju <span aria-hidden="true">↗</span></a></div>
        <div class="hero-art"><img src="{{ asset('images/demo-v2/hero-800.webp') }}" srcset="{{ asset('images/demo-v2/hero-480.webp') }} 480w, {{ asset('images/demo-v2/hero-800.webp') }} 800w, {{ asset('images/demo-v2/hero-1200.webp') }} 1200w" sizes="(max-width: 540px) 100vw, 50vw" width="1200" height="1200" fetchpriority="high" alt="Ogledna fotografija keramičke šalice i platnene torbe"></div>
    </section>
    <section id="kolekcija" class="collection-section">
        <form class="catalog-search" method="get" action="{{ route('store.home') }}#kolekcija" data-live-search @if($errors->has('q') || $errors->has('available') || $errors->has('page')) data-catalog-invalid @endif>
            <div class="search-field"><label for="catalog-q">Pronađi svoj sljedeći favorit</label><div><input type="search" id="catalog-q" name="q" value="{{ $filters['q'] }}" placeholder="Naziv proizvoda ili SKU" minlength="2" maxlength="60" autocomplete="off" @if($errors->has('q')) aria-invalid="true" aria-describedby="catalog-q-error" @endif><button class="button">Pretraži ↗</button></div>@error('q')<p class="field-error" id="catalog-q-error">{{ $message }}</p>@enderror</div>
            <label class="available-toggle"><input type="checkbox" name="available" value="1" @checked($filters['available'])>Samo dostupni artikli</label>
            <a class="text-link" href="{{ route('store.home') }}#kolekcija">Očisti filtre</a>
            <p class="search-status" data-search-status role="status" aria-live="polite">Za pretragu unesi barem dva znaka.</p>
        </form>
        <div data-catalog-results>@include('storefront.catalog-results')</div>
    </section>
    <section class="store-values"><div><span>01</span><h3>Odabrano s razlogom</h3><p>Jednostavni oblici koje je lako zavoljeti.</p></div><div><span>02</span><h3>Sve na jednom mjestu</h3><p>Varijante, dostupnost i cijene prije kupnje.</p></div><div><span>03</span><h3>Kupnja bez žurbe</h3><p>Provjeri svoju košaricu prije sljedećeg koraka.</p></div></section>
@endsection

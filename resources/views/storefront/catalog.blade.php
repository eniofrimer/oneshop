@extends('storefront.layout')
@section('content')
    <section class="store-hero">
        <div class="hero-copy"><p class="eyebrow">JEDNOSTAVNO. SVAKI DAN.</p><h1>Dobre stvari.<br><em>Bez viška.</em></h1><p>Od prve jutarnje kave do posljednjeg plana u danu. Otkrij male favorite koje ćeš rado koristiti.</p><a class="button" href="#kolekcija">Istraži kolekciju <span aria-hidden="true">↗</span></a></div>
        <div class="hero-art"><span class="art-label">ONE / SVAKODNEVNO</span><img src="{{ asset('images/demo/still-life.svg') }}" alt="Ilustracija šalice i torbe iz demo kolekcije"><span class="art-caption">Pomno odabrano. Lako kombinirano.</span></div>
    </section>
    <section id="kolekcija" class="collection-section">
        <div class="section-heading"><div><p class="eyebrow">MALA KOLEKCIJA, DOBAR POČETAK</p><h2>Za tvoju svakodnevicu</h2></div><span>{{ $products->total() }} proizvoda</span></div>
        <div class="product-grid">
            @forelse($cards as $item)
                <article class="product-card">
                    <a class="product-art" href="{{ route('store.product', $item['id']) }}">@if($item['image'])<img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" loading="lazy">@else<span class="no-image">ONE</span>@endif<span class="product-arrow" aria-hidden="true">↗</span></a>
                    <div class="product-meta"><h3><a href="{{ route('store.product', $item['id']) }}">{{ $item['name'] }}</a></h3><span>@if($item['variants']->isNotEmpty()){{ $item['variants']->pluck('price')->unique()->count() > 1 ? 'od ' : '' }}{{ \App\Storefront\StoreCatalog::money($item['variants']->min('price')) }}@else Trenutačno nedostupno @endif</span></div>
                    <p>{{ $item['variants']->count() > 1 ? $item['variants']->count().' varijante · odaberi svoju' : 'Mali favorit za svaki dan' }}</p>
                </article>
            @empty
                <div class="empty-state"><h3>Kolekcija stiže uskoro.</h3><p>Ovdje će se pojaviti objavljeni proizvodi iz administracije.</p></div>
            @endforelse
        </div>
        <div class="pagination">{{ $products->links() }}</div>
        <p class="price-note">Prikazane cijene uključuju porez prema zadanoj zoni trgovine. Dostava i konačan iznos prikazuju se prije predaje narudžbe.</p>
    </section>
    <section class="store-values"><div><span>01</span><h3>Odabrano s razlogom</h3><p>Jednostavni oblici koje je lako zavoljeti.</p></div><div><span>02</span><h3>Sve na jednom mjestu</h3><p>Varijante, dostupnost i cijene prije kupnje.</p></div><div><span>03</span><h3>Kupnja bez žurbe</h3><p>Provjeri svoju košaricu prije sljedećeg koraka.</p></div></section>
@endsection

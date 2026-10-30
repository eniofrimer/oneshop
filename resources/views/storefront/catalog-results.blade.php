        <div class="section-heading"><div><p class="eyebrow">MALA KOLEKCIJA, DOBAR POČETAK</p><h2 tabindex="-1">{{ $filters['q'] !== '' || $filters['available'] ? 'Tvoji rezultati' : 'Za tvoju svakodnevicu' }}</h2></div><span>{{ $products->total() }} proizvoda</span></div>
        <div class="product-grid">
            @forelse($cards as $item)
                <article class="product-card">
                    <a class="product-art" href="{{ route('store.product', $item['id']) }}">@if($item['image'])<img src="{{ $item['image'] }}" @if($item['image_srcset'])srcset="{{ $item['image_srcset'] }}" sizes="(max-width: 540px) 46vw, {{ \App\Storefront\StoreTheme::configured() === \App\Storefront\StoreTheme::Atelier ? '50vw' : '33vw' }}"@endif alt="{{ $item['image_alt'] }}" width="500" height="500" loading="lazy" decoding="async">@else<span class="no-image">ONE</span>@endif<span class="product-arrow" aria-hidden="true">↗</span></a>
                    <div class="product-meta"><h3><a href="{{ route('store.product', $item['id']) }}">{{ $item['name'] }}</a></h3><span>@if($item['variants']->isNotEmpty()){{ $item['variants']->pluck('price')->unique()->count() > 1 ? 'od ' : '' }}{{ \App\Storefront\StoreCatalog::money($item['variants']->min('price')) }}@else Trenutačno nedostupno @endif</span></div>
                    <p>{{ !$item['variants']->contains('available', true) ? 'Trenutačno nedostupno' : ($item['variants']->count() > 1 ? $item['variants']->count().' varijante · odaberi svoju' : 'Dostupno za testnu kupnju') }}</p>
                </article>
            @empty
                @if($errors->has('q') || $errors->has('available') || $errors->has('page'))
                    <div class="empty-state"><h3>Provjeri filtre pretrage.</h3><p>Ispravi označeni unos ili očisti filtre iznad za novi pregled kolekcije.</p></div>
                @elseif($products->total() > 0 && $products->currentPage() > 1)
                    <div class="empty-state"><h3>Ova stranica kolekcije je prazna.</h3><p>Proizvodi su dostupni na prethodnim stranicama. Tvoji filtri ostaju odabrani.</p><a class="text-link" href="{{ $products->url(1) }}" data-catalog-page>Vrati se na prvu stranicu</a></div>
                @else
                    <div class="empty-state"><h3>{{ $filters['q'] !== '' || $filters['available'] ? 'Još nema podudaranja.' : 'Kolekcija stiže uskoro.' }}</h3><p>{{ $filters['q'] !== '' || $filters['available'] ? 'Pokušaj s drugim nazivom ili isključi filter dostupnosti.' : 'Ovdje će se pojaviti objavljeni proizvodi iz administracije.' }}</p></div>
                @endif
            @endforelse
        </div>
        <div class="pagination">{{ $products->onEachSide(1)->links('storefront.pagination') }}</div>
        <p class="price-note">Prikazane cijene uključuju porez prema zadanoj zoni trgovine. Dostava i konačan iznos prikazuju se prije predaje narudžbe.</p>

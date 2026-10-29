<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'OneShop — stvari za svaki dan')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="shop theme-{{ \App\Storefront\StoreTheme::configured()->value }}">
    <a class="skip-link" href="#main-content">Preskoči na sadržaj</a>
    <div class="preview-note">Razvojna instanca · Demo ponuda i testna kupnja</div>
    <header class="shop-header wrap">
        <a class="wordmark" href="{{ route('store.home') }}" aria-label="OneShop početna">one<span>shop</span><i aria-hidden="true"></i></a>
        <nav aria-label="Glavna navigacija">
            <a href="{{ route('store.home') }}#kolekcija">Kolekcija</a>
            <a class="cart-link" href="{{ route('store.cart') }}"><svg width="19" height="21" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 7h14l1 14H4L5 7ZM8 8V6a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.5"/></svg>Košarica</a>
        </nav>
    </header>
    <main class="wrap" id="main-content" tabindex="-1">
        @if(session('message'))<div class="notice" role="status">{{ session('message') }}</div>@endif
        @if($errors->any())<div class="notice notice-error" role="alert"><strong>Provjerite unos</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
    <footer class="shop-footer wrap">
        <a class="wordmark" href="{{ route('store.home') }}">one<span>shop</span><i aria-hidden="true"></i></a>
        <p>Manje stvari. Više svakodnevice.</p>
        <a href="{{ route('filament.admin.auth.login') }}">Administracija ↗</a>
        <small>Demo trgovina za provjeru kupnje. Ponuda, porezi i dostava su testni.</small>
    </footer>
</body>
</html>

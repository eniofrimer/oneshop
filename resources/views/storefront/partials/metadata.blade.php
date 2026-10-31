@if(isset($seo))
    <meta name="description" content="{{ $seo['description'] }}">
    @if($seo['canonical'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="OneShop">
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta property="og:url" content="{{ $seo['canonical'] }}">
        @if($seo['image'])
            <meta property="og:image" content="{{ $seo['image'] }}">
            <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
        @endif
    @endif
@endif

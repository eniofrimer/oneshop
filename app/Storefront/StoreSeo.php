<?php

namespace App\Storefront;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lunar\Models\Product;

class StoreSeo
{
    public function origin(): ?string
    {
        $url = config('app.url');
        if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return rtrim($url, '/');
    }

    public function indexingEnabled(): bool
    {
        return app()->environment('production') && ! config('app.debug')
            && config('oneshop.allow_indexing') === true
            && str_starts_with($this->origin() ?? '', 'https://');
    }

    /** @param array<string, int> $parameters */
    public function url(string $route, array $parameters = []): ?string
    {
        $origin = $this->origin();

        return $origin === null ? null : $origin.route($route, $parameters, false);
    }

    /**
     * @param  LengthAwarePaginator<int, Product>  $products
     * @param  array{q: string, available: bool}  $filters
     * @return array{title: string, description: string, canonical: ?string, image: ?string, image_alt: string, robots: string}
     */
    public function catalog(LengthAwarePaginator $products, array $filters): array
    {
        $filtered = $filters['q'] !== '' || $filters['available'];
        $page = $products->currentPage();
        $url = $filtered ? null : $this->url('store.home', $page > 1 ? ['page' => $page] : []);

        return ['title' => ($page > 1 ? 'Kolekcija — stranica '.$page : 'OneShop — stvari za svaki dan'),
            'description' => 'Istraži kolekciju, usporedi varijante i provjeri dostupnost i cijene prije kupnje.',
            'canonical' => $url, 'image' => $this->origin() ? $this->origin().'/images/demo-v2/hero-1200.webp' : null,
            'image_alt' => 'Ogledna fotografija keramičke šalice i platnene torbe',
            'robots' => ! $this->indexingEnabled() ? 'noindex,nofollow' : ($filtered || $products->isEmpty() ? 'noindex,follow' : 'index,follow')];
    }

    /**
     * @param  array{id: int, name: string, description: string, image: ?string, image_alt: string}  $item
     * @return array{title: string, description: string, canonical: ?string, image: ?string, image_alt: string, robots: string}
     */
    public function product(array $item, Request $request): array
    {
        $image = $item['image'];
        if ($image !== null && str_starts_with($image, $request->getSchemeAndHttpHost().'/')) {
            $image = $this->origin() === null ? null : $this->origin().substr($image, strlen($request->getSchemeAndHttpHost()));
        }

        return ['title' => $item['name'].' — OneShop',
            'description' => $this->description($item['description'] !== '' ? $item['description'] : $item['name']),
            'canonical' => $this->url('store.product', ['productId' => $item['id']]),
            'image' => is_string($image) && filter_var($image, FILTER_VALIDATE_URL) && in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true) ? $image : null,
            'image_alt' => $item['image_alt'], 'robots' => $this->indexingEnabled() ? 'index,follow' : 'noindex,nofollow'];
    }

    public function description(string $text): string
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(preg_replace('/[\s\p{C}]+/u', ' ', $plain) ?? '');

        return mb_strlen($plain) > 180 ? mb_substr($plain, 0, 179).'…' : $plain;
    }
}

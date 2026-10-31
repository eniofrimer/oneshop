export function thumbnailPhoto(link) {
    return { url: link.href, preview: link.dataset.preview || link.href, srcset: link.dataset.srcset || null, alt: link.dataset.alt ?? '' };
}

export function defaultProductPhoto(gallery) {
    const first = gallery.querySelector('[data-gallery-thumbnail]');
    if (first) return thumbnailPhoto(first);
    const main = gallery.querySelector('[data-gallery-main]');
    if (!main) return null;
    return { url: gallery.querySelector('[data-gallery-original]')?.href || main.src,
        preview: main.src, srcset: main.getAttribute('srcset'), alt: main.alt };
}

export function showProductPhoto(gallery, photo) {
    const main = gallery.querySelector('[data-gallery-main]');
    if (!main || !photo || typeof photo.preview !== 'string' || typeof photo.url !== 'string') return;
    if (main.getAttribute('src') !== photo.preview || main.getAttribute('srcset') !== (photo.srcset || null)) {
        // Clear previous candidates before changing source; a fallback has no srcset.
        main.removeAttribute('srcset');
        main.src = photo.preview;
        if (photo.srcset) main.srcset = photo.srcset;
    }
    main.alt = photo.alt ?? '';
    const original = gallery.querySelector('[data-gallery-original]');
    if (original) original.href = photo.url;
    gallery.querySelectorAll('[data-gallery-thumbnail]').forEach(link => {
        if (link.href === new URL(photo.url, document.baseURI).href) link.setAttribute('aria-current', 'true');
        else link.removeAttribute('aria-current');
    });
}

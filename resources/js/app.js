import { mountVariantPicker } from './variant-selection.js';

document.querySelectorAll('[data-variant-picker]').forEach(mountVariantPicker);
document.querySelectorAll('[data-product-gallery]').forEach(gallery => {
    const main = gallery.querySelector('[data-gallery-main]');
    if (!main) return;
    gallery.querySelectorAll('[data-gallery-thumbnail]').forEach(thumbnail => {
        thumbnail.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            main.src = thumbnail.href;
            main.alt = thumbnail.dataset.alt ?? '';
            gallery.querySelectorAll('[aria-current]').forEach(item => item.removeAttribute('aria-current'));
            thumbnail.setAttribute('aria-current', 'true');
        });
    });
});

import { mountLiveSearch } from './live-search.js';
document.querySelectorAll('[data-live-search]').forEach(mountLiveSearch);

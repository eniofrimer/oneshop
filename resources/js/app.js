import { mountVariantPicker } from './variant-selection.js';
import { showProductPhoto, thumbnailPhoto } from './product-gallery.js';

document.querySelectorAll('[data-variant-picker]').forEach(mountVariantPicker);
document.querySelectorAll('[data-product-gallery]').forEach(gallery => {
    const main = gallery.querySelector('[data-gallery-main]');
    if (!main) return;
    gallery.querySelectorAll('[data-gallery-thumbnail]').forEach(thumbnail => {
        thumbnail.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            showProductPhoto(gallery, thumbnailPhoto(thumbnail));
        });
    });
});

import { mountLiveSearch } from './live-search.js';
document.querySelectorAll('[data-live-search]').forEach(mountLiveSearch);

document.querySelector('[data-validation-summary]')?.focus();

import { mountVariantPicker } from './variant-selection.js';

document.querySelectorAll('[data-variant-picker]').forEach(mountVariantPicker);
document.querySelectorAll('[data-product-gallery]').forEach(gallery => {
    const main = gallery.querySelector('[data-gallery-main]');
    const original = gallery.querySelector('[data-gallery-original]');
    if (!main) return;
    gallery.querySelectorAll('[data-gallery-thumbnail]').forEach(thumbnail => {
        thumbnail.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            // Remove the previous image's candidates before changing the source.
            // Pending/public-only fallbacks intentionally have no srcset.
            main.removeAttribute('srcset');
            main.src = thumbnail.dataset.preview || thumbnail.href;
            if (thumbnail.dataset.srcset) main.srcset = thumbnail.dataset.srcset;
            main.alt = thumbnail.dataset.alt ?? '';
            if (original) original.href = thumbnail.href;
            gallery.querySelectorAll('[aria-current]').forEach(item => item.removeAttribute('aria-current'));
            thumbnail.setAttribute('aria-current', 'true');
        });
    });
});

import { mountLiveSearch } from './live-search.js';
document.querySelectorAll('[data-live-search]').forEach(mountLiveSearch);

document.querySelector('[data-validation-summary]')?.focus();

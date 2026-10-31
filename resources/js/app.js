import { mountVariantPicker } from './variant-selection.js';
import { showProductPhoto, thumbnailPhoto } from './product-gallery.js';
import { mountPhotoDialog } from './photo-dialog.js';

document.querySelectorAll('[data-variant-picker]').forEach(mountVariantPicker);
document.querySelectorAll('[data-product-gallery]').forEach(gallery => {
    mountPhotoDialog(gallery);
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

const validationSummary = document.querySelector('[data-validation-summary]');
validationSummary?.addEventListener('click', event => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('[data-validation-link]');
    const target = link && document.getElementById(link.hash.slice(1));
    if (!target) return;
    const candidates = target.matches('input, select, textarea, button') ? [target]
        : [...target.querySelectorAll('input:not([type="hidden"]), select, textarea, button'), target];
    const control = candidates.find(element => !element.disabled && element.getClientRects().length > 0);
    control?.focus();
    if (control && document.activeElement === control) event.preventDefault();
});
validationSummary?.focus();

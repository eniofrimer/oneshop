import { mountVariantPicker } from './variant-selection.js';

document.querySelectorAll('[data-variant-picker]').forEach(mountVariantPicker);
document.querySelectorAll('[data-product-gallery]').forEach(gallery => {
    const main = gallery.querySelector('[data-gallery-main]');
    if (!main) return;
    gallery.querySelectorAll('[data-gallery-thumbnail]').forEach(thumbnail => {
        thumbnail.addEventListener('click', event => {
            event.preventDefault();
            main.src = thumbnail.href;
            main.alt = thumbnail.dataset.alt ?? '';
            gallery.querySelectorAll('[aria-current]').forEach(item => item.removeAttribute('aria-current'));
            thumbnail.setAttribute('aria-current', 'true');
        });
    });
});

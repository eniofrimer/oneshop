export function mountPhotoDialog(gallery) {
    const trigger = gallery.querySelector('[data-gallery-zoom]');
    const dialog = gallery.querySelector('[data-photo-dialog]');
    const main = gallery.querySelector('[data-gallery-main]');
    const image = dialog?.querySelector('[data-photo-full]');
    const close = dialog?.querySelector('[data-photo-close]');
    const original = dialog?.querySelector('[data-photo-open]');
    const status = dialog?.querySelector('[data-photo-status]');
    if (!trigger || !main || !image || !close || !original || !status || typeof dialog.showModal !== 'function') return;

    trigger.hidden = false;
    trigger.addEventListener('click', () => {
        const url = gallery.querySelector('[data-gallery-original]')?.href || main.currentSrc || main.src;
        image.alt = main.alt;
        image.hidden = false;
        status.textContent = 'Učitavanje fotografije…';
        status.hidden = false;
        original.href = url;
        image.src = url;
        // Mouse activation does not focus buttons consistently across browsers.
        // Give the native dialog the actual invoker to restore synchronously.
        trigger.focus();
        dialog.showModal();
    });
    image.addEventListener('load', () => { status.hidden = true; });
    image.addEventListener('error', () => {
        image.hidden = true;
        status.textContent = 'Fotografija se trenutačno ne može učitati. Pokušaj je otvoriti u novoj kartici.';
        status.hidden = false;
    });
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        // Some browsers include their chrome at the edge of a native dialog's
        // tab sequence. Keep this two-control viewer contained in the document.
        if (event.shiftKey && document.activeElement === close) {
            event.preventDefault();
            original.focus();
        } else if (!event.shiftKey && document.activeElement === original) {
            event.preventDefault();
            close.focus();
        }
    });
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
        // close is queued: a fast reopen can happen before this event is delivered.
        if (dialog.open) return;
        image.removeAttribute('src');
    });
}

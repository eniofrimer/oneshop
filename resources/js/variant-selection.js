// A complete selection must resolve to exactly one SKU. Prices displayed here
// are informational: cart and checkout always resolve their own server prices.
export function resolveVariant(options, variants, selected) {
    if (!Array.isArray(options) || !Array.isArray(variants) || options.length === 0) {
        return { state: 'incomplete', variant: null };
    }
    if (!options.every(option => Number.isSafeInteger(selected[option.id]) && option.values.some(value => value.id === selected[option.id]))) {
        return { state: 'incomplete', variant: null };
    }
    const matches = variants.filter(variant => options.every(option => variant.values[option.id] === selected[option.id]));
    if (matches.length !== 1) return { state: 'unavailable', variant: null };
    return { state: matches[0].available ? 'available' : 'sold-out', variant: matches[0] };
}

export function mountVariantPicker(form) {
    const controls = form.querySelector('[data-option-controls]');
    const fallback = form.querySelector('[data-variant-fallback]');
    const select = fallback?.querySelector('select[name="variant_id"]');
    const button = form.querySelector('[data-add-button]');
    const status = form.querySelector('[data-selection-status]');
    const price = form.closest('.product-copy')?.querySelector('[data-selected-price]');
    let data;
    try { data = JSON.parse(form.querySelector('[data-variant-data]')?.textContent ?? 'null'); } catch { return; }
    if (!controls || !fallback || !select || !button || !status || !data?.options?.length || !Array.isArray(data.variants)) return;

    // Keep the native, usable SKU selector if enhancement cannot initialize.
    const originalPrice = price?.textContent ?? '';
    let selected = {};
    const initial = data.variants.find(variant => String(variant.id) === select.value);
    if (initial && data.options.length > 0) selected = { ...initial.values };
    const syncInputs = () => {
        controls.querySelectorAll('[data-option-id]').forEach(input => {
            const value = selected[Number(input.dataset.optionId)];
            if (input.type === 'radio') input.checked = Number(input.value) === value;
            else input.value = value ? String(value) : '';
        });
    };
    const update = () => {
        const result = resolveVariant(data.options, data.variants, selected);
        select.value = result.variant ? String(result.variant.id) : '';
        button.disabled = result.state !== 'available';
        if (price) price.textContent = result.variant ? new Intl.NumberFormat('hr-HR', { style: 'currency', currency: 'EUR' }).format(result.variant.price / 100) : originalPrice;
        status.textContent = {
            incomplete: 'Odaberi sve opcije za prikaz cijene i dostupnosti.',
            unavailable: 'Ova kombinacija trenutačno nije dostupna. Odaberi drugu kombinaciju.',
            'sold-out': 'Ova je kombinacija rasprodana.',
            available: 'Odabrana varijanta je dostupna.',
        }[result.state];
    };
    controls.addEventListener('change', event => {
        const input = event.target;
        if (!input.matches('[data-option-id]')) return;
        selected[Number(input.dataset.optionId)] = input.value ? Number(input.value) : null;
        update();
    });
    controls.querySelector('[data-selection-reset]')?.addEventListener('click', () => {
        selected = {};
        syncInputs();
        update();
    });
    // A hidden native selector must not block validation on an empty choice.
    // The enhancement disables submission until the complete SKU is resolved.
    select.required = false;
    syncInputs();
    update();
    controls.hidden = false;
    fallback.hidden = true;
}

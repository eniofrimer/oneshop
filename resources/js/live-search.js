// Apply only the latest result, even if an aborted transport still resolves.
export function createLatestRequest() {
    let sequence = 0;
    let controller;
    const run = async (load, apply) => {
        const current = ++sequence;
        controller?.abort();
        controller = new AbortController();
        try {
            const result = await load(controller.signal);
            if (current === sequence) apply(result);
        } catch (error) {
            if (current === sequence && error.name !== 'AbortError') throw error;
        }
    };
    return { run, cancel() { sequence++; controller?.abort(); } };
}

export function mountLiveSearch(form) {
    const input = form.querySelector('[name="q"]');
    const available = form.querySelector('[name="available"]');
    const results = document.querySelector('[data-catalog-results]');
    const status = form.querySelector('[data-search-status]');
    if (!input || !available || !results || !status || !window.fetch || !window.AbortController) return;
    const coordinator = createLatestRequest();
    let timer;
    const search = async (pageUrl = null) => {
        const q = input.value.trim();
        if ([...q].length === 1) {
            status.textContent = 'Za pretragu unesi barem dva znaka.';
            return;
        }
        const url = pageUrl ? new URL(pageUrl) : new URL(form.action);
        if (url.origin !== window.location.origin) return;
        if (!pageUrl) {
            url.search = '';
            if (q) url.searchParams.set('q', q);
            if (available.checked) url.searchParams.set('available', '1');
        }
        status.textContent = 'Pretražujem kolekciju…';
        try {
            await coordinator.run(async signal => {
                const response = await fetch(url, { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error(response.status === 429 ? 'Prečesto pretraživanje. Pričekaj kratko pa pokušaj ponovno.' : 'Pretraga trenutačno nije dostupna. Pokušaj ponovno.');
                return response.json();
            }, data => {
                results.innerHTML = data.html;
                status.textContent = `Pronađeno proizvoda: ${data.count}`;
                window.history.replaceState({}, '', url.pathname + url.search + '#kolekcija');
            });
        } catch (error) {
            status.textContent = error.message;
        }
    };
    input.addEventListener('input', () => { coordinator.cancel(); clearTimeout(timer); timer = setTimeout(() => search(), 350); });
    available.addEventListener('change', () => { coordinator.cancel(); clearTimeout(timer); search(); });
    form.addEventListener('submit', event => { event.preventDefault(); clearTimeout(timer); search(); });
    results.addEventListener('click', event => {
        const link = event.target.closest('.pagination a');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        search(link.href);
    });
}

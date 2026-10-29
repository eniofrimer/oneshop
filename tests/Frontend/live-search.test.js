import test from 'node:test';
import assert from 'node:assert/strict';
import { createLatestRequest, readCatalogResponse } from '../../resources/js/live-search.js';

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

test('late responses never replace results for a newer search', async () => {
    const coordinator = createLatestRequest();
    const first = deferred(), second = deferred(), applied = [];
    let firstSignal;
    const request1 = coordinator.run(signal => { firstSignal = signal; return first.promise; }, result => applied.push(result));
    const request2 = coordinator.run(() => second.promise, result => applied.push(result));
    assert.equal(firstSignal.aborted, true);
    second.resolve('new results');
    await request2;
    first.resolve('old results');
    await request1;
    assert.deepEqual(applied, ['new results']);
});

test('clearing or invalidating input cancels a pending result before the next request starts', async () => {
    const coordinator = createLatestRequest();
    const pending = deferred(), applied = [];
    const request = coordinator.run(() => pending.promise, result => applied.push(result));
    coordinator.cancel();
    pending.resolve('obsolete results');
    await request;
    assert.deepEqual(applied, []);
});

test('an obsolete failure is ignored while current failures remain visible', async () => {
    const coordinator = createLatestRequest();
    const old = deferred();
    const first = coordinator.run(() => old.promise, () => {});
    await coordinator.run(async () => 'good', () => {});
    old.reject(new Error('old failure'));
    await first;
    await assert.rejects(coordinator.run(async () => { throw new Error('current failure'); }, () => {}), /current failure/);
});

test('catalogue responses retain the rendered fragment and numeric result count', async () => {
    const payload = { html: '<p>Nema rezultata</p>', count: 0 };
    assert.deepEqual(await readCatalogResponse(new Response(JSON.stringify(payload))), payload);
});

test('catalogue validation shows the relevant field message and omits unrelated error details', async () => {
    await assert.rejects(readCatalogResponse(new Response(JSON.stringify({ errors: { q: ['Upiši barem dva znaka.'] } }), { status: 422 })), /Upiši barem dva znaka/);
    await assert.rejects(readCatalogResponse(new Response(JSON.stringify({ errors: { internal: ['PRIVATE_DETAIL'] } }), { status: 422 })), error => {
        assert.match(error.message, /Pretraga trenutačno nije dostupna/);
        assert.doesNotMatch(error.message, /PRIVATE_DETAIL/);
        return true;
    });
});

test('rate limiting gives a Croatian retry message even without a JSON body', async () => {
    await assert.rejects(readCatalogResponse(new Response('throttled', { status: 429 })), /Pričekaj kratko/);
});

test('unexpected server bodies and invalid result shapes never become technical UI errors', async () => {
    for (const response of [new Response('PRIVATE_SERVER_DETAIL', { status: 500 }), new Response('<html>proxy</html>'),
        new Response(JSON.stringify({ html: '<p>text</p>', count: -1 })), new Response(JSON.stringify({ count: 2 }))]) {
        await assert.rejects(readCatalogResponse(response), error => {
            assert.match(error.message, /Pretraga trenutačno nije dostupna/);
            assert.doesNotMatch(error.message, /JSON|PRIVATE|proxy|Unexpected/);
            return true;
        });
    }
});

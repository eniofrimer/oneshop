import test from 'node:test';
import assert from 'node:assert/strict';
import { createLatestRequest } from '../../resources/js/live-search.js';

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

import test from 'node:test';
import assert from 'node:assert/strict';
import { resolveVariant } from '../../resources/js/variant-selection.js';

const options = [
    { id: 10, values: [{ id: 101 }, { id: 102 }] },
    { id: 20, values: [{ id: 201 }, { id: 202 }] },
];
const variants = [
    { id: 1, values: { 10: 101, 20: 201 }, available: true, price: 2500 },
    { id: 2, values: { 10: 101, 20: 202 }, available: false, price: 2700 },
    { id: 3, values: { 10: 102, 20: 202 }, available: true, price: 2800 },
];

test('complete choices resolve the specific SKU, availability and price', () => {
    const result = resolveVariant(options, variants, { 10: 102, 20: 202 });
    assert.equal(result.state, 'available');
    assert.equal(result.variant.id, 3);
    assert.equal(result.variant.price, 2800);
});

test('partial, cleared and foreign option values never resolve a SKU', () => {
    for (const selected of [{}, { 10: 101 }, { 10: null, 20: 201 }, { 10: 201, 20: 201 }, { 10: '101', 20: 201 }]) {
        assert.deepEqual(resolveVariant(options, variants, selected), { state: 'incomplete', variant: null });
    }
});

test('a missing combination and ambiguous duplicate combination are unavailable', () => {
    assert.deepEqual(resolveVariant(options, variants, { 10: 102, 20: 201 }), { state: 'unavailable', variant: null });
    const duplicate = [...variants, { ...variants[0], id: 4 }];
    assert.deepEqual(resolveVariant(options, duplicate, { 10: 101, 20: 201 }), { state: 'unavailable', variant: null });
});

test('an existing sold-out SKU can show its own price but cannot be ordered', () => {
    const result = resolveVariant(options, variants, { 10: 101, 20: 202 });
    assert.equal(result.state, 'sold-out');
    assert.equal(result.variant.id, 2);
    assert.equal(result.variant.price, 2700);
});

test('matching is independent of option/value key order and preserves inputs', () => {
    const selected = { 20: 202, 10: 102 };
    const before = JSON.stringify({ options, variants, selected });
    assert.equal(resolveVariant([...options].reverse(), variants, selected).variant.id, 3);
    assert.equal(JSON.stringify({ options, variants, selected }), before);
});

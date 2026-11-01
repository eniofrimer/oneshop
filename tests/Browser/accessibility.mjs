import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';

// Each caller keeps its own capture guards and sanitized result summaries.
export async function auditAccessibility(page) {
    const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze();
    const evaluated = ['passes', 'violations', 'incomplete', 'inapplicable']
        .flatMap(category => result[category]);
    assert.ok(evaluated.some(rule => rule.id === 'target-size'),
        'The pinned axe engine must evaluate the WCAG 2.2 target-size rule.');
    return result;
}

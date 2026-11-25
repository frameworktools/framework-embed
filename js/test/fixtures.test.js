import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { render } from '../src/render.js';
import { formatNumber, formatPValue, formatPercent } from '../src/format.js';

const spec = join(dirname(fileURLToPath(import.meta.url)), '../../spec');
const fixtures = join(spec, 'fixtures');
const names = readdirSync(fixtures).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5)).sort();
const load = (name) => JSON.parse(readFileSync(join(fixtures, name + '.json'), 'utf8'));

describe('fixtures', () => {
    it('has a fixture for every required case', () => {
        for (const name of ['r-glm-logit', 'r-lmer', 'python-xgboost', 'stata-regress', 'r-tibble', 'r-ttest-welch', 'r-ggplot',
            'python-altair', 'page', 'truncated-table', 'minimal-model', 'minimal-test', 'hostile-model', 'hostile-vega', 'hostile-page', 'hostile-image',
            'r-snippet', 'stata-snippet', 'python-snippet', 'snippet-truncated', 'snippet-output-hidden', 'hostile-snippet', 'r-ggplot-vega', 'hostile-vega-image',
            'r-dag', 'dagitty-dag', 'dag-no-adjustment', 'dag-unidentified', 'hostile-dag',
            'r-tree', 'tree-subfolder', 'tree-display-hidden', 'hostile-tree', 'tree-long', 'tree-long-full',
            'model-details-hidden', 'test-details-hidden', 'dag-display-hidden',
            'credit-hidden', 'credit-and-title-hidden', 'page-credit-hidden']) {
            expect(names).toContain(name);
        }
    });

    it.each(names)('%s renders the expected HTML', (name) => {
        const expected = readFileSync(join(fixtures, name + '.html'), 'utf8');
        expect(render(load(name))).toBe(expected);
    });

    it.each(names)('%s parses as one figure', (name) => {
        const host = document.createElement('div');
        host.innerHTML = render(load(name));
        expect(host.children).toHaveLength(1);
        expect(host.firstElementChild.tagName).toBe('FIGURE');
        expect(host.querySelectorAll('script:not([type="application/json"])')).toHaveLength(0);
    });
});

describe('trimmed rows', () => {
    it('renders a table trimmed to the shown rows exactly as the full table', () => {
        const full = load('r-tibble');
        const trimmed = { ...full, data: { ...full.data, tables: [{ ...full.data.tables[0], rows: full.data.tables[0].rows.slice(0, 25) }] } };
        expect(render(trimmed)).toBe(render(full));
        expect(render(trimmed)).toContain('Showing <span class="fw-embed__num">25</span> of <span class="fw-embed__num">60</span> rows.');
    });
});

describe('number formatting', () => {
    const cases = JSON.parse(readFileSync(join(spec, 'format-cases.json'), 'utf8'));
    const formats = {
        number: (x) => formatNumber(x), decimal: (x) => formatNumber(x, true),
        result: (x) => formatNumber(x, false, 2), 'result-decimal': (x) => formatNumber(x, true, 2),
        p: formatPValue, percent: formatPercent,
    };
    it('matches spec/format-cases.json', () => {
        const failures = cases.filter((c) => formats[c.format](c.value) !== c.expected);
        expect(failures).toEqual([]);
    });
});

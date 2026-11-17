import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { render } from '../src/render.js';
import { expandDetailsForPrint } from '../src/browser.js';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '../../spec/fixtures');
const load = (name) => JSON.parse(readFileSync(join(fixtures, name + '.json'), 'utf8'));
const dom = (doc) => { const host = document.createElement('div'); host.innerHTML = render(doc); document.body.append(host); return host; };
const headers = (table) => [...table.querySelectorAll('thead th')].map((th) => th.textContent);

describe('model view', () => {
    it('shows one full coefficient table with the odds ratio after the estimate', () => {
        const host = dom(load('r-glm-logit'));
        const tables = host.querySelectorAll('table');
        expect(tables).toHaveLength(1);
        expect(tables[0].closest('details')).toBeNull();
        expect(headers(tables[0])).toEqual(['Term', 'Estimate', 'Odds ratio (95% CI)', 'Std. error', 'Statistic', 'p-value', '95% CI']);
        expect([...tables[0].querySelectorAll('tbody tr')].map((tr) => tr.children[2].textContent)).toEqual(['—', '< 0.01 (< 0.01 to 0.13)', '1.04 (1.00 to 1.07)']);
        expect(tables[0].querySelector('tbody tr:nth-child(3) .fw-embed__ci').textContent).toBe('(1.00 to 1.07)');
        const details = host.querySelector('details.fw-embed__details');
        expect(details.open).toBe(false);
        expect(details.querySelector('summary').textContent).toBe('Model details');
        expect(details.querySelector('table')).toBeNull();
        expect(details.querySelector('dl.fw-embed__stats')).not.toBeNull();
    });

    it('shows a Stata command in the formula slot and not in the statistics', () => {
        const host = dom(load('stata-regress'));
        expect(host.querySelector('.fw-embed__formula code').textContent).toBe('regress price mpg weight i.foreign');
        expect([...host.querySelectorAll('dt')].map((dt) => dt.textContent)).not.toContain('Command');
        expect(headers(host.querySelector('table'))).toEqual(['Term', 'Estimate', 'Std. error', 'Statistic', 'p-value', '95% CI']);
        const text = host.querySelector('.fw-embed__stat--text');
        expect(text.querySelector('dt').textContent).toBe('Standard errors');
        expect(text.querySelector('dd').classList.contains('fw-embed__num')).toBe(false);
    });

    it('keeps the formula and drops the command when both exist', () => {
        const doc = load('stata-regress');
        doc.data.formula = 'price ~ mpg + weight + i.foreign';
        const host = dom(doc);
        expect(host.querySelector('.fw-embed__formula code').textContent).toBe('price ~ mpg + weight + i.foreign');
        expect(host.textContent).not.toContain('regress price');
    });

    it('spans long text values across the grid', () => {
        const doc = load('stata-regress');
        doc.data.metrics.push({ name: 'estimator_note', value: 'clustered by species_id and island' });
        const host = dom(doc);
        const wide = host.querySelector('.fw-embed__stat--wide');
        expect(wide.querySelector('dt').textContent).toBe('Estimator note');
        expect(host.querySelectorAll('.fw-embed__stat--wide')).toHaveLength(1);
    });

    it('puts random effects in details', () => {
        const host = dom(load('r-lmer'));
        expect(host.querySelectorAll('.fw-embed__body > .fw-embed__scroll')).toHaveLength(1);
        expect([...host.querySelectorAll('details caption')].map((c) => c.textContent)).toEqual(['Random effects']);
    });

    it('shows the top 10 features and continues in details', () => {
        const host = dom(load('python-xgboost'));
        expect(host.querySelectorAll('.fw-embed__body > .fw-embed__scroll tbody tr')).toHaveLength(10);
        expect(host.querySelector('.fw-embed__body > .fw-embed__note').textContent).toBe('Showing 10 of 30 rows. More in Model details.');
        expect(host.querySelectorAll('details tbody tr')).toHaveLength(15);
        expect(host.querySelector('details .fw-embed__note').textContent).toBe('Showing rows 11 to 25 of 30. View the full table');
    });

    it('shows everything when there is no coefficient table', () => {
        const host = dom(load('minimal-model'));
        expect(host.querySelector('details')).toBeNull();
        expect(host.querySelector('dl.fw-embed__stats')).not.toBeNull();
    });

    it('omits the disclosure when there is nothing else', () => {
        const doc = load('r-glm-logit');
        doc.data.metrics = [];
        expect(dom(doc).querySelector('details')).toBeNull();
    });
});

describe('model names', () => {
    const cases = JSON.parse(readFileSync(join(fixtures, '../model-names.json'), 'utf8'));
    it.each(cases.map((c) => [c.meta, c.model]))('names %s', (meta, model) => {
        const host = dom({ schema: 'framework.embed/v1', kind: 'model', url: 'https://framework.pub/d/x', data: { model, tables: [], metrics: [{ name: 'nobs', value: 1 }] } });
        expect(host.querySelector('.fw-embed__meta')?.textContent ?? null).toBe(meta);
    });
});

describe('test view', () => {
    it('shows the statistic, p-value, and estimate with its interval', () => {
        const host = dom(load('python-ttest-welch'));
        const pairs = [...host.querySelectorAll('.fw-embed__body > dl div')].map((div) => [div.querySelector('dt').textContent, div.querySelector('dd').textContent]);
        expect(pairs).toEqual([['t', '-3.04'], ['p-value', '0.004'], ['Difference in means (95% CI)', '-0.98 (-1.62 to -0.33)']]);
        const hidden = [...host.querySelectorAll('details dt')].map((dt) => dt.textContent);
        expect(hidden).toEqual(['Alternative', 'df']);
    });

    it('lists two estimates and the interval separately', () => {
        const host = dom(load('r-ttest-welch'));
        const terms = [...host.querySelectorAll('.fw-embed__body > dl dt')].map((dt) => dt.textContent);
        expect(terms).toEqual(['t', 'p-value', 'Mean in group OJ', 'Mean in group VC', '95% CI']);
        expect([...host.querySelectorAll('details dt')].map((dt) => dt.textContent)).toEqual(['Alternative', 'df', 'Null value: difference in means between group OJ and group VC']);
    });
});

describe('print', () => {
    it('opens closed details for printing and restores them', () => {
        const host = dom(load('r-glm-logit'));
        const details = host.querySelector('details');
        expandDetailsForPrint();
        window.dispatchEvent(new Event('beforeprint'));
        expect(details.open).toBe(true);
        window.dispatchEvent(new Event('afterprint'));
        expect(details.open).toBe(false);
    });
});

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
    it('shows only the exponentiated estimates by default', () => {
        const host = dom(load('r-glm-logit'));
        const visible = host.querySelector('.fw-embed__body > .fw-embed__scroll table');
        expect(headers(visible)).toEqual(['Term', 'Odds ratio (95% CI)']);
        expect([...visible.querySelectorAll('tbody td')].map((td) => td.textContent)).toEqual(['—', '< 0.01 (< 0.01 to 0.13)', '1.04 (1.00 to 1.07)']);
        const details = host.querySelector('details.fw-embed__details');
        expect(details.open).toBe(false);
        expect(details.querySelector('summary').textContent).toBe('Model details');
        expect(headers(details.querySelector('table'))).toEqual(['Term', 'Estimate', 'Std. error', 'Statistic', 'p-value', '95% CI']);
        expect(details.querySelector('dl.fw-embed__stats')).not.toBeNull();
    });

    it('shows estimates and intervals without exponentiation', () => {
        const host = dom(load('stata-regress'));
        expect(headers(host.querySelector('.fw-embed__body > .fw-embed__scroll table'))).toEqual(['Term', 'Estimate', '95% CI']);
        expect(host.querySelector('.fw-embed__body > .fw-embed__scroll tbody td').textContent).toBe('21.85');
    });

    it('puts random effects in details', () => {
        const host = dom(load('r-lmer'));
        expect(host.querySelectorAll('.fw-embed__body > .fw-embed__scroll')).toHaveLength(1);
        expect([...host.querySelectorAll('details caption')].map((c) => c.textContent)).toEqual(['Fixed effects', 'Random effects']);
    });

    it('shows the top 10 features of an importance-only model', () => {
        const host = dom(load('python-xgboost'));
        const visible = host.querySelector('.fw-embed__body > .fw-embed__scroll table');
        expect(visible.querySelectorAll('tbody tr')).toHaveLength(10);
        expect(host.querySelector('.fw-embed__body > .fw-embed__note').textContent).toBe('Showing 10 of 30 rows. More in Model details.');
        expect(host.querySelectorAll('details tbody tr')).toHaveLength(25);
    });

    it('shows everything when there is no estimate table', () => {
        const host = dom(load('minimal-model'));
        expect(host.querySelector('details')).toBeNull();
        expect(host.querySelector('dl.fw-embed__stats')).not.toBeNull();
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

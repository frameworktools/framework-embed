import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { render } from '../src/render.js';
import { enhanceTips, expandDetailsForPrint } from '../src/browser.js';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '../../spec/fixtures');
const load = (name) => JSON.parse(readFileSync(join(fixtures, name + '.json'), 'utf8'));
const dom = (doc) => { const host = document.createElement('div'); host.innerHTML = render(doc); document.body.append(host); return host; };
const headers = (table) => [...table.querySelectorAll('thead th')].map((th) => th.textContent);

describe('model view', () => {
    it('switches a logit model between odds ratios and log odds, odds ratios first', () => {
        const host = dom(load('r-glm-logit'));
        const scales = host.querySelector('form.fw-embed__scales');
        expect(scales.closest('details')).toBeNull();
        const options = [...scales.querySelectorAll('.fw-embed__switch input[type="radio"]')];
        expect(options.map((input) => [input.value, input.parentElement.textContent.trim(), input.checked])).toEqual([['ratio', 'Odds ratio', true], ['model', 'Log odds', false]]);
        expect(new Set(options.map((input) => input.name)).size).toBe(1);

        // Both views are the same table; only the estimate, std. error, and interval change scale
        const ratio = scales.querySelector('.fw-embed__scale--ratio table');
        const model = scales.querySelector('.fw-embed__scale--model table');
        expect(headers(ratio)).toEqual(['Term', 'Odds ratio', 'Std. error', 'Statistic', 'p-value', '95% CI']);
        expect(headers(model)).toEqual(['Term', 'Log odds', 'Std. error', 'Statistic', 'p-value', '95% CI']);
        const cells = (table) => [...table.querySelectorAll('tbody tr')].map((tr) => [...tr.children].map((c) => c.textContent));
        const doc = load('r-glm-logit');
        const rows = doc.data.tables[0].rows;
        const names = doc.data.tables[0].columns.map((c) => c.name);
        const at = (row, name) => row[names.indexOf(name)];
        cells(ratio).forEach((row, i) => {
            expect(row[0]).toBe(cells(model)[i][0]);
            expect(row[3]).toBe(cells(model)[i][3]);
            expect(row[4]).toBe(cells(model)[i][4]);
            // A rounded cell keeps its full value in the title
            const td = ratio.querySelectorAll('tbody tr')[i].children[1];
            const value = Number((td.getAttribute('title') ?? row[1]).replaceAll(',', ''));
            expect(Math.abs(value / Math.exp(at(rows[i], 'estimate')) - 1)).toBeLessThan(0.01);
        });
        expect(cells(ratio)[2][1]).toBe('1.04');
        expect(cells(ratio)[2][5]).toBe('1.00 to 1.07');
        expect(host.querySelectorAll('table')).toHaveLength(2);

        const details = host.querySelector('details.fw-embed__details');
        expect(details.open).toBe(false);
        expect(details.querySelector('summary').textContent).toBe('Model details');
        expect(details.querySelector('table')).toBeNull();
        expect(details.querySelector('dl.fw-embed__stats')).not.toBeNull();
    });

    it('gives each embed its own switch, so two on one page toggle separately', () => {
        const page = document.createElement('div');
        page.innerHTML = render(load('r-glm-logit')) + render(load('r-glm-logit'));
        const [first, second] = page.querySelectorAll('form.fw-embed__scales');
        first.querySelector('input[value="model"]').checked = true;
        expect(second.querySelector('input[value="ratio"]').checked).toBe(true);
        expect(first.querySelector('input[value="ratio"]').checked).toBe(false);
    });

    it('labels a Cox model hazard ratio and log hazard', () => {
        const host = dom(load('stata-stcox'));
        expect([...host.querySelectorAll('.fw-embed__switch label')].map((label) => label.textContent.trim())).toEqual(['Hazard ratio', 'Log hazard']);
    });

    it('shows a single table with no switch for a model without a ratio', () => {
        const host = dom(load('r-lm-penguins'));
        expect(host.querySelector('form.fw-embed__scales')).toBeNull();
        expect(headers(host.querySelector('table'))[1]).toBe('Estimate');
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

    it('shows random effects beside the fixed effects, outside the details', () => {
        const host = dom(load('r-lmer'));
        expect([...host.querySelectorAll('.fw-embed__body > .fw-embed__scroll caption')].map((c) => c.textContent)).toEqual(['Fixed effects', 'Random effects']);
        expect(host.querySelectorAll('details caption')).toHaveLength(0);
        expect(host.querySelector('details .fw-embed__stats')).not.toBeNull();
    });

    it('shows the top 10 features and continues in details', () => {
        const host = dom(load('python-xgboost'));
        expect(host.querySelectorAll('.fw-embed__body > .fw-embed__scroll tbody tr')).toHaveLength(10);
        expect(host.querySelector('.fw-embed__body > .fw-embed__note').textContent).toBe('Showing 10 of 30 rows. The rest are in Model details.');
        expect(host.querySelectorAll('details tbody tr')).toHaveLength(15);
        expect(host.querySelector('details .fw-embed__note').textContent).toBe('Showing rows 11 to 25 of 30. View full table');
    });

    it('shows everything when there is no coefficient table', () => {
        const host = dom(load('minimal-model'));
        expect(host.querySelector('details')).toBeNull();
        expect(host.querySelector('dl.fw-embed__stats')).not.toBeNull();
    });

    it('keeps the model type, response, and formula in the disclosure', () => {
        const doc = load('r-glm-logit');
        doc.data.metrics = [];
        const details = dom(doc).querySelector('details');
        expect(details.querySelector('.fw-embed__meta')).not.toBeNull();
        expect(details.querySelector('.fw-embed__meta > code.fw-embed__var').textContent).toBe('am');
        expect(details.querySelector('.fw-embed__formula')).not.toBeNull();
        expect(dom(doc).querySelector('.fw-embed__body > .fw-embed__meta')).toBeNull();
    });

    it('omits the disclosure when there is nothing else', () => {
        const doc = load('r-glm-logit');
        doc.data.metrics = [];
        delete doc.data.formula;
        doc.data.model = null;
        expect(dom(doc).querySelector('details')).toBeNull();
    });
});

describe('figures', () => {
    it('marks SVG figures as vector so they fill the width', () => {
        expect(dom(load('r-ggplot')).querySelector('img').className).toBe('fw-embed__img fw-embed__img--vector');
        expect(dom(load('matplotlib-png')).querySelector('img').className).toBe('fw-embed__img');
        const doc = load('r-ggplot');
        doc.image.content_type = 'IMAGE/SVG+XML; charset=utf-8';
        expect(dom(doc).querySelector('img').classList.contains('fw-embed__img--vector')).toBe(true);
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

describe('variable tips', () => {
    it('explains each mapped term with its variable, level, and rows', () => {
        const host = dom(load('r-lm-variables'));
        const table = host.querySelector('.fw-embed__table--coefficients');
        const terms = [...table.querySelectorAll('tbody th')];
        expect(terms[0].querySelector('button')).toBeNull();
        const chinstrap = terms.find((th) => th.querySelector('button')?.textContent === 'speciesChinstrap');
        const tip = chinstrap.querySelector('.fw-embed__tip');
        expect(chinstrap.querySelector('button').getAttribute('popovertarget')).toBe(tip.id);
        expect(tip.hasAttribute('popover')).toBe(true);
        expect([...tip.querySelectorAll('.fw-embed__tip-block > span')].map((span) => span.textContent))
            .toEqual(['species = Chinstrap', 'Penguin species', 'Compared with Adelie. 68 of 342 rows.']);
        const mass = terms.find((th) => th.querySelector('button')?.textContent === 'body_mass_g');
        expect(mass.querySelector('.fw-embed__tip-detail').textContent).toBe('Mean 4201.75, SD 801.95, range 2700 to 6300. 342 rows.');
    });

    it('leaves a term plain when its mapping names an unknown variable', () => {
        const doc = load('r-lm-variables');
        doc.data.terms = [{ term: 'speciesGentoo', parts: [{ variable: 'island', level: 'Biscoe' }] }];
        expect(dom(doc).querySelector('.fw-embed__term')).toBeNull();
    });

    it('describes data table columns from their label and summary', () => {
        const doc = {
            kind: 'table', key: 'k', version: 1, title: 'T',
            data: { tables: [{ title: 'T', columns: [
                { name: 'mass', type: 'number', label: 'Body mass (g)', summary: { count: 10, missing: 2, mean: 4000, sd: 500.5, min: 3000, max: 5000 } },
                { name: 'species', type: 'string', summary: { count: 10, distinct: 3, top: [{ value: 'Adelie', count: 5 }, { value: 'Gentoo', count: 3 }, { value: 'Chinstrap', count: 2 }] } },
                { name: 'plain', type: 'string' },
            ], rows: [[1, 'Adelie', 'x']] }] },
        };
        const heads = [...dom(doc).querySelectorAll('thead th')];
        expect(heads[0].querySelector('button').textContent).toBe('Body mass (g)');
        expect(heads[0].querySelector('.fw-embed__tip-name').textContent).toBe('mass');
        expect(heads[0].querySelector('.fw-embed__tip-detail').textContent).toBe('Mean 4000, SD 500.50, range 3000 to 5000. 10 rows, 2 missing.');
        expect(heads[1].querySelector('.fw-embed__tip-detail').textContent).toBe('3 distinct values. Most common: Adelie (5), Gentoo (3), Chinstrap (2). 10 rows.');
        expect(heads[2].innerHTML).toBe('plain');
    });

    it('renumbers tip ids when the same embed appears twice', () => {
        document.body.innerHTML = '';
        dom(load('r-lm-variables'));
        dom(load('r-lm-variables'));
        enhanceTips(document);
        const ids = [...document.querySelectorAll('.fw-embed__tip')].map((tip) => tip.id);
        expect(new Set(ids).size).toBe(ids.length);
        for (const button of document.querySelectorAll('.fw-embed__term')) {
            expect(button.getAttribute('popovertarget')).toBe(button.nextElementSibling.id);
        }
    });
});

describe('bayesian models', () => {
    it('labels the posterior summary and uses the package ratio columns', () => {
        const host = dom(load('r-brms-logit'));
        const ratio = host.querySelector('.fw-embed__scale--ratio table');
        const model = host.querySelector('.fw-embed__scale--model table');
        expect(headers(ratio)).toEqual(['Term', 'Odds ratio', 'Posterior SD', '95% interval', 'R-hat', 'Bulk ESS', 'Tail ESS']);
        expect(headers(model)).toEqual(['Term', 'Log odds', 'Posterior SD', '95% interval', 'R-hat', 'Bulk ESS', 'Tail ESS']);
        const row = (table, n) => [...table.querySelectorAll('tbody tr')[n].children].map((c) => c.textContent);
        // The ratio values are the package's, not exp() of the posterior mean
        expect(row(ratio, 2)).toEqual(['educationcollege', '1.80', '0.22', '1.42 to 2.27', '1.02', '613', '880']);
        expect(row(model, 2)).toEqual(['educationcollege', '0.58', '0.12', '0.35 to 0.82', '1.02', '613', '880']);
    });

    it('warns about R-hat and divergences, and keeps the priors in Model details', () => {
        const host = dom(load('r-brms-logit'));
        expect([...host.querySelectorAll('.fw-embed__body > .fw-embed__warning')].map((p) => p.textContent)).toEqual([
            'R-hat above 1.01 for 1 parameter: the chains may not have converged.',
            '3 divergent transitions: the posterior may be biased.',
        ]);
        const details = host.querySelector('details');
        expect(details.querySelector('.fw-embed__meta').textContent).toBe('Bayesian logistic mixed model · Outcome: voted');
        expect([...details.querySelectorAll('caption')].map((c) => c.textContent)).toContain('Priors');
        expect([...details.querySelectorAll('dt')].map((dt) => dt.textContent)).toContain('Credible level');
    });

    it('shows only the model scale when the package sent no ratio columns', () => {
        const doc = load('r-brms-logit');
        const table = doc.data.tables[0];
        const keep = table.columns.map((c, i) => [c, i]).filter(([c]) => !c.name.startsWith('ratio_'));
        table.columns = keep.map(([c]) => c);
        table.rows = table.rows.map((row) => keep.map(([, i]) => row[i]));
        const host = dom(doc);
        expect(host.querySelector('.fw-embed__switch')).toBeNull();
        expect(headers(host.querySelector('.fw-embed__table--coefficients'))[1]).toBe('Posterior mean');
    });
});

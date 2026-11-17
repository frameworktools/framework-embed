// Runs real vega, vega-lite, and vega-embed (the pinned versions) with the bundle's chart options.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import vegaEmbed from 'vega-embed';
import * as vega from 'vega';
import * as vegaLite from 'vega-lite';
import { expressionInterpreter } from 'vega-interpreter';
import { blockingLoader, chartOptions, chartSpec, configure, enhanceCharts, referencesUrl } from '../src/browser.js';
import { render } from '../src/render.js';

const base = { $schema: 'https://vega.github.io/schema/vega-lite/v6.json', data: { values: [{ a: 'A', b: 1 }, { a: 'B', b: 2 }] }, mark: 'bar',
    encoding: { x: { field: 'a', type: 'nominal' }, y: { field: 'b', type: 'quantitative' } } };
const doc = (spec) => ({ schema: 'framework.embed/v1', key: 'chart', version: 1, kind: 'vega-lite', title: 'Chart', alt: 'A chart.', url: 'https://framework.pub/d/chart/versions/1', spec });

let fetchSpy;
let openSpy;
beforeEach(() => {
    fetchSpy = vi.fn(async () => { throw new Error('network'); });
    vi.stubGlobal('fetch', fetchSpy);
    openSpy = vi.spyOn(window, 'open').mockImplementation(() => null);
    delete globalThis.__fwPwned;
});
afterEach(() => { configure({ vegaEmbed: null }); vi.unstubAllGlobals(); vi.restoreAllMocks(); document.body.innerHTML = ''; });

// The options the bundle passes, applied to real vega-embed.
async function draw(spec) {
    const view = document.createElement('div');
    document.body.append(view);
    const safe = chartSpec(spec);
    if (safe === null) return { refused: true, view };
    const result = await vegaEmbed(view, safe, chartOptions(view));
    return { refused: false, view, result };
}

async function mounted(spec) {
    configure({ vegaEmbed });
    const host = document.createElement('div');
    document.body.append(host);
    host.innerHTML = render(doc(spec));
    enhanceCharts(host, { lazy: false });
    const chart = host.querySelector('[data-fw-embed-chart]');
    await vi.waitFor(() => expect(['ready', 'error']).toContain(chart.dataset.fwEmbedChartState), { timeout: 5000 });
    return { host, chart };
}

describe('external resources', () => {
    it('draws inline data with the safe options', async () => {
        const { chart } = await mounted(base);
        expect(chart.dataset.fwEmbedChartState).toBe('ready');
        expect(chart.querySelector('svg')).not.toBeNull();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it.each([
        ['data.url', { ...base, data: { url: 'https://evil.example/data.csv' } }],
        ['lookup data.url', { ...base, transform: [{ lookup: 'a', from: { data: { url: 'https://evil.example/x.json' }, key: 'a' } }] }],
        ['image mark url', { ...base, mark: 'image', encoding: { url: { value: 'https://evil.example/pixel.png' } } }],
        ['vega data url', { $schema: 'https://vega.github.io/schema/vega/v6.json', data: [{ name: 't', url: 'https://evil.example/d.json' }] }],
    ])('never fetches for %s and keeps the fallback', async (name, spec) => {
        const { chart } = await mounted(spec);
        expect(chart.dataset.fwEmbedChartState).toBe(referencesUrl(spec) ? 'error' : 'ready');
        expect(fetchSpy).not.toHaveBeenCalled();
        if (referencesUrl(spec)) expect(chart.querySelector('.fw-embed__fallback a')?.getAttribute('href')).toBe('https://framework.pub/d/chart/versions/1');
        for (const image of chart.querySelectorAll('image')) expect(image.getAttribute('href') ?? '').toBe('');
    });

    it('refuses data loads even when the URL check is bypassed', async () => {
        const compiled = vegaLite.compile({ ...base, data: { url: 'https://evil.example/d.csv' } }).spec;
        const view = new vega.View(vega.parse(compiled, null, { ast: true }), { loader: blockingLoader, renderer: 'none', expr: expressionInterpreter });
        await view.runAsync().catch(() => {});
        expect(fetchSpy).not.toHaveBeenCalled();
        await expect(blockingLoader.load('https://evil.example')).rejects.toThrow();
        await expect(blockingLoader.sanitize('https://evil.example', { context: 'href' })).rejects.toThrow();
    });

    it('ignores embed options in usermeta', async () => {
        const spec = { ...base, usermeta: { embedOptions: { actions: true, ast: false, loader: { baseURL: 'https://evil.example/' }, tooltip: false } } };
        expect(chartSpec(spec).usermeta).toBeUndefined();
        const { view } = await draw(spec);
        expect(view.querySelector('.vega-actions, details')).toBeNull();
    });
});

describe('expressions', () => {
    it.each([
        "constructor.constructor('globalThis.__fwPwned = 1')()",
        "datum.constructor.constructor('globalThis.__fwPwned = 1')()",
        "window.__fwPwned = 1",
        "this.constructor.constructor('globalThis.__fwPwned = 1')()",
        "(datum['__proto__']['constructor']['constructor'])('globalThis.__fwPwned = 1')()",
        "datum['constr' + 'uctor']['constr' + 'uctor']('globalThis.__fwPwned = 1')()",
    ])('does not execute %s', async (expression) => {
        const spec = { ...base, transform: [{ calculate: expression, as: 'x' }] };
        await draw(spec).catch(() => {});
        await mounted(spec);
        expect(globalThis.__fwPwned).toBeUndefined();
    });

    it('evaluates ordinary expressions with the interpreter', async () => {
        const { result } = await draw({ ...base, transform: [{ calculate: 'datum.b * 2', as: 'c' }] });
        expect(result.view.data('data_0').map((row) => row.c)).toEqual([2, 4]);
    });
});

describe('links and tooltips', () => {
    it.each(['javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)', 'java\tscript:alert(1)', '&#106;avascript:alert(1)', 'javascript&colon;alert(1)', 'data:text/html,<script>alert(1)</script>'])(
        'renders href %j as no link and opens nothing', async (href) => {
            const spec = { ...base, encoding: { ...base.encoding, href: { value: href }, tooltip: { value: '<img src=x onerror="globalThis.__fwPwned=1">' } } };
            const { view, result } = await draw(spec);
            expect(view.querySelector('a')).toBeNull();
            expect(view.innerHTML).not.toMatch(/javascript|data:text/i);
            const item = result.view.scenegraph().root.items[0];
            result.view._renderer?.handleHref?.(new window.MouseEvent('click'), item, href);
            await new Promise((resolve) => setTimeout(resolve, 0));
            expect(openSpy).not.toHaveBeenCalled();
        });

    it('shows tooltips as text', () => {
        const handler = chartOptions(document.body).tooltip;
        handler(null, { clientX: 1, clientY: 2 }, null, { name: '<img src=x onerror="globalThis.__fwPwned=1">', n: 3 });
        const tip = document.querySelector('.fw-embed__tooltip');
        expect(tip.textContent).toBe('name: <img src=x onerror="globalThis.__fwPwned=1">\nn: 3');
        expect(tip.querySelector('img')).toBeNull();
        expect(globalThis.__fwPwned).toBeUndefined();
        handler(null, {}, null, null);
        expect(tip.hidden).toBe(true);
    });

    it('forces the safety options over site options', () => {
        configure({ vegaOptions: { actions: true, ast: false, loader: null, tooltip: true, renderer: 'canvas' } });
        const options = chartOptions(document.body);
        expect(options).toMatchObject({ actions: false, ast: true, loader: blockingLoader, renderer: 'canvas' });
        expect(typeof options.tooltip).toBe('function');
        configure({ vegaOptions: {} });
    });
});

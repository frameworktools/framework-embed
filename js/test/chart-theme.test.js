import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';
import vegaEmbed from 'vega-embed';
import { chartOptions, chartTheme, configure, mount, parseColor, prefersDark, responsiveSpec } from '../src/browser.js';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '../../spec/fixtures');
const load = (name) => JSON.parse(readFileSync(join(fixtures, name + '.json'), 'utf8'));

afterEach(() => {
    configure({ vegaEmbed: null });
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.className = '';
    document.body.removeAttribute('style');
});

const nest = (outerAttrs = {}, innerStyle = '') => {
    const outer = document.createElement('div');
    for (const [k, v] of Object.entries(outerAttrs)) outer.setAttribute(k, v);
    const inner = document.createElement('div');
    inner.setAttribute('style', innerStyle);
    outer.append(inner);
    document.body.append(outer);
    return inner;
};

describe('page theme', () => {
    it('reads explicit markers on the nearest ancestor first', () => {
        vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener() {} }));
        expect(prefersDark(nest({ 'data-theme': 'light' }))).toBe(false);
        expect(prefersDark(nest({ class: 'light' }))).toBe(false);
        expect(prefersDark(nest({ 'data-theme': 'dark' }))).toBe(true);
        expect(prefersDark(nest({ class: 'dark' }, 'background: white'))).toBe(true);
    });

    it('then reads the nearest opaque background', () => {
        vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener() {} }));
        expect(prefersDark(nest({ style: 'background-color: rgb(255, 255, 255)' }))).toBe(false);
        expect(prefersDark(nest({ style: 'background-color: rgb(17, 24, 39)' }))).toBe(true);
        expect(prefersDark(nest({ style: 'background-color: rgb(17, 24, 39)' }, 'background-color: rgba(255, 255, 255, 0)'))).toBe(true);
    });

    it('then the computed color-scheme, then the OS preference', () => {
        vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener() {} }));
        // happy-dom computes no color-scheme, so stand in for the browser.
        const scheme = (value) => vi.stubGlobal('getComputedStyle', () => ({ backgroundColor: 'rgba(0, 0, 0, 0)', colorScheme: value }));
        scheme('light');
        expect(prefersDark(nest())).toBe(false);
        scheme('dark');
        expect(prefersDark(nest())).toBe(true);
        scheme('light dark');
        expect(prefersDark(nest())).toBe(true);
        vi.unstubAllGlobals();
        vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener() {} }));
        expect(prefersDark(nest())).toBe(true);
        vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener() {} }));
        expect(prefersDark(nest())).toBe(false);
    });

    it('parses computed colors', () => {
        expect(parseColor('rgb(1, 2, 3)')).toEqual([1, 2, 3, 1]);
        expect(parseColor('rgba(1, 2, 3, 0.5)')).toEqual([1, 2, 3, 0.5]);
        expect(parseColor('rgb(1 2 3 / 50%)')).toEqual([1, 2, 3, 0.5]);
        expect(parseColor('transparent')).toEqual([0, 0, 0, 0]);
    });
});

describe('responsive size', () => {
    const view = { $schema: 'https://vega.github.io/schema/vega-lite/v6.json', mark: 'point', encoding: {} };
    it('fills the container for single views without a width', () => {
        expect(responsiveSpec(view)).toMatchObject({ width: 'container', autosize: { type: 'fit', contains: 'padding' } });
        expect(responsiveSpec({ ...view, mark: undefined, layer: [] }).width).toBe('container');
        expect(responsiveSpec({ ...view, height: 200 }).height).toBe(200);
        expect(responsiveSpec({ ...view, autosize: { type: 'pad' } }).autosize).toEqual({ type: 'pad' });
    });

    it.each([
        ['explicit width', { width: 400 }],
        ['step width', { width: { step: 20 } }],
        ['facet', { facet: { field: 'a' }, spec: {} }],
        ['repeat', { repeat: ['a'], spec: {} }],
        ['concat', { hconcat: [] }],
        ['row encoding', { encoding: { row: { field: 'a' } } }],
    ])('leaves %s alone', (name, extra) => {
        const spec = { ...view, ...extra };
        expect(responsiveSpec(spec)).toBe(spec);
    });

    it('leaves Vega specifications alone', () => {
        const spec = { $schema: 'https://vega.github.io/schema/vega/v6.json', marks: [] };
        expect(responsiveSpec(spec)).toBe(spec);
    });
});

describe('chart config', () => {
    it('uses the figure font and colors', () => {
        const host = document.createElement('div');
        host.setAttribute('style', 'font-family: Georgia, serif; color: rgb(20, 30, 40)');
        document.body.append(host);
        host.innerHTML = '<figure class="fw-embed"><div class="fw-embed__chart"></div></figure>';
        const theme = chartTheme(host.querySelector('.fw-embed__chart'));
        expect(theme.config.font).toBe('Georgia, serif');
        expect(theme.config.title.color).toBe('rgba(20, 30, 40, 1)');
        expect(theme.config.axis.labelColor).toBe('rgba(20, 30, 40, 0.64)');
        expect(theme.config.axis.gridColor).toBe('rgba(20, 30, 40, 0.16)');
        expect(theme.config.background).toBe('transparent');
    });

    it('lets the specification config win over the page config', async () => {
        const host = document.createElement('div');
        host.setAttribute('style', 'font-family: Georgia, serif; color: rgb(20, 30, 40)');
        document.body.append(host);
        host.innerHTML = '<figure class="fw-embed"><div class="fw-embed__chart"><div></div></div></figure>';
        const view = host.querySelector('.fw-embed__chart > div');
        const spec = { $schema: 'https://vega.github.io/schema/vega-lite/v6.json', width: 200, data: { values: [{ a: 1 }, { a: 2 }] }, mark: 'point',
            encoding: { x: { field: 'a', type: 'quantitative' } }, config: { axis: { labelColor: '#ff0000' } } };
        const { view: vegaView } = await vegaEmbed(view, responsiveSpec(spec), chartOptions(view));
        const svg = await vegaView.toSVG();
        expect(svg).toContain('#ff0000');
        expect(svg).toContain('Georgia, serif');
        expect(svg).toContain('rgba(20, 30, 40, 0.16)');
    });

    it('re-draws when the site switches themes', async () => {
        const calls = [];
        configure({ vegaEmbed: vi.fn(async (el, spec, options) => { calls.push(options.theme ?? 'light'); return { finalize() {} }; }) });
        const host = document.createElement('div');
        document.body.append(host);
        mount(host, load('python-altair'), { lazy: false });
        await vi.waitFor(() => expect(calls).toEqual(['light']));
        document.documentElement.setAttribute('data-theme', 'dark');
        await vi.waitFor(() => expect(calls).toEqual(['light', 'dark']));
        document.documentElement.className = 'unrelated';
        await new Promise((resolve) => setTimeout(resolve, 50));
        expect(calls).toEqual(['light', 'dark']);
        expect(host.querySelectorAll('.fw-embed__chart-view')).toHaveLength(1);
    });
});

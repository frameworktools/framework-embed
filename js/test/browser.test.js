import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { chartTheme, configure, defineElement, enhanceCharts, enhanceTrees, mount, prefersDark, responsiveSpec } from '../src/browser.js';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '../../spec/fixtures');
const load = (name) => JSON.parse(readFileSync(join(fixtures, name + '.json'), 'utf8'));

afterEach(() => { configure({ hosts: ['framework.pub'], vegaEmbed: null }); vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('mount and charts', () => {
    it('mounts a document and draws its chart with vega-embed', async () => {
        const vegaEmbed = vi.fn(async (el) => { el.innerHTML = '<svg></svg>'; });
        configure({ vegaEmbed });
        const host = document.createElement('div');
        document.body.append(host);
        mount(host, load('python-altair'), { lazy: false });
        await vi.waitFor(() => expect(host.querySelector('[data-fw-embed-chart]').dataset.fwEmbedChartState).toBe('ready'));
        const [, spec, options] = vegaEmbed.mock.calls[0];
        expect(spec).toEqual(responsiveSpec(load('python-altair').spec));
        expect(spec).toMatchObject({ width: 'container', autosize: { type: 'fit', contains: 'padding' } });
        expect(options).toMatchObject({ actions: false, renderer: 'svg' });
        expect(host.querySelector('.fw-embed__fallback').classList.contains('fw-embed__sr-only')).toBe(true);
    });

    it('keeps the fallback link when vega fails', async () => {
        configure({ vegaEmbed: vi.fn(async () => { throw new Error('nope'); }) });
        const host = document.createElement('div');
        mount(host, load('python-altair'), { lazy: false });
        await vi.waitFor(() => expect(host.querySelector('[data-fw-embed-chart]').dataset.fwEmbedChartState).toBe('error'));
        expect(host.querySelector('.fw-embed__fallback a').getAttribute('href')).toBe(load('python-altair').url);
        expect(host.querySelector('.fw-embed__chart-view')).toBeNull();
    });

    it('passes the dark theme under a dark ancestor', async () => {
        const vegaEmbed = vi.fn(async () => {});
        configure({ vegaEmbed });
        const page = document.createElement('div');
        page.dataset.theme = 'dark';
        const host = document.createElement('div');
        page.append(host);
        expect(prefersDark(host)).toBe(true);
        mount(host, load('python-altair'), { lazy: false });
        await vi.waitFor(() => expect(vegaEmbed).toHaveBeenCalled());
        expect(vegaEmbed.mock.calls[0][2].theme).toBe('dark');
        expect(enhanceCharts(host)).toBe(0);
    });
});

describe('<framework-artifact>', () => {
    it('renders in light DOM from an allowed src', async () => {
        const fetch = vi.fn(async () => ({ status: 200, ok: true, json: async () => load('r-glm-logit') }));
        vi.stubGlobal('fetch', fetch);
        defineElement();
        const el = document.createElement('framework-artifact');
        el.innerHTML = '<a href="https://framework.pub/d/rglmlogit0000000">Transmission model</a>';
        el.setAttribute('src', 'https://framework.pub/d/rglmlogit0000000');
        document.body.append(el);
        await vi.waitFor(() => expect(el.getAttribute('data-fw-embed-state')).toBe('ready'));
        expect(el.shadowRoot).toBeNull();
        expect(el.querySelector('figure.fw-embed--model')).not.toBeNull();
    });

    it('renders a stored document from inline JSON without fetching', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        defineElement();
        const el = document.createElement('framework-artifact');
        el.setAttribute('src', 'https://framework.pub/d/rglmlogit0000000');
        const script = document.createElement('script');
        script.type = 'application/json';
        script.textContent = JSON.stringify(load('r-glm-logit'));
        el.append(script);
        document.body.append(el);
        await vi.waitFor(() => expect(el.getAttribute('data-fw-embed-state')).toBe('ready'));
        expect(fetch).not.toHaveBeenCalled();
        expect(el.innerHTML).toBe(readFileSync(join(fixtures, 'r-glm-logit.html'), 'utf8'));
        document.body.append(el);
        expect(el.getAttribute('data-fw-embed-state')).toBe('ready');
    });

    it('ignores inline JSON that is not an embed document', async () => {
        const fetch = vi.fn(async () => ({ status: 404, ok: false, json: async () => ({}) }));
        vi.stubGlobal('fetch', fetch);
        defineElement();
        const el = document.createElement('framework-artifact');
        el.setAttribute('src', 'https://framework.pub/d/abc');
        el.innerHTML = '<script type="application/json">{"schema":"other","kind":"page"}</script>';
        document.body.append(el);
        await vi.waitFor(() => expect(el.getAttribute('data-fw-embed-state')).toBe('gone'));
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('keeps its fallback for a host that is not allowed', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        defineElement();
        const el = document.createElement('framework-artifact');
        el.innerHTML = '<a href="https://evil.com/d/x">fallback</a>';
        el.setAttribute('src', 'https://evil.com/d/x');
        document.body.append(el);
        await vi.waitFor(() => expect(el.getAttribute('data-fw-embed-state')).toBe('error'));
        expect(fetch).not.toHaveBeenCalled();
        expect(el.textContent).toBe('fallback');
    });
});

describe('long trees', () => {
    it('clips a long tree and toggles it with its button', () => {
        const host = document.createElement('div');
        document.body.append(host);
        mount(host, load('tree-long'), { lazy: false });
        const tree = host.querySelector('.fw-embed__tree');
        const button = host.querySelector('.fw-embed__more');
        expect(tree.classList.contains('fw-embed__tree--clipped')).toBe(true);
        expect(button.hidden).toBe(false);
        button.click();
        expect(tree.classList.contains('fw-embed__tree--clipped')).toBe(false);
        expect([button.textContent, button.getAttribute('aria-expanded')]).toEqual(['Show less', 'true']);
        button.click();
        expect(tree.classList.contains('fw-embed__tree--clipped')).toBe(true);
        expect([button.textContent, button.getAttribute('aria-expanded')]).toEqual(['Show more', 'false']);
        // Enhancing again wires nothing twice
        enhanceTrees(host);
        button.click();
        expect(button.textContent).toBe('Show less');
    });

    it('leaves a short tree and a whole tree alone', () => {
        for (const name of ['r-tree', 'tree-long-full']) {
            const host = document.createElement('div');
            mount(host, load(name), { lazy: false });
            expect(host.querySelector('.fw-embed__tree--clipped')).toBeNull();
            expect(host.querySelector('.fw-embed__more')).toBeNull();
        }
    });
});

describe('snippet output toggle', () => {
    it('shows hidden output on click and hides it again', () => {
        const host = document.createElement('div');
        document.body.append(host);
        mount(host, load('snippet-output-hidden'), { lazy: false });
        const button = host.querySelector('[data-fw-output]');
        const blocks = [...host.querySelectorAll('.fw-embed__block')];
        expect(button.hidden).toBe(false);
        expect(button.textContent).toBe('Show output');
        expect(blocks.every((block) => block.hidden)).toBe(true);
        button.click();
        expect(button.textContent).toBe('Hide output');
        expect(blocks.some((block) => block.hidden)).toBe(false);
        button.click();
        expect(blocks.every((block) => block.hidden)).toBe(true);
    });

    it('starts with output shown when the snippet does not hide it', () => {
        const host = document.createElement('div');
        mount(host, load('python-snippet'), { lazy: false });
        expect(host.querySelector('[data-fw-output]').textContent).toBe('Hide output');
    });
});

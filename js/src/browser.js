// Browser pieces: fetching, mounting, charts, and the <framework-artifact> element.
import { render } from './render.js';
import { DEFAULT_HOSTS, embedRequestUrl } from './url.js';

// Exact versions, each published months before this package; vega-embed needs the vega and vega-lite globals.
export const DEFAULT_VEGA_SCRIPTS = Object.freeze([
    { src: 'https://cdn.jsdelivr.net/npm/vega@6.2.0/build/vega.min.js', integrity: 'sha384-0Wc8+KeboSkAq/fK81pd4uFbWKu4ouB+y4KWCYxlC69hRWol7vnc6zZSruXOtc0F' },
    { src: 'https://cdn.jsdelivr.net/npm/vega-lite@6.4.3/build/vega-lite.min.js', integrity: 'sha384-9/70gNCfOu6G7xXvkdreMfuqAEsoaGJVXV2BN/JLRXkSmcGvnMqtsRx8HZtUWAvI' },
    { src: 'https://cdn.jsdelivr.net/npm/vega-embed@7.1.0/build/vega-embed.min.js', integrity: 'sha384-giTAWqsEDsWuWzWKi6NCvjgwi160SClnTYYXWPLuy/DnaQTqmk4soinrpxcCS4dx' },
]);

const config = {
    hosts: [...DEFAULT_HOSTS],
    vegaScripts: [...DEFAULT_VEGA_SCRIPTS],
    vegaEmbed: null,
    vegaInterpreter: null,
    vegaOptions: {},
};

// Set allowed hosts, the vega script URLs (or a vegaEmbed function), and extra vega-embed options.
export function configure(options = {}) {
    if (Array.isArray(options.hosts)) config.hosts = [...options.hosts];
    if (Array.isArray(options.vegaScripts)) config.vegaScripts = options.vegaScripts.map((s) => (typeof s === 'string' ? { src: s } : s));
    if (typeof options.vegaEmbed === 'function' || options.vegaEmbed === null) config.vegaEmbed = options.vegaEmbed;
    if (typeof options.vegaInterpreter === 'object' || options.vegaInterpreter === null) config.vegaInterpreter = options.vegaInterpreter;
    if (options.vegaOptions && typeof options.vegaOptions === 'object') config.vegaOptions = { ...options.vegaOptions };
    return { ...config, hosts: [...config.hosts], vegaScripts: [...config.vegaScripts] };
}

export class EmbedError extends Error {
    constructor(code, message) {
        super(message);
        this.name = 'EmbedError';
        this.code = code;
    }
}

// The embed document for an artifact URL, or null when the server says it is gone (404 or 410).
export async function fetchEmbed(url, options = {}) {
    const hosts = options.hosts ?? config.hosts;
    const target = embedRequestUrl(url, hosts);
    if (target === null) throw new EmbedError('not_allowed', 'The URL is not a Framework artifact on an allowed host.');
    const request = options.fetch ?? globalThis.fetch;
    const response = await request(target, {
        method: 'GET', mode: 'cors', credentials: 'omit', redirect: 'error', referrerPolicy: 'strict-origin-when-cross-origin',
        headers: { Accept: 'application/json' }, signal: options.signal,
    });
    if (response.status === 404 || response.status === 410) return null;
    if (!response.ok) throw new EmbedError('http', `The embed request failed with status ${response.status}.`);
    const doc = await response.json();
    if (doc === null || typeof doc !== 'object' || doc.schema !== 'framework.embed/v1') throw new EmbedError('invalid', 'The answer is not a framework.embed/v1 document.');
    return doc;
}

// Render a document into an element and draw any chart in it.
export function mount(element, doc, options = {}) {
    element.innerHTML = render(doc);
    enhanceCharts(element, options);
    return element.firstElementChild;
}

let vegaPromise = null;

function loadScript(entry) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = entry.src;
        script.async = false;
        if (entry.integrity) { script.integrity = entry.integrity; script.crossOrigin = 'anonymous'; }
        script.onload = () => resolve();
        script.onerror = () => reject(new EmbedError('vega', `Could not load ${entry.src}.`));
        document.head.append(script);
    });
}

export function loadVegaEmbed() {
    if (config.vegaEmbed) return Promise.resolve(config.vegaEmbed);
    if (typeof window !== 'undefined' && typeof window.vegaEmbed === 'function') return Promise.resolve(window.vegaEmbed);
    vegaPromise ??= config.vegaScripts.reduce((chain, entry) => chain.then(() => loadScript(entry)), Promise.resolve())
        .then(() => {
            if (typeof window.vegaEmbed !== 'function') throw new EmbedError('vega', 'vega-embed did not load.');
            return window.vegaEmbed;
        })
        .catch((error) => { vegaPromise = null; throw error; });
    return vegaPromise;
}

// [r, g, b, a] (0-255, alpha 0-1) for a CSS color, or null.
export function parseColor(value) {
    if (typeof value !== 'string' || value === '') return null;
    const rgb = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.]+%?))?\s*\)$/i.exec(value.trim());
    if (rgb) {
        const alpha = rgb[4] === undefined ? 1 : rgb[4].endsWith('%') ? parseFloat(rgb[4]) / 100 : parseFloat(rgb[4]);
        return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3]), alpha];
    }
    if (value.trim() === 'transparent') return [0, 0, 0, 0];
    // Other syntaxes (color(), oklch(), color-mix()) resolve through a 1x1 canvas.
    try {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) return null;
        context.fillStyle = value;
        context.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;
        // getImageData returns un-premultiplied channels.
        return a === 0 ? [0, 0, 0, 0] : [r, g, b, Math.round((a / 255) * 1000) / 1000];
    } catch {
        return null;
    }
}

function luminance([r, g, b]) {
    const channel = (c) => { const v = c / 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function styleOf(element) {
    return typeof getComputedStyle === 'function' ? getComputedStyle(element) : null;
}

// Whether the page around an element is dark. Order: explicit markers on the nearest ancestor
// ([data-theme], .dark, .light), the nearest opaque background, the computed color-scheme,
// and last the operating system preference.
export function prefersDark(element) {
    for (let node = element; node && node.nodeType === 1; node = node.parentElement) {
        const theme = node.getAttribute('data-theme');
        if (theme === 'dark' || theme === 'light') return theme === 'dark';
        if (node.classList.contains('dark')) return true;
        if (node.classList.contains('light')) return false;
    }
    for (let node = element; node && node.nodeType === 1; node = node.parentElement) {
        const color = parseColor(styleOf(node)?.backgroundColor);
        if (color && color[3] >= 0.5) return luminance(color) < 0.2;
    }
    const scheme = (styleOf(element)?.colorScheme ?? '').trim().split(/\s+/);
    if (scheme.length === 1 && (scheme[0] === 'dark' || scheme[0] === 'light')) return scheme[0] === 'dark';
    return typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches;
}


// --- chart safety ------------------------------------------------------------
// Stored specifications render in the host site's origin, so vega must not
// evaluate code or reach the network. SPEC.md, "Chart safety", lists the rules.

const refuse = () => Promise.reject(new EmbedError('blocked', 'Framework charts load no external resources.'));

// A vega loader that refuses every data load, image, and link.
export const blockingLoader = Object.freeze({ load: refuse, sanitize: refuse, http: refuse, file: refuse, options: Object.freeze({}) });

// True when the specification names an external resource: any string `url` property.
export function referencesUrl(value, depth = 0) {
    if (depth > 64 || value === null || typeof value !== 'object') return depth > 64;
    if (Array.isArray(value)) return value.some((item) => referencesUrl(item, depth + 1));
    for (const [key, item] of Object.entries(value)) {
        if (key === 'url' && typeof item === 'string') return true;
        if (referencesUrl(item, depth + 1)) return true;
    }
    return false;
}

// The specification vega receives: a copy without `usermeta`, whose embedOptions vega-embed would merge over ours.
// Null when it references a URL.
export function chartSpec(spec) {
    if (spec === null || typeof spec !== 'object' || Array.isArray(spec) || referencesUrl(spec)) return null;
    const copy = JSON.parse(JSON.stringify(spec));
    delete copy.usermeta;
    return copy;
}

function tooltipText(value) {
    if (value === null || value === undefined) return '';
    if (typeof value !== 'object') return String(value);
    return Object.entries(value).map(([key, item]) => `${key}: ${item !== null && typeof item === 'object' ? JSON.stringify(item) : String(item)}`).join('\n');
}

// A vega tooltip handler that writes text only.
export function textTooltip() {
    let tip = null;
    return (handler, event, item, value) => {
        const text = tooltipText(value);
        if (text === '') {
            if (tip) tip.hidden = true;
            return;
        }
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'fw-embed__tooltip';
            tip.setAttribute('role', 'tooltip');
            document.body.append(tip);
        }
        tip.textContent = text;
        tip.hidden = false;
        tip.style.left = `${(event?.clientX ?? 0) + 12}px`;
        tip.style.top = `${(event?.clientY ?? 0) + 12}px`;
    };
}

const COMPOSITE = ['facet', 'repeat', 'concat', 'hconcat', 'vconcat'];

function isVegaLite(spec) {
    const schema = typeof spec.$schema === 'string' ? spec.$schema : '';
    if (schema.includes('/vega-lite/')) return true;
    if (schema.includes('/vega/')) return false;
    return 'mark' in spec || 'layer' in spec;
}

// Single Vega-Lite views without a width fill their container. Composite views keep their own sizes.
export function responsiveSpec(spec) {
    if (!isVegaLite(spec) || spec.width !== undefined || COMPOSITE.some((key) => key in spec)) return spec;
    const encoding = spec.encoding ?? {};
    if (encoding.row || encoding.column || encoding.facet) return spec;
    return { ...spec, width: 'container', autosize: spec.autosize ?? { type: 'fit', contains: 'padding' } };
}

function rgba(color) {
    return color === null ? null : `rgba(${color[0]}, ${color[1]}, ${color[2]}, ${Math.round(color[3] * 1000) / 1000})`;
}

// Font and colors from the page, so the chart matches the site. The specification's own config wins.
export function chartTheme(container) {
    const figure = container.closest('.fw-embed') ?? container;
    const style = styleOf(figure);
    const font = style?.fontFamily || 'sans-serif';
    const fgColor = parseColor(style?.color) ?? [0, 0, 0, 1];
    let muted = null;
    let border = null;
    if (style) {
        const probe = document.createElement('span');
        probe.style.cssText = 'position:absolute;visibility:hidden;color:var(--fw-embed-muted);border:1px solid var(--fw-embed-border)';
        figure.append(probe);
        const probeStyle = styleOf(probe);
        muted = parseColor(probeStyle?.color);
        border = parseColor(probeStyle?.borderTopColor);
        probe.remove();
    }
    // Without the stylesheet, derive muted text and borders from the text color.
    if (muted === null || (muted[3] === 1 && muted.slice(0, 3).join() === fgColor.slice(0, 3).join())) muted = [...fgColor.slice(0, 3), 0.64];
    if (border === null || border.slice(0, 3).join() === fgColor.slice(0, 3).join() && border[3] === 1) border = [...fgColor.slice(0, 3), 0.16];
    const fg = rgba(fgColor);
    const mutedColor = rgba(muted);
    const borderColor = rgba(border);
    return {
        dark: prefersDark(container),
        config: {
            background: 'transparent',
            font,
            title: { color: fg, subtitleColor: mutedColor, font },
            style: { 'guide-label': { fill: mutedColor, font }, 'guide-title': { fill: fg, font } },
            axis: { labelColor: mutedColor, titleColor: fg, gridColor: borderColor, domainColor: borderColor, tickColor: borderColor, labelFont: font, titleFont: font },
            legend: { labelColor: mutedColor, titleColor: fg, labelFont: font, titleFont: font },
            header: { labelColor: mutedColor, titleColor: fg, labelFont: font, titleFont: font },
            view: { stroke: borderColor },
        },
    };
}

// vega-embed options. Site options come first; the safety options always win.
export function chartOptions(container, theme = chartTheme(container)) {
    return {
        renderer: 'svg',
        ...(theme.dark ? { theme: 'dark' } : {}),
        config: theme.config,
        ...config.vegaOptions,
        actions: false,
        ast: true,
        ...(config.vegaInterpreter ? { expr: config.vegaInterpreter } : {}),
        loader: blockingLoader,
        tooltip: textTooltip(),
    };
}

const drawn = new Map();
let themeObserver = null;
let themeScheduled = false;

function themeKey(theme) {
    return JSON.stringify(theme);
}

function recheckThemes() {
    themeScheduled = false;
    for (const [container, state] of drawn) {
        if (!container.isConnected) { state.result?.finalize?.(); drawn.delete(container); continue; }
        if (themeKey(chartTheme(container)) !== state.key) drawChart(container);
    }
}

function scheduleThemeCheck() {
    if (themeScheduled) return;
    themeScheduled = true;
    (typeof requestAnimationFrame === 'function' ? requestAnimationFrame : setTimeout)(recheckThemes);
}

// Re-draws charts when the site switches themes on <html> or <body>, or the OS preference changes.
function watchThemes() {
    if (themeObserver || typeof MutationObserver !== 'function') return;
    themeObserver = new MutationObserver(scheduleThemeCheck);
    const options = { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] };
    themeObserver.observe(document.documentElement, options);
    if (document.body) themeObserver.observe(document.body, options);
    if (typeof matchMedia === 'function') matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', scheduleThemeCheck);
}

async function drawChart(container) {
    const previous = drawn.get(container);
    const generation = (previous?.generation ?? 0) + 1;
    drawn.set(container, { ...previous, generation });
    if (!previous) container.dataset.fwEmbedChartState = 'loading';
    try {
        const script = container.querySelector('script[type="application/json"]');
        const spec = chartSpec(JSON.parse(script?.textContent ?? 'null'));
        if (spec === null) throw new EmbedError('blocked', 'The chart has no usable inline specification.');
        const vegaEmbed = await loadVegaEmbed();
        const theme = chartTheme(container);
        // Attach before drawing so a container-width view can measure itself. A redraw keeps the old height.
        const old = container.querySelector('.fw-embed__chart-view');
        const view = document.createElement('div');
        view.className = 'fw-embed__chart-view';
        if (old) view.style.minHeight = `${old.offsetHeight}px`;
        previous?.result?.finalize?.();
        old?.remove();
        container.prepend(view);
        const result = await vegaEmbed(view, responsiveSpec(spec), chartOptions(container, theme));
        view.style.minHeight = '';
        if (drawn.get(container)?.generation !== generation) { result?.finalize?.(); return; }
        drawn.set(container, { generation, key: themeKey(theme), result });
        container.querySelector('.fw-embed__fallback')?.classList.add('fw-embed__sr-only');
        container.dataset.fwEmbedChartState = 'ready';
        watchThemes();
    } catch (error) {
        if (drawn.get(container)?.generation !== generation) return;
        drawn.delete(container);
        container.querySelector('.fw-embed__chart-view')?.remove();
        container.querySelector('.fw-embed__fallback')?.classList.remove('fw-embed__sr-only');
        container.dataset.fwEmbedChartState = 'error';
    }
}

// Draw every [data-fw-embed-chart] under root once it nears the viewport. vega loads on first use.
export function enhanceCharts(root = document, options = {}) {
    const charts = [...root.querySelectorAll('[data-fw-embed-chart]:not([data-fw-embed-chart-state])')];
    if (charts.length === 0) return 0;
    const lazy = options.lazy !== false && typeof IntersectionObserver === 'function';
    if (!lazy) {
        charts.forEach(drawChart);
        return charts.length;
    }
    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            observer.unobserve(entry.target);
            drawChart(entry.target);
        }
    }, { rootMargin: '200px' });
    for (const chart of charts) {
        chart.dataset.fwEmbedChartState = 'waiting';
        observer.observe(chart);
    }
    return charts.length;
}

const Base = typeof HTMLElement === 'undefined' ? class {} : HTMLElement;

// <framework-artifact src="https://framework.pub/d/..."> in light DOM, so site CSS applies.
// A stored document in a child <script type="application/json"> renders without fetching.
// Otherwise its children stay as the fallback until the document loads.
export class FrameworkArtifactElement extends Base {
    static get observedAttributes() { return ['src']; }

    connectedCallback() {
        if (this.getAttribute('data-fw-embed-state') !== 'ready') this.load();
    }

    attributeChangedCallback(name, previous, next) {
        if (previous !== null && previous !== next && this.isConnected) this.load();
    }

    // A stored document in a direct <script type="application/json"> child, if any.
    storedDocument() {
        const script = [...this.children].find((child) => child.tagName === 'SCRIPT' && child.getAttribute('type') === 'application/json');
        if (!script) return null;
        try {
            const doc = JSON.parse(script.textContent);
            return doc !== null && typeof doc === 'object' && doc.schema === 'framework.embed/v1' ? doc : null;
        } catch {
            return null;
        }
    }

    async load() {
        const token = Symbol('load');
        this._token = token;
        const stored = this.storedDocument();
        if (stored) {
            this.show(stored);
            return;
        }
        const src = this.getAttribute('src');
        if (src === null) return;
        this.setAttribute('data-fw-embed-state', 'loading');
        try {
            const doc = await fetchEmbed(src);
            if (this._token !== token) return;
            if (doc === null) { this.setAttribute('data-fw-embed-state', 'gone'); return; }
            this.show(doc);
        } catch (error) {
            if (this._token !== token) return;
            this.setAttribute('data-fw-embed-state', 'error');
            this.dispatchEvent(new CustomEvent('framework-embed:error', { detail: { error } }));
        }
    }

    show(doc) {
        mount(this, doc);
        this.setAttribute('data-fw-embed-state', 'ready');
        this.dispatchEvent(new CustomEvent('framework-embed:load', { detail: { doc } }));
    }
}

export function defineElement(name = 'framework-artifact') {
    if (typeof customElements === 'undefined' || customElements.get(name)) return false;
    customElements.define(name, class extends FrameworkArtifactElement {});
    return true;
}

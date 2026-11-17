import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { render, safeUrl, scriptJson } from '../src/render.js';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '../../spec/fixtures');
const load = (name) => JSON.parse(readFileSync(join(fixtures, name + '.json'), 'utf8'));
const dom = (html) => { const host = document.createElement('div'); host.innerHTML = html; return host; };

describe('escaping', () => {
    for (const name of ['hostile-model', 'hostile-page', 'hostile-image', 'hostile-vega']) {
        it(`${name} creates no executable or injected markup`, () => {
            const html = render(load(name));
            expect(html).not.toMatch(/<script>|<img src=x|<b>|javascript:|data:text/i);
            const host = dom(html);
            expect(host.querySelectorAll('script:not([type="application/json"]), b, iframe, object')).toHaveLength(0);
            for (const node of host.querySelectorAll('*')) {
                for (const attr of node.attributes) expect(attr.name.startsWith('on')).toBe(false);
            }
            for (const link of host.querySelectorAll('[href], [src]')) {
                expect(link.getAttribute('href') ?? link.getAttribute('src')).toMatch(/^https:\/\//);
            }
        });
    }

    it('keeps hostile text as text', () => {
        const host = dom(render(load('hostile-model')));
        expect(host.querySelector('.fw-embed__title').textContent).toBe('<script>alert(1)</script> & "><img src=x onerror=alert(1)>');
        expect(host.querySelector('tbody th').textContent).toBe('<script>alert(1)</script>');
    });

    it('cannot close the chart script element', () => {
        const doc = load('hostile-vega');
        const html = render(doc);
        expect(html.match(/<\/script>/g)).toHaveLength(1);
        const script = dom(html).querySelector('script[type="application/json"]');
        expect(JSON.parse(script.textContent)).toEqual(doc.spec);
    });

    it('accepts only http and https URLs', () => {
        expect(safeUrl('https://framework.pub/d/abc')).toBe('https://framework.pub/d/abc');
        for (const bad of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.com', '/relative', 'https://a b', 'https://x/"y', 'https://x/\\y', ' https://x', 'https://x\n', null, 5]) {
            expect(safeUrl(bad)).toBeNull();
        }
        expect(scriptJson({ a: '</script>\u2028&' })).toBe('{"a":"\\u003c/script\\u003e\\u2028\\u0026"}');
    });

    it('renders garbage input without throwing', () => {
        for (const bad of [null, 1, 'x', [], {}, { kind: 'model' }, { kind: 'model', data: { tables: [{ columns: [{ name: 'term' }], rows: [1, null, [{}]] }] } },
            { kind: 'table', data: { tables: 'no' } }, { kind: 'test', data: { test: { method: 'm', statistic: 'x', parameters: [null, { name: 1 }] } } },
            { kind: 'image', image: { url: 'https://framework.pub/i.svg', width: -1 } }, { kind: 'vega-lite', spec: [] }]) {
            expect(() => render(bad)).not.toThrow();
        }
        expect(render(null)).toBe('');
        expect(render({ kind: 'model' })).toContain('fw-embed--page');
    });
});

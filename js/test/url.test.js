import { describe, expect, it, vi } from 'vitest';
import { allowedOrigin, embedRequestUrl, isAllowedUrl, matchArtifactUrl } from '../src/url.js';
import { fetchEmbed, EmbedError } from '../src/browser.js';

describe('host allow-list', () => {
    it('allows framework.pub over https by default', () => {
        expect(allowedOrigin('https://framework.pub/d/abc')).toBe('https://framework.pub');
        expect(allowedOrigin('HTTPS://Framework.PUB:443/d/abc')).toBe('https://framework.pub');
    });

    it.each([
        'http://framework.pub/d/abc',
        'https://framework.pub.evil.com/d/abc',
        'https://evil.com/d/abc',
        'https://sub.framework.pub/d/abc',
        'https://framework.pub@evil.com/d/abc',
        'https://evil.com@framework.pub/d/abc',
        'https://user:pass@framework.pub/d/abc',
        'https://framework.pub:8443/d/abc',
        'https://framework.pub./d/abc',
        'https://framework%2epub/d/abc',
        'https://framework.pub\\@evil.com/d/abc',
        'https://127.0.0.1/d/abc',
        'https://2130706433/d/abc',
        'https://0x7f.0.0.1/d/abc',
        'https://[::1]/d/abc',
        'https://framework.pub /d/abc',
        'ftp://framework.pub/d/abc',
        '//framework.pub/d/abc',
        'javascript://framework.pub/%0aalert(1)',
        'http://localhost/d/abc',
        '',
    ])('rejects %s', (url) => {
        expect(isAllowedUrl(url)).toBe(false);
        expect(embedRequestUrl(url)).toBeNull();
    });

    it('allows http://localhost only when listed as an origin', () => {
        const hosts = ['framework.pub', 'http://localhost:8000'];
        expect(allowedOrigin('http://localhost:8000/d/abc', hosts)).toBe('http://localhost:8000');
        expect(isAllowedUrl('http://localhost/d/abc', hosts)).toBe(false);
        expect(isAllowedUrl('https://localhost:8000/d/abc', hosts)).toBe(false);
        expect(isAllowedUrl('http://127.0.0.1:8000/d/abc', ['http://127.0.0.1:8000'])).toBe(false);
    });
});

describe('artifact URLs', () => {
    it.each([
        ['https://framework.pub/d/aB3dE5fG7hJ9kL1m', { type: 'key', key: 'aB3dE5fG7hJ9kL1m', version: null }],
        ['https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3?x=1#y', { type: 'key', key: 'aB3dE5fG7hJ9kL1m', version: 3 }],
        ['https://framework.pub/@ada/admissions/model', { type: 'path', handle: 'ada', project: 'admissions', slug: 'model', version: null }],
        ['https://framework.pub/@ada/admissions/model/versions/12/', { type: 'path', handle: 'ada', slug: 'model', version: 12 }],
        ['https://framework.pub/api/embed/v1/artifacts/abc/versions/2', { type: 'embed', key: 'abc', version: 2 }],
        ['https://framework.pub/go/aqxh', { type: 'go', key: null, version: null, url: 'https://framework.pub/go/aqxh' }],
    ])('matches %s', (url, expected) => {
        expect(matchArtifactUrl(url)).toMatchObject(expected);
    });

    it.each(['https://framework.pub/', 'https://framework.pub/d/', 'https://framework.pub/d/a/b', 'https://framework.pub/@ada/../x/y',
        'https://framework.pub/d/abc/versions/0', 'https://framework.pub/r/abc', 'https://framework.pub#@evil.com/d/abc', 'https://framework.pub/go/', 'https://framework.pub/go/a/b', 'https://framework.pub/go/a_b'])('does not match %s', (url) => {
        expect(matchArtifactUrl(url)).toBeNull();
    });

    it('builds resolve and direct request URLs', () => {
        expect(embedRequestUrl('https://framework.pub/@ada/admissions/model/versions/2?utm=1'))
            .toBe('https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2F%40ada%2Fadmissions%2Fmodel%2Fversions%2F2');
        expect(embedRequestUrl('https://framework.pub/api/embed/v1/artifacts/abc')).toBe('https://framework.pub/api/embed/v1/artifacts/abc');
        expect(embedRequestUrl('https://framework.pub/go/aqxh')).toBe('https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2Fgo%2Faqxh');
    });

    it('keeps output=none and no other query', () => {
        expect(matchArtifactUrl('https://framework.pub/d/abc?utm=1&output=none#x').url).toBe('https://framework.pub/d/abc?output=none');
        expect(matchArtifactUrl('https://framework.pub/d/abc?output=all').url).toBe('https://framework.pub/d/abc');
        expect(embedRequestUrl('https://framework.pub/d/abc?output=none'))
            .toBe('https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2Fd%2Fabc%3Foutput%3Dnone');
        expect(embedRequestUrl('https://framework.pub/api/embed/v1/artifacts/abc/versions/2?output=none'))
            .toBe('https://framework.pub/api/embed/v1/artifacts/abc/versions/2?output=none');
    });
});

describe('fetchEmbed', () => {
    const answer = (status, body) => vi.fn(async () => ({ status, ok: status >= 200 && status < 300, json: async () => body }));

    it('fetches from the resolve endpoint without credentials or redirects', async () => {
        const fetch = answer(200, { schema: 'framework.embed/v1', kind: 'page' });
        const doc = await fetchEmbed('https://framework.pub/d/abc', { fetch });
        expect(doc.kind).toBe('page');
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2Fd%2Fabc');
        expect(init).toMatchObject({ credentials: 'omit', redirect: 'error' });
    });

    it('never requests a host that is not allowed', async () => {
        const fetch = answer(200, {});
        await expect(fetchEmbed('https://framework.pub@evil.com/d/abc', { fetch })).rejects.toBeInstanceOf(EmbedError);
        await expect(fetchEmbed('https://evil.com/d/abc', { fetch, hosts: ['framework.pub'] })).rejects.toThrow(/allowed host/);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('returns null when the artifact is gone', async () => {
        expect(await fetchEmbed('https://framework.pub/d/abc', { fetch: answer(404, {}) })).toBeNull();
    });

    it('rejects other documents', async () => {
        await expect(fetchEmbed('https://framework.pub/d/abc', { fetch: answer(200, { schema: 'other' }) })).rejects.toThrow(/framework.embed/);
        await expect(fetchEmbed('https://framework.pub/d/abc', { fetch: answer(500, {}) })).rejects.toThrow(/500/);
    });
});

// Host allow-list and artifact URL matching. SPEC.md, "Allowed hosts" and
// "Artifact URLs"; php/src/UrlMatcher.php implements the same rules.

export const DEFAULT_HOSTS = Object.freeze(['framework.pub']);

const URL_PATTERN = /^([a-z][a-z0-9+.-]*):\/\/([^/?#]*)([^?#]*)(\?[^#]*)?(#.*)?$/i;
const AUTHORITY_PATTERN = /^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::([0-9]{1,5}))?$/i;
const DEFAULT_PORTS = { http: '80', https: '443' };

const KEY = '([A-Za-z0-9_-]{1,64})';
const NAME = '([A-Za-z0-9_.-]{1,200})';
const VERSION = '([1-9][0-9]{0,8})';
const PATHS = [
    ['embed', new RegExp(`^/api/embed/v1/artifacts/${KEY}(?:/versions/${VERSION})?$`)],
    ['key', new RegExp(`^/d/${KEY}(?:/versions/${VERSION})?/?$`)],
    ['path', new RegExp(`^/@${NAME}/${NAME}/${NAME}(?:/versions/${VERSION})?/?$`)],
];

// scheme, lower-case host, and explicit non-default port of an absolute URL, or null.
function parse(url) {
    if (typeof url !== 'string' || url.length === 0 || url.length > 2048 || /[^\x21-\x7e]|\\/.test(url)) return null;
    const parts = URL_PATTERN.exec(url);
    if (!parts) return null;
    const scheme = parts[1].toLowerCase();
    if (scheme !== 'https' && scheme !== 'http') return null;
    const authority = AUTHORITY_PATTERN.exec(parts[2]);
    if (!authority) return null;
    const host = authority[1].toLowerCase();
    // A host whose last label is numeric is an IP address to a browser.
    if (/(^|\.)(\d+|0x[0-9a-f]*)$/.test(host)) return null;
    let port = authority[2] ?? null;
    if (port !== null) {
        const number = Number(port);
        if (number < 1 || number > 65535) return null;
        port = String(number) === DEFAULT_PORTS[scheme] ? null : String(number);
    }
    return { scheme, host, port, path: parts[3] || '/', query: parts[4] ?? '' };
}

// An allow-list entry is a bare host name (https only) or an origin such as http://localhost:8000.
function parseAllowed(entry) {
    if (typeof entry !== 'string') return null;
    if (!entry.includes('://')) entry = 'https://' + entry;
    const parsed = parse(entry);
    return parsed && parsed.path === '/' && parsed.query === '' ? parsed : null;
}

function originOf(parsed) {
    return `${parsed.scheme}://${parsed.host}${parsed.port === null ? '' : ':' + parsed.port}`;
}

// The URL's origin when its scheme, host, and port match an allowed host exactly, else null.
export function allowedOrigin(url, hosts = DEFAULT_HOSTS) {
    const parsed = parse(url);
    if (!parsed) return null;
    for (const entry of hosts) {
        const allowed = parseAllowed(entry);
        if (allowed && allowed.scheme === parsed.scheme && allowed.host === parsed.host && allowed.port === parsed.port) return originOf(parsed);
    }
    return null;
}

export function isAllowedUrl(url, hosts = DEFAULT_HOSTS) {
    return allowedOrigin(url, hosts) !== null;
}

// What an artifact URL on an allowed host points at, or null.
export function matchArtifactUrl(url, hosts = DEFAULT_HOSTS) {
    const origin = allowedOrigin(url, hosts);
    if (!origin) return null;
    const { path } = parse(url);
    for (const [type, pattern] of PATHS) {
        const m = pattern.exec(path);
        if (!m) continue;
        if (type === 'path') {
            if ([m[1], m[2], m[3]].some((part) => part === '.' || part === '..')) return null;
            return { type, origin, url: origin + path, handle: m[1], project: m[2], slug: m[3], key: null, version: m[4] ? Number(m[4]) : null };
        }
        return { type, origin, url: origin + path, handle: null, project: null, slug: null, key: m[1], version: m[2] ? Number(m[2]) : null };
    }
    return null;
}

// The embed API URL to request for an artifact or embed URL, or null when it is not allowed.
export function embedRequestUrl(url, hosts = DEFAULT_HOSTS) {
    const match = matchArtifactUrl(url, hosts);
    if (!match) return null;
    if (match.type === 'embed') return match.url;
    return `${match.origin}/api/embed/v1/resolve?url=${encodeURIComponent(match.url)}`;
}

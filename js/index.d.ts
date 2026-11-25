export interface EmbedDocument {
    schema: 'framework.embed/v1';
    key?: string;
    version?: number;
    latest_version?: number;
    kind: 'model' | 'table' | 'test' | 'code_snippet' | 'dag' | 'tree' | 'image' | 'vega-lite' | 'page' | string;
    title?: string | null;
    description?: string | null;
    alt?: string | null;
    url: string;
    embed_url?: string;
    published_at?: string | null;
    author?: { name: string; url?: string | null } | null;
    provider?: { name: string; url: string } | null;
    attribution?: { text: string; url: string } | null;
    data?: Record<string, unknown> | null;
    spec?: Record<string, unknown> | null;
    image?: { url: string; content_type?: string | null; width?: number | null; height?: number | null } | null;
    [field: string]: unknown;
}

export interface ArtifactUrlMatch {
    type: 'key' | 'path' | 'embed';
    origin: string;
    url: string;
    key: string | null;
    handle: string | null;
    project: string | null;
    slug: string | null;
    version: number | null;
}

export interface VegaScript { src: string; integrity?: string }

export interface Config {
    hosts: string[];
    vegaScripts: VegaScript[];
    vegaEmbed: ((el: HTMLElement, spec: object, options?: object) => Promise<unknown>) | null;
    vegaInterpreter: object | null;
    vegaOptions: Record<string, unknown>;
}

export const ROW_LIMIT: number;
export const FEATURE_LIMIT: number;
export const DEFAULT_HOSTS: readonly string[];
export const DEFAULT_VEGA_SCRIPTS: readonly VegaScript[];

/** Escaped HTML for an embed document. Pure; the PHP renderer returns the same bytes. */
export function render(doc: EmbedDocument): string;
export function escapeHtml(value: unknown): string;
export function safeUrl(value: unknown): string | null;
export function scriptJson(value: unknown): string;

/** places: 3 for data tables (default), 2 for model and test results. */
export function formatNumber(x: unknown, decimal?: boolean, places?: 2 | 3): string;
export function formatPValue(p: unknown): string;
export function formatPercent(x: unknown): string;
export function formatValue(value: unknown, decimal?: boolean, places?: 2 | 3): string;
export function formatFixed(x: number, places?: number): string;
export function formatScientific(x: number, significant?: number): string;

/** Hosts are bare names (https only) or origins such as "http://localhost:8000". */
export function allowedOrigin(url: string, hosts?: readonly string[]): string | null;
export function isAllowedUrl(url: string, hosts?: readonly string[]): boolean;
export function matchArtifactUrl(url: string, hosts?: readonly string[]): ArtifactUrlMatch | null;
export function embedRequestUrl(url: string, hosts?: readonly string[]): string | null;

export function configure(options?: Partial<Omit<Config, 'vegaScripts'>> & { vegaScripts?: Array<string | VegaScript> }): Config;
/** Resolves to null when the artifact is gone (404 or 410). Throws EmbedError for hosts that are not allowed. */
export function fetchEmbed(url: string, options?: { hosts?: readonly string[]; fetch?: typeof fetch; signal?: AbortSignal }): Promise<EmbedDocument | null>;
export function mount(element: Element, doc: EmbedDocument, options?: { lazy?: boolean }): Element | null;
export function enhanceCharts(root?: ParentNode, options?: { lazy?: boolean }): number;
/** Adds hover and placement to variable tips under root, and renumbers repeated tip ids. */
export function enhanceTips(root?: ParentNode): void;
/** Shows and wires the copy button on code snippets. */
export function enhanceCopy(root?: ParentNode): void;
export function enhanceOutput(root?: ParentNode): void;
export function loadVegaEmbed(): Promise<NonNullable<Config['vegaEmbed']>>;
/** Light or dark from the page: markers, then background luminance, then color-scheme, then the OS. */
export function prefersDark(element: Element): boolean;
/** A vega loader that rejects every load and sanitize call. */
export const blockingLoader: Readonly<{ load(): Promise<never>; sanitize(): Promise<never>; http(): Promise<never>; file(): Promise<never>; options: object }>;
/** True when the specification has a string `url` property anywhere. */
export function referencesUrl(spec: unknown): boolean;
/** A copy of the specification without `usermeta`, or null when it references a URL. */
export function chartSpec(spec: unknown): Record<string, unknown> | null;
/** The vega-embed options the bundle uses: blocking loader, AST interpreter, no actions, text tooltips. */
export function chartOptions(container: Element, theme?: { dark: boolean; config: Record<string, unknown> }): Record<string, unknown>;
/** [r, g, b, alpha] for a CSS color, or null. */
export function parseColor(value: string): [number, number, number, number] | null;
export function responsiveSpec(spec: Record<string, unknown>): Record<string, unknown>;
export function chartTheme(container: Element): { dark: boolean; config: Record<string, unknown> };
export function textTooltip(): (handler: unknown, event: MouseEvent | null, item: unknown, value: unknown) => void;
export function defineElement(name?: string): boolean;
/** Opens closed .fw-embed__details before printing and closes them afterward. */
export function expandDetailsForPrint(): void;
export class FrameworkArtifactElement extends HTMLElement {
    load(): Promise<void>;
}
export class EmbedError extends Error {
    code: 'not_allowed' | 'http' | 'invalid' | 'vega' | 'blocked';
}

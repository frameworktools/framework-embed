// framework.embed/v1 -> HTML. SPEC.md, "Renderer markup", defines the output;
// php/src/Renderer.php must produce the same bytes for every document.
import { DATA_PLACES, EMPTY, RESULT_PLACES, asciiCapitalize, asciiLower, dropsDigits, exp, formatExact, formatNumber, formatPValue, formatPercent, formatValue, isNumber, isPValueName } from './format.js';

export const ROW_LIMIT = 25;
export const FEATURE_LIMIT = 10;
// Text values longer than this many characters take a full row of the statistics grid.
export const WIDE_TEXT = 24;

const KINDS = new Set(['model', 'table', 'test', 'code_snippet', 'dag', 'image', 'vega-lite', 'page']);

const COLUMN_LABELS = {
    term: 'Term', estimate: 'Estimate', std_error: 'Std. error', statistic: 'Statistic', p_value: 'p-value',
    conf_low: 'CI lower', conf_high: 'CI upper', group: 'Group', variance: 'Variance', std_dev: 'Std. dev.',
    correlation: 'Correlation', feature: 'Feature', importance: 'Importance', edf: 'EDF', ref_df: 'Ref. df', df: 'df',
    rhat: 'R-hat', ess_bulk: 'Bulk ESS', ess_tail: 'Tail ESS', parameter: 'Parameter', prior: 'Prior', source: 'Source',
};

// Bayesian models summarize the posterior: the same columns, named for what they hold
const BAYESIAN_LABELS = { estimate: 'Posterior mean', std_error: 'Posterior SD', conf_low: 'Interval lower', conf_high: 'Interval upper' };

const METRIC_LABELS = {
    nobs: 'Observations', df_resid: 'Residual df', df_model: 'Model df', aic: 'AIC', bic: 'BIC',
    rsquared: 'R²', 'r.squared': 'R²', rsquared_adj: 'Adjusted R²', 'adj.r.squared': 'Adjusted R²', pseudo_rsquared: 'Pseudo R²',
    sigma: 'Residual SD', llf: 'Log likelihood', log_likelihood: 'Log likelihood', deviance: 'Deviance',
    'null.deviance': 'Null deviance', dispersion: 'Dispersion', converged: 'Converged', reml: 'REML',
    confidence_level: 'Confidence level', interval_method: 'Interval method', vcov_type: 'Standard errors',
    n_estimators: 'Trees', max_depth: 'Maximum depth', learning_rate: 'Learning rate', n_features: 'Features',
    importance_type: 'Importance type', objective: 'Objective', n_events: 'Events', concordance: 'Concordance',
    theta: 'Theta', command: 'Command',
    chains: 'Chains', draws: 'Draws', warmup: 'Warmup', sampler: 'Sampler', divergent_transitions: 'Divergent transitions',
    max_treedepth_hits: 'Max treedepth hits', bayes_r2: 'Bayes R²', elpd_loo: 'ELPD (LOO)', elpd_loo_se: 'ELPD (LOO) SE',
    looic: 'LOOIC', p_loo: 'p (LOO)', waic: 'WAIC', rhat_max: 'Max R-hat', acceptance_rate: 'Acceptance rate',
    efficiency_min: 'Efficiency (min)', efficiency_avg: 'Efficiency (avg)', efficiency_max: 'Efficiency (max)', dic: 'DIC',
    log_marginal_likelihood: 'Log marginal likelihood',
};

const INTERVAL_METHODS = { 'wald-t': 'Wald t', 'wald-normal': 'Wald normal', profile: 'Profile likelihood', bootstrap: 'Bootstrap' };

const MODEL_TYPES = {
    linear: 'Linear model', glm: 'Generalized linear model', mixed: 'Mixed model', survival: 'Survival model',
    ordinal: 'Ordinal model', multinomial: 'Multinomial model', additive: 'Additive model', fixed_effects: 'Fixed-effects model',
    quantile: 'Quantile regression', linear_ml: 'Linear model', tree_ensemble: 'Tree ensemble model',
};

const ALTERNATIVES = { 'two.sided': 'Two-sided', less: 'One-sided (less)', greater: 'One-sided (greater)' };

const COEFFICIENT_COLUMNS = new Set(['term', 'estimate', 'std_error', 'statistic', 'p_value', 'conf_low', 'conf_high', 'df',
    'rhat', 'ess_bulk', 'ess_tail', 'ratio_estimate', 'ratio_std_error', 'ratio_conf_low', 'ratio_conf_high']);
// Above this, R-hat says the chains may not have converged
const RHAT_LIMIT = 1.01;
// Effective sample sizes are counts of draws, shown whole
const WHOLE_COLUMNS = new Set(['ess_bulk', 'ess_tail']);

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ESCAPES[c]);

const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const list = (value) => (Array.isArray(value) ? value : []);
const text = (value) => (typeof value === 'string' && value !== '' ? value : null);

// http(s) URLs without whitespace, quotes, angle brackets, backslashes, or control characters.
export function safeUrl(value) {
    return typeof value === 'string' && /^https?:\/\/[^\x00-\x20\x7f"'<>\\`]+$/i.test(value) ? value : null;
}

// JSON for a <script type="application/json"> element: no "<", ">", "&", or line separators.
export function scriptJson(value) {
    return JSON.stringify(value).replace(/[<>&\u2028\u2029]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));
}

export function render(doc) {
    if (!isObject(doc)) return '';
    tips = { prefix: tipPrefix(doc), count: 0 };
    bayesian = doc.kind === 'model' && isObject(doc.data) && isObject(doc.data.model) && doc.data.model.inference === 'bayesian';
    let kind = KINDS.has(doc.kind) ? doc.kind : 'page';
    let body = null;
    if (kind === 'model') body = renderModel(doc);
    else if (kind === 'table') body = renderTableKind(doc);
    else if (kind === 'test') body = renderTest(doc);
    else if (kind === 'code_snippet') body = renderSnippet(doc);
    else if (kind === 'dag') body = renderDag(doc);
    else if (kind === 'image') body = renderImage(doc);
    else if (kind === 'vega-lite') body = renderChart(doc);
    if (body === null) {
        kind = 'page';
        body = renderPage(doc);
    }
    let open = `<figure class="fw-embed fw-embed--${kind}" data-fw-embed-kind="${kind}"`;
    if (text(doc.key) !== null) open += ` data-fw-embed-key="${escapeHtml(doc.key)}"`;
    if (Number.isInteger(doc.version)) open += ` data-fw-embed-version="${doc.version}"`;
    // The caption heads the embed: the title and the link back. The description and author follow the
    // content. A page's link card already shows all three, so its caption (the link back alone) follows the card.
    const caption = renderCaption(doc, kind);
    const inner = [`<div class="fw-embed__body">`, body, '</div>'];
    if (kind === 'page') return [open + '>', ...inner, caption, '</figure>', ''].join('\n');
    return [open + '>', caption, ...inner, ...renderAbout(doc), '</figure>', ''].join('\n');
}

function renderCaption(doc, kind) {
    const parts = ['<figcaption class="fw-embed__caption">'];
    if (kind !== 'page' && text(doc.title) !== null) parts.push(`<span class="fw-embed__title">${escapeHtml(doc.title)}</span>`);
    const attribution = isObject(doc.attribution) ? doc.attribution : {};
    const credit = escapeHtml(text(attribution.text) ?? 'Framework');
    const href = safeUrl(attribution.url) ?? safeUrl(doc.url);
    parts.push(href === null
        ? `<span class="fw-embed__credit">${credit}</span>`
        : `<a class="fw-embed__credit" href="${escapeHtml(href)}" target="_blank" rel="noopener">${credit}</a>`);
    parts.push('</figcaption>');
    return parts.join('\n');
}

// The description and author, under the content.
function renderAbout(doc) {
    const parts = [];
    if (text(doc.description) !== null) parts.push(`<span class="fw-embed__description">${escapeHtml(doc.description)}</span>`);
    const author = isObject(doc.author) ? doc.author : {};
    if (text(author.name) !== null) {
        const href = safeUrl(author.url);
        const name = escapeHtml(author.name);
        parts.push(`<span class="fw-embed__author">${href === null ? name : `<a href="${escapeHtml(href)}">${name}</a>`}</span>`);
    }
    return parts.length === 0 ? [] : ['<div class="fw-embed__about">', ...parts, '</div>'];
}

// --- variable tips ---
// A term or column with something to tell gets a button that opens a popover with the variable's
// label, its reference level, and a summary. The popover opens on click or tap without JavaScript;
// the browser bundle adds hover and places it beside the button. Ids are unique within a page
// for one copy of an embed; the bundle renumbers repeats.

let tips = { prefix: 'fw-embed-tip-', count: 0 };
// Whether the model being rendered was fit by posterior sampling
let bayesian = false;

function tipPrefix(doc) {
    const key = text(doc.key);
    if (key === null || !/^[A-Za-z0-9_-]+$/.test(key)) return 'fw-embed-tip-';
    return `fw-embed-tip-${key}-${Number.isInteger(doc.version) ? doc.version : 0}-`;
}

function tip(label, blocks) {
    const id = `${tips.prefix}${++tips.count}`;
    return `<button type="button" class="fw-embed__term" popovertarget="${id}">${escapeHtml(label)}</button>`
        + `<span class="fw-embed__tip" id="${id}" popover="">${blocks.join('')}</span>`;
}

function tipBlock(name, label, detail) {
    let html = `<span class="fw-embed__tip-name">${escapeHtml(name)}</span>`;
    if (label !== null) html += `<span class="fw-embed__tip-label">${escapeHtml(label)}</span>`;
    if (detail !== '') html += `<span class="fw-embed__tip-detail">${escapeHtml(detail)}</span>`;
    return `<span class="fw-embed__tip-block">${html}</span>`;
}

function count(x) {
    return formatNumber(x);
}

function plural(n, one, many) {
    return `${count(n)} ${n === 1 ? one : many}`;
}

// Sentences about a variable or column: its spread or common values, then its rows.
function summaryDetail(type, summary, levels, level, reference) {
    const sentences = [];
    const s = isObject(summary) ? summary : {};
    const stat = (x) => formatValue(x, false, RESULT_PLACES);
    if (level !== null) {
        if (reference !== null && reference !== level) sentences.push(`Compared with ${reference}.`);
        const top = list(s.top).find((entry) => isObject(entry) && isLevel(entry.value, level) && Number.isInteger(entry.count));
        if (top !== undefined && Number.isInteger(s.count)) sentences.push(`${count(top.count)} of ${plural(s.count, 'row', 'rows')}.`);
        return sentences.join(' ');
    }
    if (type === 'number') {
        const parts = [];
        if (isNumber(s.mean)) parts.push(`mean ${stat(s.mean)}`);
        if (isNumber(s.sd)) parts.push(`SD ${stat(s.sd)}`);
        if (isNumber(s.min) && isNumber(s.max)) {
            // Both ends with the same decimals: 1.00 to 6.90, not 1 to 6.90
            const decimal = !Number.isInteger(s.min) || !Number.isInteger(s.max);
            parts.push(`range ${formatValue(s.min, decimal, RESULT_PLACES)} to ${formatValue(s.max, decimal, RESULT_PLACES)}`);
        }
        if (parts.length > 0) sentences.push(asciiCapitalize(parts.join(', ')) + '.');
    } else if (type === 'date' || type === 'datetime') {
        if (typeof s.min === 'string' && typeof s.max === 'string') sentences.push(`From ${s.min} to ${s.max}.`);
    } else {
        if (levels.length > 0) sentences.push(`${plural(levels.length, 'level', 'levels')}${reference !== null ? `, compared with ${reference}` : ''}.`);
        else if (Number.isInteger(s.distinct)) sentences.push(`${plural(s.distinct, 'distinct value', 'distinct values')}.`);
        const top = list(s.top).filter((entry) => isObject(entry) && Number.isInteger(entry.count)).slice(0, 3);
        if (top.length > 0) sentences.push(`Most common: ${top.map((entry) => `${formatValue(entry.value)} (${count(entry.count)})`).join(', ')}.`);
    }
    if (Number.isInteger(s.count)) {
        const missing = Number.isInteger(s.missing) && s.missing > 0 ? `, ${count(s.missing)} missing` : '';
        sentences.push(`${plural(s.count, 'row', 'rows')}${missing}.`);
    }
    return sentences.join(' ');
}

// Whether a top value is the level: a string as it is, an integer in digits, a boolean as true or false
// in any ASCII case (R writes TRUE, Python True).
function isLevel(value, level) {
    if (typeof value === 'string') return value === level;
    if (typeof value === 'boolean') return asciiLower(level) === String(value);
    return Number.isInteger(value) && String(value) === level;
}

function modelVariables(doc) {
    const variables = new Map();
    for (const variable of list(doc.data?.variables)) {
        if (isObject(variable) && text(variable.name) !== null && !variables.has(variable.name)) variables.set(variable.name, variable);
    }
    return variables;
}

// A coefficient term, with a tip when the model says which variables it comes from.
function termCell(label, doc) {
    const entry = list(doc.data?.terms).find((term) => isObject(term) && term.term === label);
    if (entry === undefined) return escapeHtml(label);
    const variables = modelVariables(doc);
    const blocks = [];
    for (const part of list(entry.parts)) {
        const variable = isObject(part) ? variables.get(part.variable) : undefined;
        if (variable === undefined) return escapeHtml(label);
        const level = text(part.level);
        const levels = list(variable.levels).filter((value) => typeof value === 'string');
        const name = level === null ? variable.name : `${variable.name} = ${level}`;
        const variableLabel = text(variable.label);
        blocks.push(tipBlock(name, variableLabel === variable.name ? null : variableLabel,
            summaryDetail(variable.type, variable.summary, levels, level, text(variable.reference))));
    }
    return blocks.length === 0 ? escapeHtml(label) : tip(label, blocks);
}

// A data table's column header, with a tip when the column carries a label or summary.
function columnHeader(column) {
    const label = columnLabel(column, false);
    const detail = summaryDetail(column.type === 'number' || column.type === 'date' || column.type === 'datetime' ? column.type : 'string', column.summary, [], null, null);
    const named = label !== column.name;
    if (detail === '' && !named) return escapeHtml(label);
    return tip(label, [tipBlock(column.name, named ? label : null, detail)]);
}

// --- data kinds -------------------------------------------------------------
// Models and tests show their main results, random effects included; the rest goes in a closed <details>.

function usableTables(data) {
    return list(data.tables).filter((table) => isObject(table) && Array.isArray(table.rows)
        && Array.isArray(table.columns) && table.columns.length > 0
        && table.columns.every((column) => isObject(column) && text(column.name) !== null));
}

function usableMetrics(data) {
    return list(data.metrics).filter((metric) => isObject(metric) && text(metric.name) !== null);
}

// A section the artifact's owner turned off on Framework Cloud: data.display.{name} is false.
function hidden(data, name) {
    return isObject(data.display) && data.display[name] === false;
}

function details(label, blocks) {
    if (blocks.length === 0) return [];
    return [`<details class="fw-embed__details">`, `<summary>${label}</summary>`, ...blocks, '</details>'];
}

function isCoefficientTable(table, index) {
    return (table.role === 'coefficients' || ((table.role === null || table.role === undefined) && index === 0))
        && columnIndex(table, 'term') >= 0 && columnIndex(table, 'estimate') >= 0;
}

// A Stata `command` metric with a string value. It stands in for a formula and never appears in the statistics.
function isCommand(metric) {
    return metric.name === 'command' && text(metric.value) !== null;
}

function renderModel(doc) {
    const data = doc.data;
    if (!isObject(data)) return null;
    const tables = usableTables(data);
    const metrics = usableMetrics(data);
    let formula = text(data.formula);
    if (tables.length === 0 && metrics.length === 0 && formula === null) return null;
    const command = metrics.find(isCommand);
    if (formula === null && command !== undefined) formula = command.value;
    const stats = metrics.filter((metric) => !isCommand(metric));
    const model = isObject(data.model) ? data.model : {};
    const parts = [];
    const meta = modelMeta(model);
    if (meta !== null) parts.push(`<p class="fw-embed__meta">${metaHtml(meta, text(model.response))}</p>`);
    if (formula !== null) parts.push(`<p class="fw-embed__formula"><code>${escapeHtml(formula)}</code></p>`);
    let level = null;
    for (const metric of metrics) if (metric.name === 'confidence_level' && isNumber(metric.value)) { level = metric.value; break; }
    const coefficients = tables.map((table, index) => isCoefficientTable(table, index));
    const importance = coefficients.includes(true) ? -1 : tables.findIndex((table) => table.role === 'importance');
    const visible = [];
    const rest = [];
    tables.forEach((table, index) => {
        if (coefficients[index]) {
            visible.push(renderCoefficients(table, model, level, doc));
        } else if (index === importance) {
            const continued = !hidden(data, 'details') && shownRows(table, FEATURE_LIMIT, ROW_LIMIT).length > 0;
            visible.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, FEATURE_LIMIT, continued ? 'Model details' : null));
            if (continued) rest.push(renderTable(table, true, true, doc, RESULT_PLACES, FEATURE_LIMIT, ROW_LIMIT, null));
        } else if (table.role === 'random_effects') {
            // Variance components are as much a result as the fixed effects; a posterior's read like its coefficients
            visible.push(bayesian && columnIndex(table, 'term') >= 0 && columnIndex(table, 'estimate') >= 0
                ? coefficientTable(table, doc, 'random-effects', posteriorColumns(table, level, 'Posterior mean', false))
                : renderTable(table, true, true, doc, RESULT_PLACES, 0, ROW_LIMIT, null));
        } else {
            rest.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, ROW_LIMIT, null));
        }
    });
    if (stats.length > 0) rest.push(renderMetrics(stats));
    if (visible.length === 0) return [...parts, ...rest].join('\n');
    if (bayesian) visible.push(...samplingWarnings(tables, metrics));
    // The model type, response, and formula are details: the coefficients show the terms, and the post names the model
    if (hidden(data, 'details')) return visible.join('\n');
    return [...visible, ...details('Model details', [...parts, ...rest])].join('\n');
}

function renderTableKind(doc) {
    const data = doc.data;
    if (!isObject(data)) return null;
    const tables = usableTables(data);
    if (tables.length === 0) return null;
    const parts = tables.map((table) => renderTable(table, tables.length > 1, false, doc, DATA_PLACES, 0, ROW_LIMIT, null));
    const metrics = usableMetrics(data);
    if (metrics.length > 0) parts.push(renderMetrics(metrics));
    return parts.join('\n');
}

// A test's interval; a missing end is unbounded.
function intervalText(low, high) {
    const a = isNumber(low) ? formatNumber(low, true, RESULT_PLACES) : '-∞';
    const b = isNumber(high) ? formatNumber(high, true, RESULT_PLACES) : '∞';
    const full = (x, text) => (isNumber(x) ? formatExact(x) : text);
    return titled(`${a} to ${b}`, dropsDigits(low, a) || dropsDigits(high, b) ? `${full(low, a)} to ${full(high, b)}` : null);
}

// Text or {text, title} as plain parts, to build a longer value from shown numbers.
function pieces(value) {
    return typeof value === 'string' ? { text: value, title: value } : value;
}

function renderTest(doc) {
    const data = doc.data;
    if (!isObject(data) || !isObject(data.test) || text(data.test.method) === null) return null;
    const test = data.test;
    const result = (value) => shown(value, formatNumber(value, false, RESULT_PLACES));
    const main = [];
    if (isObject(test.statistic) && text(test.statistic.name) !== null) main.push([test.statistic.name, result(test.statistic.value), true]);
    if (isNumber(test.p_value)) main.push(['p-value', shown(test.p_value, formatPValue(test.p_value)), true]);
    const estimates = named(test.estimates);
    const interval = isObject(test.conf_int) && isNumber(test.conf_int.level) ? test.conf_int : null;
    const ci = interval === null ? null : `${formatPercent(interval.level)} CI`;
    if (estimates.length === 1 && interval !== null) {
        const estimate = result(estimates[0].value);
        const range = intervalText(interval.low, interval.high);
        const rounded = typeof estimate !== 'string' || typeof range !== 'string';
        const text = `${pieces(estimate).text} (${pieces(range).text})`;
        main.push([`${asciiCapitalize(estimates[0].name)} (${ci})`, titled(text, rounded ? `${pieces(estimate).title} (${pieces(range).title})` : null), true]);
    } else {
        for (const entry of estimates) main.push([asciiCapitalize(entry.name), result(entry.value), true]);
        if (interval !== null) main.push([ci, intervalText(interval.low, interval.high), true]);
    }
    const extra = [];
    if (typeof test.alternative === 'string' && Object.hasOwn(ALTERNATIVES, test.alternative)) extra.push(['Alternative', ALTERNATIVES[test.alternative], false]);
    for (const entry of named(test.parameters)) extra.push([entry.name, result(entry.value), true]);
    for (const entry of named(test.null_value)) extra.push([`Null value: ${entry.name}`, result(entry.value), true]);
    const rest = [];
    if (extra.length > 0) rest.push(renderPairs(extra));
    for (const table of usableTables(data)) rest.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, ROW_LIMIT, null));
    const metrics = usableMetrics(data);
    if (metrics.length > 0) rest.push(renderMetrics(metrics));
    const parts = [`<p class="fw-embed__method">${escapeHtml(test.method)}</p>`];
    if (main.length > 0) parts.push(renderPairs(main));
    return [...parts, ...(hidden(data, 'details') ? [] : details('Test details', rest))].join('\n');
}

function named(value) {
    return list(value).filter((entry) => isObject(entry) && text(entry.name) !== null);
}

const FAMILY_NAMES = { gaussian: 'Gaussian', tweedie: 'Tweedie', poisson: 'Poisson' };
const LINK_NAMES = { sqrt: 'square-root', cloglog: 'complementary log-log' };

function familyName(family) {
    const key = asciiLower(family);
    return Object.hasOwn(FAMILY_NAMES, key) ? FAMILY_NAMES[key] : family;
}

function linkName(link) {
    const key = asciiLower(link);
    return Object.hasOwn(LINK_NAMES, key) ? LINK_NAMES[key] : link;
}

function familyLink(family, link) {
    if (family !== null && link !== null) return `${familyName(family)} family, ${linkName(link)} link`;
    if (family !== null) return `${familyName(family)} family`;
    if (link !== null) return `${linkName(link)} link`;
    return null;
}

// The common name for a model, from its type, family, link, and exponentiate label.
// The family and link appear only when the name does not imply them. Null for an unknown combination.
function modelName(model) {
    const lower = (value) => (text(value) === null ? null : asciiLower(value));
    const type = lower(model.type);
    const family = lower(model.family);
    const link = lower(model.link);
    const withLink = (name, implied) => (link === null || link === implied ? name : `${name} (${linkName(link)} link)`);
    if (type === 'linear') return 'Ordinary least squares regression';
    if (type === 'glm') {
        if (family === 'gaussian' && (link === null || link === 'identity')) return 'Ordinary least squares regression';
        if (family === 'binomial') {
            if (link === null || link === 'logit') return 'Logistic regression';
            if (link === 'probit') return 'Probit regression';
            if (link === 'cloglog') return 'Complementary log-log regression';
            return `Binomial regression (${linkName(link)} link)`;
        }
        if (family === 'poisson') return withLink('Poisson regression', 'log');
        if (family === 'negative binomial') return withLink('Negative binomial regression', 'log');
        if (family === 'gamma') return link === null ? 'Gamma regression' : `Gamma regression (${linkName(link)} link)`;
        if (family !== null && family.startsWith('quasi')) {
            const rest = family.slice(5).replace(/^[- ]+/, '');
            return rest === '' ? 'Quasi-likelihood regression' : `Quasi-${rest === 'poisson' ? 'Poisson' : rest} regression`;
        }
        return null;
    }
    if (type === 'mixed') {
        if (family === null || family === 'gaussian') return withLink('Linear mixed model', 'identity');
        if (family === 'binomial') {
            if (link === null || link === 'logit') return 'Logistic mixed model';
            if (link === 'probit') return 'Probit mixed model';
            return `Binomial mixed model (${linkName(link)} link)`;
        }
        if (family === 'poisson') return withLink('Poisson mixed model', 'log');
        if (family === 'negative binomial') return withLink('Negative binomial mixed model', 'log');
        if (family === 'gamma') return link === null ? 'Gamma mixed model' : `Gamma mixed model (${linkName(link)} link)`;
        return null;
    }
    if (type === 'survival') return text(model.exponentiate) === 'Hazard ratio' ? 'Cox proportional hazards model' : 'Parametric survival model';
    if (type === 'ordinal') {
        if (link === null || link === 'logit') return 'Ordinal logistic regression';
        if (link === 'probit') return 'Ordinal probit regression';
        return `Ordinal regression (${linkName(link)} link)`;
    }
    if (type === 'multinomial') return 'Multinomial logistic regression';
    if (type === 'additive') {
        const detail = family === null || family === 'gaussian' ? null : familyLink(family, link);
        return detail === null ? 'Generalized additive model' : `Generalized additive model (${detail})`;
    }
    if (type === 'fixed_effects') {
        if (family === 'poisson') return 'Fixed-effects Poisson regression';
        if (family === 'binomial' && (link === null || link === 'logit')) return 'Fixed-effects logistic regression';
        return 'Fixed-effects regression';
    }
    if (type === 'quantile') return 'Quantile regression';
    if (type === 'linear_ml') return 'Linear model';
    if (type === 'tree_ensemble') return 'Tree ensemble model';
    return null;
}

// The meta line, with the outcome (always last) set as a variable name.
function metaHtml(meta, response) {
    if (response === null || !meta.endsWith(response)) return `<span>${escapeHtml(meta)}</span>`;
    return `<span>${escapeHtml(meta.slice(0, meta.length - response.length))}</span><code class="fw-embed__var">${escapeHtml(response)}</code>`;
}

function modelMeta(model) {
    const parts = [];
    const name = modelName(model);
    if (name !== null) {
        parts.push(name);
    } else {
        // Unknown combinations: the type label, then the family and link as sent.
        if (text(model.type) !== null && model.type !== 'other') parts.push(Object.hasOwn(MODEL_TYPES, model.type) ? MODEL_TYPES[model.type] : model.type);
        const detail = familyLink(text(model.family), text(model.link));
        if (detail !== null) parts.push(detail);
    }
    if (model.inference === 'bayesian') {
        // Proper nouns keep their capital: Bayesian Poisson regression
        if (parts.length === 0) parts.push('Bayesian model');
        else parts[0] = `Bayesian ${/^(Poisson|Cox)\b/.test(parts[0]) ? parts[0] : asciiLower(parts[0].slice(0, 1)) + parts[0].slice(1)}`;
    }
    if (text(model.response) !== null) parts.push(`Outcome: ${model.response}`);
    return parts.length > 0 ? asciiCapitalize(parts.join(' · ')) : null;
}

function columnIndex(table, name) {
    return table.columns.findIndex((column) => column.name === name);
}

function cell(row, index) {
    return Array.isArray(row) && index >= 0 && index < row.length ? row[index] : null;
}

function shownRows(table, start, end) {
    return table.rows.slice(start, end).filter((row) => Array.isArray(row));
}

function isDecimalColumn(rows, index) {
    return rows.some((row) => isNumber(cell(row, index)) && !Number.isInteger(cell(row, index)));
}

function formatCell(value, column, decimal, places) {
    if (isNumber(value) && isPValueName(column.name)) return shown(value, formatPValue(value));
    if (isNumber(value) && WHOLE_COLUMNS.has(column.name)) return shown(value, formatNumber(Math.round(value)));
    if (isNumber(value) && column.name === 'rhat') return shown(value, formatValue(value, true, RESULT_PLACES));
    return shown(value, formatValue(value, decimal, places));
}

function columnLabel(column, modelLabels) {
    if (text(column.label) !== null) return column.label;
    if (modelLabels && bayesian && Object.hasOwn(BAYESIAN_LABELS, column.name)) return BAYESIAN_LABELS[column.name];
    if (modelLabels && Object.hasOwn(COLUMN_LABELS, column.name)) return COLUMN_LABELS[column.name];
    return column.name;
}

function th(label, numeric) {
    return `<th scope="col"${numeric ? ' class="fw-embed__num"' : ''}>${escapeHtml(label)}</th>`;
}

// A cell's text, or {text, title} when the text rounds its number: the title holds the full value.
function td(value, numeric) {
    const { text, title } = typeof value === 'string' ? { text: value, title: null } : value;
    return `<td${numeric ? ' class="fw-embed__num"' : ''}${title === null ? '' : ` title="${escapeHtml(title)}"`}>${escapeHtml(text)}</td>`;
}

function titled(text, title) {
    return title === null ? text : { text, title };
}

// A number as shown, with its full value as the title when the text drops digits.
function shown(x, text) {
    return titled(text, dropsDigits(x, text) ? formatExact(x) : null);
}

// "a to b", titled with both full values when either end drops digits.
function shownRange(a, b, format) {
    const low = format(a);
    const high = format(b);
    const full = (x, text) => (isNumber(x) ? formatExact(x) : text);
    return titled(`${low} to ${high}`, dropsDigits(a, low) || dropsDigits(b, high) ? `${full(a, low)} to ${full(b, high)}` : null);
}

function openTable(table, showCaption, modifier) {
    const title = text(table.title) ?? 'Table';
    const lines = [`<div class="fw-embed__scroll" role="region" tabindex="0" aria-label="${escapeHtml(title)}">`,
        `<table class="fw-embed__table${modifier ? ' fw-embed__table--' + modifier : ''}">`];
    if (showCaption) lines.push(`<caption>${escapeHtml(title)}</caption>`);
    return lines;
}

function numberSpan(count) {
    return `<span class="fw-embed__num">${escapeHtml(formatNumber(count))}</span>`;
}

// `rest` names the <details> that holds the remaining rows, or null to link to the artifact.
// `start` is the index of the first row shown; a continuation names its row range.
function tableNotes(table, rows, start, doc, rest) {
    const notes = [];
    const total = Number.isInteger(table.row_count) ? table.row_count : table.rows.length;
    const columnTotal = Number.isInteger(table.column_count) ? table.column_count : table.columns.length;
    const href = safeUrl(doc.url);
    const link = href === null ? '' : ` <a href="${escapeHtml(href)}">View full table</a>`;
    if (start > 0) {
        const last = start + rows.length;
        notes.push(`<p class="fw-embed__note">Showing rows ${numberSpan(start + 1)} to ${numberSpan(last)} of ${numberSpan(total)}.${total > last ? link : ''}</p>`);
    } else {
        const parts = [];
        if (total > rows.length) parts.push(`${numberSpan(rows.length)} of ${numberSpan(total)} rows`);
        if (rest === null && columnTotal > table.columns.length) parts.push(`${numberSpan(table.columns.length)} of ${numberSpan(columnTotal)} columns`);
        if (parts.length > 0) notes.push(`<p class="fw-embed__note">Showing ${parts.join(' and ')}.${rest !== null ? ` The rest are in ${rest}.` : link}</p>`);
    }
    if (rest === null && text(table.precision_note) !== null) notes.push(`<p class="fw-embed__note">${escapeHtml(table.precision_note)}</p>`);
    return notes;
}

// Rows start to end (exclusive) of a data table. Decimal columns are judged on the first 25 rows,
// so a table split across the figure and its details formats both parts alike.
function renderTable(table, showCaption, modelLabels, doc, places, start, end, rest) {
    const rows = shownRows(table, start, end);
    const sample = shownRows(table, 0, ROW_LIMIT);
    const columns = table.columns;
    const decimal = columns.map((_, index) => isDecimalColumn(sample, index));
    const lines = openTable(table, showCaption, null);
    lines.push('<thead><tr>' + columns.map((column) => modelLabels
        ? th(columnLabel(column, true), column.type === 'number')
        : `<th scope="col"${column.type === 'number' ? ' class="fw-embed__num"' : ''}>${columnHeader(column)}</th>`).join('') + '</tr></thead>');
    lines.push('<tbody>');
    for (const row of rows) {
        lines.push('<tr>' + columns.map((column, index) => td(formatCell(cell(row, index), column, decimal[index], places), column.type === 'number')).join('') + '</tr>');
    }
    lines.push('</tbody>', '</table>', '</div>');
    return [...lines, ...tableNotes(table, rows, start, doc, rest)].join('\n');
}

// A coefficient table with the given columns: [header, numeric, (row) => text].
function coefficientTable(table, doc, modifier, columns) {
    const rows = shownRows(table, 0, ROW_LIMIT);
    const term = columnIndex(table, 'term');
    const lines = openTable(table, true, modifier);
    lines.push('<thead><tr>' + th(columnLabel(table.columns[term], true), false) + columns.map(([header, numeric]) => th(header, numeric)).join('') + '</tr></thead>', '<tbody>');
    for (const row of rows) {
        lines.push('<tr>' + `<th scope="row">${termCell(formatValue(cell(row, term)), doc)}</th>` + columns.map(([, numeric, value]) => td(value(row), numeric)).join('') + '</tr>');
    }
    lines.push('</tbody>', '</table>', '</div>');
    return [...lines, ...tableNotes(table, rows, 0, doc, null)].join('\n');
}

// Columns outside the standard set, such as a multinomial outcome, with their own formatting.
function extraColumns(table) {
    const rows = shownRows(table, 0, ROW_LIMIT);
    const columns = [];
    table.columns.forEach((column, index) => {
        if (COEFFICIENT_COLUMNS.has(column.name)) return;
        const decimal = isDecimalColumn(rows, index);
        columns.push([columnLabel(column, true), column.type === 'number', (row) => formatCell(cell(row, index), column, decimal, RESULT_PLACES)]);
    });
    return columns;
}

function intervalLabel(level) {
    // A credible interval is just the interval: readers needn't learn CrI
    const name = bayesian ? 'interval' : 'CI';
    return level === null ? name : `${formatPercent(level)} ${name}`;
}

function decimalCell(index) {
    return (row) => shown(cell(row, index), formatValue(cell(row, index), true, RESULT_PLACES));
}


// The log-scale label for each ratio, used as the estimate header and the switch's second option.
const LOG_SCALES = { 'Odds ratio': 'Log odds', 'Hazard ratio': 'Log hazard', 'Rate ratio': 'Log rate', 'Incidence rate ratio': 'Log rate', 'Time ratio': 'Log time' };

// The coefficient table. A model with an exponentiated scale (odds, hazard, rate ratios) gets the
// same table twice, on the model scale and the ratio scale, and a switch between them, as Stata's
// `or` option does: the estimate and interval are exponentiated, the std. error is the delta-method
// one, exp(b) * se, and the statistic and p-value are unchanged. The switch is radio buttons in a
// form of their own, so it works without JavaScript and each embed's buttons are separate. Where a
// host strips the form controls, only the ratio view shows.
function renderCoefficients(table, model, level, doc) {
    let ratio = text(model.exponentiate);
    // A posterior's ratio scale comes from the exponentiated draws, which only the package has
    if (bayesian && columnIndex(table, 'ratio_estimate') < 0) ratio = null;
    if (ratio === null) return coefficientTable(table, doc, 'coefficients', coefficientColumns(table, level, bayesian ? 'Posterior mean' : 'Estimate', false));
    const logScale = Object.hasOwn(LOG_SCALES, ratio) ? LOG_SCALES[ratio] : 'Coefficient';
    return [
        '<form class="fw-embed__scales">',
        `<div class="fw-embed__switch" role="radiogroup" aria-label="Scale">`,
        `<label><input type="radio" name="fw-embed-scale" value="ratio" checked=""> ${escapeHtml(ratio)}</label>`,
        `<label><input type="radio" name="fw-embed-scale" value="model"> ${escapeHtml(logScale)}</label>`,
        '</div>',
        '<div class="fw-embed__scale fw-embed__scale--ratio">', coefficientTable(table, doc, 'coefficients', coefficientColumns(table, level, ratio, true)), '</div>',
        '<div class="fw-embed__scale fw-embed__scale--model">', coefficientTable(table, doc, 'coefficients', coefficientColumns(table, level, logScale, false)), '</div>',
        '</form>',
    ].join('\n');
}

// The coefficient columns, on the model scale or exponentiated.
function coefficientColumns(table, level, estimateLabel, exponentiated) {
    const at = (name) => columnIndex(table, name);
    if (bayesian) return posteriorColumns(table, level, estimateLabel, exponentiated);
    const estimate = at('estimate');
    const low = at('conf_low');
    const high = at('conf_high');
    // On the ratio scale the shown number is exp(value)
    const scaled = (value) => (exponentiated && isNumber(value) ? exp(value) : value);
    const text = (value) => formatValue(value, true, RESULT_PLACES);
    const columns = extraColumns(table);
    columns.push([estimateLabel, true, (row) => shown(scaled(cell(row, estimate)), text(scaled(cell(row, estimate))))]);
    if (at('std_error') >= 0) {
        const index = at('std_error');
        columns.push(['Std. error', true, (row) => {
            const se = cell(row, index);
            const b = cell(row, estimate);
            const value = exponentiated && isNumber(se) && isNumber(b) ? exp(b) * se : se;
            return shown(value, formatValue(value, true, RESULT_PLACES));
        }]);
    }
    if (at('statistic') >= 0) columns.push(['Statistic', true, decimalCell(at('statistic'))]);
    if (at('p_value') >= 0) columns.push(['p-value', true, pValueCell(at('p_value'))]);
    if (low >= 0 && high >= 0) {
        columns.push([intervalLabel(level), true, (row) => {
            const a = cell(row, low);
            const b = cell(row, high);
            return a === null && b === null ? EMPTY : shownRange(scaled(a), scaled(b), text);
        }]);
    }
    return columns;
}

// A posterior summary: the package's own ratio-scale columns on the ratio scale, then the
// sampling diagnostics, with R-hat flagged above its limit.
function posteriorColumns(table, level, estimateLabel, exponentiated) {
    const at = (name) => columnIndex(table, exponentiated ? `ratio_${name}` : name);
    const text = (value) => formatValue(value, true, RESULT_PLACES);
    const decimal = (index) => (row) => shown(cell(row, index), text(cell(row, index)));
    const columns = extraColumns(table);
    if (at('estimate') >= 0) columns.push([estimateLabel, true, decimal(at('estimate'))]);
    if (at('std_error') >= 0) columns.push(['Posterior SD', true, decimal(at('std_error'))]);
    const low = at('conf_low');
    const high = at('conf_high');
    if (low >= 0 && high >= 0) {
        columns.push([intervalLabel(level), true, (row) => {
            const a = cell(row, low);
            const b = cell(row, high);
            return a === null && b === null ? EMPTY : shownRange(a, b, text);
        }]);
    }
    const rhat = columnIndex(table, 'rhat');
    if (rhat >= 0) columns.push(['R-hat', true, decimal(rhat)]);
    for (const name of ['ess_bulk', 'ess_tail']) {
        const index = columnIndex(table, name);
        if (index >= 0) columns.push([COLUMN_LABELS[name], true, (row) => (isNumber(cell(row, index)) ? shown(cell(row, index), formatNumber(Math.round(cell(row, index)))) : formatValue(cell(row, index)))]);
    }
    return columns;
}

// Warnings a reader should see beside a posterior: unconverged chains and divergent transitions.
function samplingWarnings(tables, metrics) {
    let high = 0;
    for (const table of tables) {
        const index = columnIndex(table, 'rhat');
        if (index < 0) continue;
        for (const row of list(table.rows)) if (isNumber(cell(row, index)) && cell(row, index) > RHAT_LIMIT) high += 1;
    }
    const notes = [];
    if (high > 0) notes.push(`R-hat above 1.01 for ${plural(high, 'parameter', 'parameters')}: the chains may not have converged.`);
    const divergent = metrics.find((metric) => metric.name === 'divergent_transitions');
    if (divergent !== undefined && isNumber(divergent.value) && divergent.value > 0) {
        notes.push(`${plural(divergent.value, 'divergent transition', 'divergent transitions')}: the posterior may be biased.`);
    }
    return notes.map((note) => `<p class="fw-embed__note fw-embed__warning">${escapeHtml(note)}</p>`);
}

function pValueCell(index) {
    return (row) => (isNumber(cell(row, index)) ? shown(cell(row, index), formatPValue(cell(row, index))) : formatValue(cell(row, index)));
}


function metricLabel(name) {
    if (bayesian && name === 'confidence_level') return 'Credible level';
    if (Object.hasOwn(METRIC_LABELS, name)) return METRIC_LABELS[name];
    if (name.startsWith('n_groups.') && name.length > 9) return `Groups: ${name.slice(9)}`;
    return asciiCapitalize(name.replace(/[_.]/g, ' '));
}

function metricValue(name, value) {
    if (name === 'confidence_level' && isNumber(value)) return formatPercent(value);
    if (name === 'interval_method' && typeof value === 'string' && Object.hasOwn(INTERVAL_METHODS, value)) return INTERVAL_METHODS[value];
    if (isNumber(value) && isPValueName(name)) return shown(value, formatPValue(value));
    return shown(value, formatValue(value, false, RESULT_PLACES));
}

function renderMetrics(metrics) {
    return renderPairs(metrics.map((metric) => [metricLabel(metric.name), metricValue(metric.name, metric.value), isNumber(metric.value)]));
}

// Label and value pairs. A value is text, or {text, title} when it rounds its number.
function renderPairs(pairs) {
    return ['<dl class="fw-embed__stats">', ...pairs.map(([term, value, numeric]) => {
        const { text, title } = typeof value === 'string' ? { text: value, title: null } : value;
        const tip = title === null ? '' : ` title="${escapeHtml(title)}"`;
        if (numeric) return `<div><dt>${escapeHtml(term)}</dt><dd class="fw-embed__num"${tip}>${escapeHtml(text)}</dd></div>`;
        const wide = [...text].length > WIDE_TEXT ? ' fw-embed__stat--wide' : '';
        return `<div class="fw-embed__stat--text${wide}"><dt>${escapeHtml(term)}</dt><dd${tip}>${escapeHtml(text)}</dd></div>`;
    }), '</dl>'].join('\n');
}

// --- code snippets ----------------------------------------------------------
// Code and the text it printed, as a notebook showed them. Cloud colors the code and styles the
// output; each block arrives as plain `text` and as `lines` of [classes, text] segments.

const SNIPPET_LANGUAGES = { r: 'R', python: 'Python', stata: 'Stata' };
const SNIPPET_BLOCKS = { code: 'Code', output: 'Output', message: 'Message', warning: 'Warning', error: 'Error' };
const SNIPPET_LINKS = { notebook: 'Full notebook', model: 'Model' };
const known = (names, value) => typeof value === 'string' && Object.hasOwn(names, value);

// A segment's classes, each prefixed: "res bold" -> "fw-t-res fw-t-bold". Tokens outside [a-z0-9-] are dropped.
function segmentClasses(classes) {
    return classes.split(' ').filter((token) => /^[a-z0-9-]+$/.test(token)).map((token) => `fw-t-${token}`).join(' ');
}

// A block's lines as segments, or null when they don't fit the shape.
function snippetLines(block) {
    if (!Array.isArray(block.lines)) return null;
    for (const line of block.lines) {
        if (!Array.isArray(line)) return null;
        for (const segment of line) {
            if (!Array.isArray(segment) || segment.length !== 2 || typeof segment[0] !== 'string' || typeof segment[1] !== 'string') return null;
        }
    }
    return block.lines;
}

// A block's HTML lines: styled segments, or its plain text when the segments are missing or malformed.
function snippetHtml(block) {
    const lines = snippetLines(block);
    if (lines === null) return typeof block.text === 'string' ? block.text.split('\n').map(escapeHtml) : null;
    return lines.map((line) => line.map(([classes, value]) => {
        const names = segmentClasses(classes);
        return names === '' ? escapeHtml(value) : `<span class="${names}">${escapeHtml(value)}</span>`;
    }).join(''));
}

function snippetHead(source, output, shown) {
    const parts = ['<div class="fw-embed__snippet-head">',
        `<span class="fw-embed__lang">${known(SNIPPET_LANGUAGES, source.language) ? SNIPPET_LANGUAGES[source.language] : 'Code'}</span>`];
    const file = text(source.file);
    const name = [file === null ? null : file.split('/').pop(), text(source.label)].filter((part) => part !== null && part !== '');
    if (name.length > 0) parts.push(`<span class="fw-embed__source">${escapeHtml(name.join(' · '))}</span>`);
    const range = list(source.lines);
    if (range.length === 2 && Number.isInteger(range[0]) && Number.isInteger(range[1]) && range[0] >= 1 && range[1] >= range[0]) {
        const label = range[0] === range[1] ? `Line ${count(range[0])}` : `Lines ${count(range[0])}–${count(range[1])}`;
        const href = safeUrl(source.permalink);
        parts.push(href === null
            ? `<span class="fw-embed__lines">${label}</span>`
            : `<a class="fw-embed__lines" href="${escapeHtml(href)}" target="_blank" rel="noopener">${label}</a>`);
    }
    if (output) parts.push(`<button type="button" class="fw-embed__copy fw-embed__toggle" data-fw-output hidden>${shown ? 'Hide output' : 'Show output'}</button>`);
    parts.push('<button type="button" class="fw-embed__copy" data-fw-copy hidden>Copy code</button>', '</div>');
    return parts.join('\n');
}

function snippetBlock(block, shown) {
    const html = snippetHtml(block);
    if (html === null) return null;
    if (block.type === 'code') return `<pre class="fw-embed__code"><code>${html.join('\n')}</code></pre>`;
    const truncated = isObject(block.truncated) && Number.isInteger(block.truncated.lines) && block.truncated.lines > 0 ? block.truncated.lines : 0;
    const parts = [`<div class="fw-embed__block fw-embed__block--${block.type}"${shown ? '' : ' hidden'}>`];
    const body = [`<pre class="fw-embed__output"><code>${html.join('\n')}</code></pre>`];
    if (truncated > 0) body.push(`<p class="fw-embed__more">${plural(truncated, 'more line', 'more lines')} not shown.</p>`);
    if (block.folded === true) {
        parts.push('<details class="fw-embed__fold">', `<summary>${SNIPPET_BLOCKS[block.type]}, ${plural(html.length + truncated, 'line', 'lines')}</summary>`, ...body, '</details>');
    } else {
        parts.push(...body);
    }
    parts.push('</div>');
    return parts.join('\n');
}

function renderSnippet(doc) {
    const data = doc.data;
    if (!isObject(data) || !isObject(data.snippet)) return null;
    const source = isObject(data.source) ? data.source : {};
    // display.output false: the output starts hidden, and the toggle shows it
    const shown = !(isObject(data.display) && data.display.output === false);
    const blocks = [];
    let code = false;
    let output = false;
    for (const block of list(data.snippet.blocks)) {
        if (!isObject(block) || !known(SNIPPET_BLOCKS, block.type)) continue;
        const html = snippetBlock(block, shown);
        if (html === null) continue;
        code = code || block.type === 'code';
        output = output || block.type !== 'code';
        blocks.push(html);
    }
    if (!code) return null;
    const parts = ['<div class="fw-embed__snippet">', snippetHead(source, output, shown), ...blocks];
    const links = list(isObject(data.display) ? data.display.links : null).flatMap((link) => {
        if (!isObject(link) || !known(SNIPPET_LINKS, link.rel)) return [];
        const href = safeUrl(link.url);
        return href === null ? [] : [`<a href="${escapeHtml(href)}" target="_blank" rel="noopener">${SNIPPET_LINKS[link.rel]}</a>`];
    });
    if (links.length > 0) parts.push(`<p class="fw-embed__links">${links.join(' ')}</p>`);
    parts.push('</div>');
    return parts.join('\n');
}

// --- DAGs -----------------------------------------------------------------
// Framework Cloud lays the graph out: renderers write the boxes and the edge paths it sends.

const DAG_ROLES = { exposure: 'Exposure', outcome: 'Outcome', adjusted: 'Adjusted', latent: 'Latent', selected: 'Selected' };
const DAG_LABEL_CHARS = 32;
const DAG_PATH = /^[MLQZ0-9 .-]{1,400}$/;
const DAG_CODE = { r: 'R', python: 'Python', stata: 'Stata' };

const dagSize = (value) => Number.isInteger(value) && value >= 0 && value <= 100000;
const dagPath = (value) => typeof value === 'string' && DAG_PATH.test(value);

// At most 32 code points, cut to 31 with trailing spaces dropped and an ellipsis added.
function dagShown(label) {
    const points = Array.from(label);
    return points.length > DAG_LABEL_CHARS ? points.slice(0, DAG_LABEL_CHARS - 1).join('').replace(/ +$/, '') + '…' : label;
}

function dagNodes(graph) {
    return list(graph.nodes).flatMap((node) => {
        if (!isObject(node) || text(node.name) === null || ![node.x, node.y, node.width, node.height].every(dagSize)) return [];
        const roles = Object.keys(DAG_ROLES).filter((role) => list(node.roles).includes(role));
        return [{ name: node.name, label: text(node.label) ?? node.name, roles, x: node.x, y: node.y, width: node.width, height: node.height }];
    });
}

function dagSvg(doc, graph, nodes) {
    const label = text(doc.alt) ?? text(doc.title) ?? 'Causal diagram';
    const lines = [`<svg class="fw-embed__dag-graph" viewBox="0 0 ${graph.width} ${graph.height}" width="${graph.width}" height="${graph.height}"`
        + ` style="min-width: ${Math.floor(graph.width * 3 / 4)}px" role="img" aria-label="${escapeHtml(label)}">`];
    for (const edge of list(graph.edges)) {
        if (!isObject(edge) || !dagPath(edge.path)) continue;
        lines.push(`<path class="fw-embed__dag-edge${edge.type === '<->' ? ' fw-embed__dag-edge--bidirected' : ''}" d="${edge.path}"/>`);
        for (const head of list(edge.heads)) {
            if (dagPath(head)) lines.push(`<path class="fw-embed__dag-head" d="${head}"/>`);
        }
    }
    for (const node of nodes) {
        const shown = dagShown(node.label);
        const classes = ['fw-embed__dag-node', ...node.roles.map((role) => `fw-embed__dag-node--${role}`)].join(' ');
        lines.push(`<g class="${classes}">${shown === node.label ? '' : `<title>${escapeHtml(node.label)}</title>`}`
            + `<rect x="${node.x}" y="${node.y}" width="${node.width}" height="${node.height}" rx="6"/>`
            + `<text x="${node.x + Math.floor(node.width / 2)}" y="${node.y + Math.floor(node.height / 2)}">${escapeHtml(shown)}</text></g>`);
    }
    lines.push('</svg>');
    return lines.join('\n');
}

function dagNames(names, labels) {
    return names.map((name) => `<code class="fw-embed__var">${escapeHtml(labels.get(name) ?? name)}</code>`).join(', ');
}

const dagNameList = (value) => (Array.isArray(value) && value.every((name) => typeof name === 'string') ? value : null);

function dagAnalysis(analysis, labels) {
    if (!isObject(analysis)) return [];
    const blocks = [];
    const exposures = dagNameList(analysis.exposures) ?? [];
    const outcomes = dagNameList(analysis.outcomes) ?? [];
    if (exposures.length > 0 && outcomes.length > 0 && Array.isArray(analysis.adjustment_sets)) {
        const sets = analysis.adjustment_sets.map(dagNameList).filter((set) => set !== null);
        const effect = analysis.effect === 'direct' ? 'direct' : 'total';
        blocks.push(`<p class="fw-embed__note">Minimal sufficient adjustment sets for the ${effect} effect of ${dagNames(exposures, labels)} on ${dagNames(outcomes, labels)}:</p>`);
        if (sets.length === 0) blocks.push('<p class="fw-embed__dag-result">No set of observed variables identifies the effect.</p>');
        else if (sets.length === 1 && sets[0].length === 0) blocks.push('<p class="fw-embed__dag-result">No adjustment is needed.</p>');
        else blocks.push('<ul class="fw-embed__dag-list">', ...sets.map((set) => `<li>{ ${dagNames(set, labels)} }</li>`), '</ul>');
    }
    const implications = list(analysis.implications).filter((entry) => isObject(entry) && typeof entry.x === 'string'
        && typeof entry.y === 'string' && dagNameList(entry.given) !== null);
    if (implications.length > 0) {
        blocks.push('<p class="fw-embed__note">Testable implications:</p>', '<ul class="fw-embed__dag-list">',
            ...implications.map((entry) => `<li>${dagNames([entry.x], labels)} ⫫ ${dagNames([entry.y], labels)}`
                + `${entry.given.length > 0 ? ` | ${dagNames(entry.given, labels)}` : ''}</li>`), '</ul>');
    }
    return blocks;
}

function dagCode(label, block) {
    if (!isObject(block)) return null;
    const html = snippetHtml(block);
    if (html === null) return null;
    return ['<div class="fw-embed__snippet">', '<div class="fw-embed__snippet-head">', `<span class="fw-embed__lang">${label}</span>`,
        '<button type="button" class="fw-embed__copy" data-fw-copy hidden>Copy code</button>', '</div>',
        `<pre class="fw-embed__code"><code>${html.join('\n')}</code></pre>`, '</div>'].join('\n');
}

function renderDag(doc) {
    const data = doc.data;
    if (!isObject(data) || !isObject(data.dag)) return null;
    const graph = data.dag;
    if (!dagSize(graph.width) || !dagSize(graph.height) || graph.width === 0 || graph.height === 0) return null;
    const nodes = dagNodes(graph);
    if (nodes.length === 0) return null;
    const labels = new Map(nodes.map((node) => [node.name, node.label]));
    const parts = ['<div class="fw-embed__dag">', dagSvg(doc, graph, nodes)];
    const roles = Object.keys(DAG_ROLES).filter((role) => nodes.some((node) => node.roles.includes(role)));
    if (roles.length > 0 && !hidden(data, 'legend')) {
        parts.push(`<p class="fw-embed__dag-legend">${roles.map((role) => `<span class="fw-embed__dag-key fw-embed__dag-key--${role}">${DAG_ROLES[role]}</span>`).join(' ')}</p>`);
    }
    parts.push('</div>');
    const code = isObject(data.code) && known(DAG_CODE, data.code.language) ? dagCode(DAG_CODE[data.code.language], data.code) : null;
    parts.push(...details('Analysis', dagAnalysis(data.analysis, labels)),
        ...details('Code', [dagCode('DAGitty', data.dagitty), code].filter((panel) => panel !== null)));
    return parts.join('\n');
}

// --- other kinds ------------------------------------------------------------

function renderImage(doc) {
    return imageMedia(doc.image, text(doc.alt) ?? text(doc.title) ?? '');
}

// An image object as a figure: null without a safe URL.
function imageMedia(value, alt) {
    const image = isObject(value) ? value : null;
    const src = image === null ? null : safeUrl(image.url);
    if (src === null) return null;
    // Vector figures scale to the figure's width; raster figures stop at their natural size.
    const type = text(image.content_type);
    const vector = type !== null && asciiLower(type).startsWith('image/svg+xml');
    let tag = `<img class="fw-embed__img${vector ? ' fw-embed__img--vector' : ''}" src="${escapeHtml(src)}" alt="${escapeHtml(alt)}" loading="lazy" decoding="async"`;
    if (Number.isInteger(image.width) && image.width > 0 && Number.isInteger(image.height) && image.height > 0) {
        tag += ` width="${image.width}" height="${image.height}"`;
        // A tall vector figure narrows so its height stays within 80vh.
        if (vector) tag += ` style="max-width: min(100%, calc(80vh * ${image.width} / ${image.height}))"`;
    }
    return `<div class="fw-embed__media">${tag}></div>`;
}

// The specification for the script to draw, over a fallback: the chart's static image when it has a safe
// one, otherwise its alt text and a link back. The drawn chart replaces the fallback.
function renderChart(doc) {
    if (!isObject(doc.spec)) return null;
    const alt = text(doc.alt) ?? text(doc.title) ?? 'Chart';
    const href = safeUrl(doc.url);
    const fallback = imageMedia(doc.image, alt)
        ?? `<p class="fw-embed__fallback">${escapeHtml(alt)}${href === null ? '' : ` <a href="${escapeHtml(href)}">View chart</a>`}</p>`;
    return [
        '<div class="fw-embed__chart" data-fw-embed-chart>',
        `<script type="application/json">${scriptJson(doc.spec)}</script>`,
        fallback,
        '</div>',
    ].join('\n');
}

function renderPage(doc) {
    const title = escapeHtml(text(doc.title) ?? 'Untitled');
    const href = safeUrl(doc.url);
    const lines = ['<div class="fw-embed__card">',
        href === null ? `<span class="fw-embed__card-title">${title}</span>` : `<a class="fw-embed__card-title" href="${escapeHtml(href)}">${title}</a>`];
    if (text(doc.description) !== null) lines.push(`<p class="fw-embed__card-description">${escapeHtml(doc.description)}</p>`);
    const author = isObject(doc.author) ? doc.author : {};
    if (text(author.name) !== null) {
        const authorHref = safeUrl(author.url);
        const name = escapeHtml(author.name);
        lines.push(`<p class="fw-embed__author">${authorHref === null ? name : `<a href="${escapeHtml(authorHref)}">${name}</a>`}</p>`);
    }
    lines.push('</div>');
    return lines.join('\n');
}

// framework.embed/v1 -> HTML. SPEC.md, "Renderer markup", defines the output;
// php/src/Renderer.php must produce the same bytes for every document.
import { DATA_PLACES, EMPTY, RESULT_PLACES, asciiCapitalize, asciiLower, exp, formatNumber, formatPValue, formatPercent, formatValue, isNumber, isPValueName } from './format.js';

export const ROW_LIMIT = 25;
export const FEATURE_LIMIT = 10;
// Text values longer than this many characters take a full row of the statistics grid.
export const WIDE_TEXT = 24;

const KINDS = new Set(['model', 'table', 'test', 'image', 'vega-lite', 'page']);

const COLUMN_LABELS = {
    term: 'Term', estimate: 'Estimate', std_error: 'Std. error', statistic: 'Statistic', p_value: 'p-value',
    conf_low: 'CI lower', conf_high: 'CI upper', group: 'Group', variance: 'Variance', std_dev: 'Std. dev.',
    correlation: 'Correlation', feature: 'Feature', importance: 'Importance', edf: 'EDF', ref_df: 'Ref. df', df: 'df',
};

const METRIC_LABELS = {
    nobs: 'Observations', df_resid: 'Residual df', df_model: 'Model df', aic: 'AIC', bic: 'BIC',
    rsquared: 'R²', 'r.squared': 'R²', rsquared_adj: 'Adjusted R²', 'adj.r.squared': 'Adjusted R²', pseudo_rsquared: 'Pseudo R²',
    sigma: 'Residual std. dev.', llf: 'Log likelihood', log_likelihood: 'Log likelihood', deviance: 'Deviance',
    'null.deviance': 'Null deviance', dispersion: 'Dispersion', converged: 'Converged', reml: 'REML',
    confidence_level: 'Confidence level', interval_method: 'Interval method', vcov_type: 'Standard errors',
    n_estimators: 'Trees', max_depth: 'Maximum depth', learning_rate: 'Learning rate', n_features: 'Features',
    importance_type: 'Importance type', objective: 'Objective', n_events: 'Events', concordance: 'Concordance',
    theta: 'Theta', command: 'Command',
};

const INTERVAL_METHODS = { 'wald-t': 'Wald t', 'wald-normal': 'Wald normal', profile: 'Profile likelihood', bootstrap: 'Bootstrap' };

const MODEL_TYPES = {
    linear: 'Linear model', glm: 'Generalized linear model', mixed: 'Mixed model', survival: 'Survival model',
    ordinal: 'Ordinal model', multinomial: 'Multinomial model', additive: 'Additive model', fixed_effects: 'Fixed-effects model',
    quantile: 'Quantile regression', linear_ml: 'Linear model', tree_ensemble: 'Tree ensemble',
};

const ALTERNATIVES = { 'two.sided': 'Two-sided', less: 'One-sided (less)', greater: 'One-sided (greater)' };

const COEFFICIENT_COLUMNS = new Set(['term', 'estimate', 'std_error', 'statistic', 'p_value', 'conf_low', 'conf_high', 'df']);

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
    let kind = KINDS.has(doc.kind) ? doc.kind : 'page';
    let body = null;
    if (kind === 'model') body = renderModel(doc);
    else if (kind === 'table') body = renderTableKind(doc);
    else if (kind === 'test') body = renderTest(doc);
    else if (kind === 'image') body = renderImage(doc);
    else if (kind === 'vega-lite') body = renderChart(doc);
    if (body === null) {
        kind = 'page';
        body = renderPage(doc);
    }
    let open = `<figure class="fw-embed fw-embed--${kind}" data-fw-embed-kind="${kind}"`;
    if (text(doc.key) !== null) open += ` data-fw-embed-key="${escapeHtml(doc.key)}"`;
    if (Number.isInteger(doc.version)) open += ` data-fw-embed-version="${doc.version}"`;
    return [open + '>', `<div class="fw-embed__body">`, body, '</div>', renderCaption(doc, kind), '</figure>', ''].join('\n');
}

function renderCaption(doc, kind) {
    const parts = ['<figcaption class="fw-embed__caption">'];
    if (kind !== 'page') {
        if (text(doc.title) !== null) parts.push(`<span class="fw-embed__title">${escapeHtml(doc.title)}</span>`);
        if (text(doc.description) !== null) parts.push(`<span class="fw-embed__description">${escapeHtml(doc.description)}</span>`);
        const author = isObject(doc.author) ? doc.author : {};
        if (text(author.name) !== null) {
            const href = safeUrl(author.url);
            const name = escapeHtml(author.name);
            parts.push(`<span class="fw-embed__author">${href === null ? name : `<a href="${escapeHtml(href)}">${name}</a>`}</span>`);
        }
    }
    const attribution = isObject(doc.attribution) ? doc.attribution : {};
    const credit = escapeHtml(text(attribution.text) ?? 'Framework');
    const href = safeUrl(attribution.url) ?? safeUrl(doc.url);
    parts.push(href === null
        ? `<span class="fw-embed__credit">${credit}</span>`
        : `<a class="fw-embed__credit" href="${escapeHtml(href)}" target="_blank" rel="noopener">${credit}</a>`);
    parts.push('</figcaption>');
    return parts.join('\n');
}

// --- data kinds -------------------------------------------------------------
// Models and tests show their main results; the rest goes in a closed <details>.

function usableTables(data) {
    return list(data.tables).filter((table) => isObject(table) && Array.isArray(table.rows)
        && Array.isArray(table.columns) && table.columns.length > 0
        && table.columns.every((column) => isObject(column) && text(column.name) !== null));
}

function usableMetrics(data) {
    return list(data.metrics).filter((metric) => isObject(metric) && text(metric.name) !== null);
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
    if (meta !== null) parts.push(`<p class="fw-embed__meta">${escapeHtml(meta)}</p>`);
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
            const continued = shownRows(table, FEATURE_LIMIT, ROW_LIMIT).length > 0;
            visible.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, FEATURE_LIMIT, continued ? 'Model details' : null));
            if (continued) rest.push(renderTable(table, true, true, doc, RESULT_PLACES, FEATURE_LIMIT, ROW_LIMIT, null));
        } else {
            rest.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, ROW_LIMIT, null));
        }
    });
    if (stats.length > 0) rest.push(renderMetrics(stats));
    if (visible.length === 0) return [...parts, ...rest].join('\n');
    return [...parts, ...visible, ...details('Model details', rest)].join('\n');
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

function intervalText(low, high) {
    return `${isNumber(low) ? formatNumber(low, true, RESULT_PLACES) : '-∞'} to ${isNumber(high) ? formatNumber(high, true, RESULT_PLACES) : '∞'}`;
}

function renderTest(doc) {
    const data = doc.data;
    if (!isObject(data) || !isObject(data.test) || text(data.test.method) === null) return null;
    const test = data.test;
    const result = (value) => formatNumber(value, false, RESULT_PLACES);
    const main = [];
    if (isObject(test.statistic) && text(test.statistic.name) !== null) main.push([test.statistic.name, result(test.statistic.value), true]);
    if (isNumber(test.p_value)) main.push(['p-value', formatPValue(test.p_value), true]);
    const estimates = named(test.estimates);
    const interval = isObject(test.conf_int) && isNumber(test.conf_int.level) ? test.conf_int : null;
    const ci = interval === null ? null : `${formatPercent(interval.level)} CI`;
    if (estimates.length === 1 && interval !== null) {
        main.push([`${asciiCapitalize(estimates[0].name)} (${ci})`, `${result(estimates[0].value)} (${intervalText(interval.low, interval.high)})`, true]);
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
    return [...parts, ...details('Test details', rest)].join('\n');
}

function named(value) {
    return list(value).filter((entry) => isObject(entry) && text(entry.name) !== null);
}

function familyLink(family, link) {
    if (family !== null && link !== null) return `${family} family, ${link} link`;
    if (family !== null) return `${family} family`;
    if (link !== null) return `${link} link`;
    return null;
}

// The common name for a model, from its type, family, link, and exponentiate label.
// The family and link appear only when the name does not imply them. Null for an unknown combination.
function modelName(model) {
    const lower = (value) => (text(value) === null ? null : asciiLower(value));
    const type = lower(model.type);
    const family = lower(model.family);
    const link = lower(model.link);
    const withLink = (name, implied) => (link === null || link === implied ? name : `${name} (${link} link)`);
    if (type === 'linear') return 'Ordinary least squares regression';
    if (type === 'glm') {
        if (family === 'gaussian' && (link === null || link === 'identity')) return 'Ordinary least squares regression';
        if (family === 'binomial') {
            if (link === null || link === 'logit') return 'Logistic regression';
            if (link === 'probit') return 'Probit regression';
            if (link === 'cloglog') return 'Complementary log-log regression';
            return `Binomial regression (${link} link)`;
        }
        if (family === 'poisson') return withLink('Poisson regression', 'log');
        if (family === 'negative binomial') return withLink('Negative binomial regression', 'log');
        if (family === 'gamma') return link === null ? 'Gamma regression' : `Gamma regression (${link} link)`;
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
            return `Binomial mixed model (${link} link)`;
        }
        if (family === 'poisson') return withLink('Poisson mixed model', 'log');
        if (family === 'negative binomial') return withLink('Negative binomial mixed model', 'log');
        if (family === 'gamma') return link === null ? 'Gamma mixed model' : `Gamma mixed model (${link} link)`;
        return null;
    }
    if (type === 'survival') return text(model.exponentiate) === 'Hazard ratio' ? 'Cox proportional hazards model' : 'Parametric survival model';
    if (type === 'ordinal') {
        if (link === null || link === 'logit') return 'Ordinal logistic regression';
        if (link === 'probit') return 'Ordinal probit regression';
        return `Ordinal regression (${link} link)`;
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
    if (type === 'linear_ml') return 'Linear model (machine learning)';
    if (type === 'tree_ensemble') return 'Tree ensemble';
    return null;
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
    if (text(model.response) !== null) parts.push(`Response: ${model.response}`);
    return parts.length > 0 ? parts.join(' · ') : null;
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
    if (isNumber(value) && isPValueName(column.name)) return formatPValue(value);
    return formatValue(value, decimal, places);
}

function columnLabel(column, modelLabels) {
    if (text(column.label) !== null) return column.label;
    if (modelLabels && Object.hasOwn(COLUMN_LABELS, column.name)) return COLUMN_LABELS[column.name];
    return column.name;
}

function th(label, numeric) {
    return `<th scope="col"${numeric ? ' class="fw-embed__num"' : ''}>${escapeHtml(label)}</th>`;
}

function td(value, numeric) {
    return `<td${numeric ? ' class="fw-embed__num"' : ''}>${escapeHtml(value)}</td>`;
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
    const link = href === null ? '' : ` <a href="${escapeHtml(href)}">View the full table</a>`;
    if (start > 0) {
        const last = start + rows.length;
        notes.push(`<p class="fw-embed__note">Showing rows ${numberSpan(start + 1)} to ${numberSpan(last)} of ${numberSpan(total)}.${total > last ? link : ''}</p>`);
    } else {
        const parts = [];
        if (total > rows.length) parts.push(`${numberSpan(rows.length)} of ${numberSpan(total)} rows`);
        if (rest === null && columnTotal > table.columns.length) parts.push(`${numberSpan(table.columns.length)} of ${numberSpan(columnTotal)} columns`);
        if (parts.length > 0) notes.push(`<p class="fw-embed__note">Showing ${parts.join(' and ')}.${rest !== null ? ` More in ${rest}.` : link}</p>`);
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
    lines.push('<thead><tr>' + columns.map((column) => th(columnLabel(column, modelLabels), column.type === 'number')).join('') + '</tr></thead>');
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
    lines.push('<thead><tr>' + th(columnLabel(table.columns[term], true), false) + columns.map(([header, numeric]) => (Array.isArray(header) ? ratioTh(header) : th(header, numeric))).join('') + '</tr></thead>', '<tbody>');
    for (const row of rows) {
        lines.push('<tr>' + `<th scope="row">${escapeHtml(formatValue(cell(row, term)))}</th>` + columns.map(([, numeric, value]) => {
            const shown = value(row);
            return Array.isArray(shown) ? ratioTd(shown) : td(shown, numeric);
        }).join('') + '</tr>');
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
    return level === null ? 'CI' : `${formatPercent(level)} CI`;
}

function decimalCell(index) {
    return (row) => formatValue(cell(row, index), true, RESULT_PLACES);
}

function intervalCell(low, high) {
    return (row) => {
        const a = cell(row, low);
        const b = cell(row, high);
        return a === null && b === null ? EMPTY : `${formatValue(a, true, RESULT_PLACES)} to ${formatValue(b, true, RESULT_PLACES)}`;
    };
}

// The coefficient table: estimate, the exponentiated estimate with its interval when the model has one,
// std. error, statistic, p-value, and interval.
function renderCoefficients(table, model, level, doc) {
    const at = (name) => columnIndex(table, name);
    const low = at('conf_low');
    const high = at('conf_high');
    const interval = low >= 0 && high >= 0;
    const ratio = text(model.exponentiate);
    const columns = extraColumns(table);
    columns.push(['Estimate', true, decimalCell(at('estimate'))]);
    if (ratio !== null) {
        columns.push([interval ? [ratio, intervalLabel(level)] : ratio, true,
            (row) => ratioCell(cell(row, at('estimate')), interval ? [cell(row, low), cell(row, high)] : null, cell(row, at('term')))]);
    }
    if (at('std_error') >= 0) columns.push(['Std. error', true, decimalCell(at('std_error'))]);
    if (at('statistic') >= 0) columns.push(['Statistic', true, decimalCell(at('statistic'))]);
    if (at('p_value') >= 0) {
        const index = at('p_value');
        columns.push(['p-value', true, (row) => (isNumber(cell(row, index)) ? formatPValue(cell(row, index)) : formatValue(cell(row, index)))]);
    }
    if (interval) columns.push([intervalLabel(level), true, intervalCell(low, high)]);
    return coefficientTable(table, doc, 'coefficients', columns);
}

// An exponentiated estimate and its exponentiated interval, as [ratio, interval or null].
// Intercepts show EMPTY.
function ratioCell(estimate, bounds, term) {
    if (!isNumber(estimate) || (typeof term === 'string' && isIntercept(term))) return [EMPTY, null];
    const value = formatNumber(exp(estimate), true, RESULT_PLACES);
    if (bounds === null) return [value, null];
    const [low, high] = bounds.map((bound) => (isNumber(bound) ? formatNumber(exp(bound), true, RESULT_PLACES) : EMPTY));
    return [value, `${low} to ${high}`];
}

// The ratio label on one line and its interval label, in parentheses, on the next.
function ratioTh([label, interval]) {
    return `<th scope="col" class="fw-embed__num">${escapeHtml(label)} <span class="fw-embed__ci">(${escapeHtml(interval)})</span></th>`;
}

// The ratio on one line and its interval, in parentheses, on the next.
function ratioTd([value, interval]) {
    return `<td class="fw-embed__num">${escapeHtml(value)}${interval === null ? '' : ` <span class="fw-embed__ci">(${escapeHtml(interval)})</span>`}</td>`;
}

function isIntercept(term) {
    return /^(\(intercept\)|intercept|const|_cons)$/.test(term.replace(/[A-Z]/g, (c) => c.toLowerCase()));
}

function metricLabel(name) {
    if (Object.hasOwn(METRIC_LABELS, name)) return METRIC_LABELS[name];
    if (name.startsWith('n_groups.') && name.length > 9) return `Groups: ${name.slice(9)}`;
    return asciiCapitalize(name.replace(/[_.]/g, ' '));
}

function metricValue(name, value) {
    if (name === 'confidence_level' && isNumber(value)) return formatPercent(value);
    if (name === 'interval_method' && typeof value === 'string' && Object.hasOwn(INTERVAL_METHODS, value)) return INTERVAL_METHODS[value];
    if (isNumber(value) && isPValueName(name)) return formatPValue(value);
    return formatValue(value, false, RESULT_PLACES);
}

function renderMetrics(metrics) {
    return renderPairs(metrics.map((metric) => [metricLabel(metric.name), metricValue(metric.name, metric.value), isNumber(metric.value)]));
}

function renderPairs(pairs) {
    return ['<dl class="fw-embed__stats">', ...pairs.map(([term, value, numeric]) => {
        if (numeric) return `<div><dt>${escapeHtml(term)}</dt><dd class="fw-embed__num">${escapeHtml(value)}</dd></div>`;
        const wide = [...value].length > WIDE_TEXT ? ' fw-embed__stat--wide' : '';
        return `<div class="fw-embed__stat--text${wide}"><dt>${escapeHtml(term)}</dt><dd>${escapeHtml(value)}</dd></div>`;
    }), '</dl>'].join('\n');
}

// --- other kinds ------------------------------------------------------------

function renderImage(doc) {
    const image = isObject(doc.image) ? doc.image : null;
    const src = image === null ? null : safeUrl(image.url);
    if (src === null) return null;
    const alt = text(doc.alt) ?? text(doc.title) ?? '';
    let tag = `<img class="fw-embed__img" src="${escapeHtml(src)}" alt="${escapeHtml(alt)}" loading="lazy" decoding="async"`;
    if (Number.isInteger(image.width) && image.width > 0 && Number.isInteger(image.height) && image.height > 0) {
        tag += ` width="${image.width}" height="${image.height}"`;
    }
    return `<div class="fw-embed__media">${tag}></div>`;
}

function renderChart(doc) {
    if (!isObject(doc.spec)) return null;
    const href = safeUrl(doc.url);
    const alt = escapeHtml(text(doc.alt) ?? text(doc.title) ?? 'Chart');
    return [
        '<div class="fw-embed__chart" data-fw-embed-chart>',
        `<script type="application/json">${scriptJson(doc.spec)}</script>`,
        `<p class="fw-embed__fallback">${alt}${href === null ? '' : ` <a href="${escapeHtml(href)}">View the chart</a>`}</p>`,
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

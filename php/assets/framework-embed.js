// src/format.js
var EMPTY = "\u2014";
var P_VALUE_NAMES = /* @__PURE__ */ new Set(["p_value", "p.value", "p-value", "pvalue", "pr(>|t|)", "pr(>|z|)", "pr(>chisq)", "p>|t|", "p>|z|"]);
function isPValueName(name) {
  return typeof name === "string" && P_VALUE_NAMES.has(asciiLower(name));
}
function asciiLower(value) {
  return value.replace(/[A-Z]/g, (c) => c.toLowerCase());
}
function asciiCapitalize(value) {
  return /^[a-z]/.test(value) ? value[0].toUpperCase() + value.slice(1) : value;
}
var isNumber = (value) => typeof value === "number" && Number.isFinite(value);
function decompose(x) {
  const [mantissa, exponent] = Math.abs(x).toExponential().split("e");
  const digits = mantissa.replace(".", "").replace(/0+$/, "") || "0";
  return { digits, exp: Number(exponent) };
}
function increment(digits) {
  const out = digits.split("");
  let i = out.length - 1;
  while (i >= 0) {
    if (out[i] === "9") {
      out[i] = "0";
      i -= 1;
    } else {
      out[i] = String(Number(out[i]) + 1);
      return out.join("");
    }
  }
  return "1" + out.join("");
}
function fixedDigits(x, places) {
  let whole;
  if (x === 0) {
    whole = "0";
  } else {
    const { digits, exp: exp2 } = decompose(x);
    const keep = exp2 + 1 + places;
    if (keep < 0) {
      whole = "0";
    } else if (keep === 0) {
      whole = digits[0] >= "5" ? "1" : "0";
    } else {
      let head = digits.length >= keep ? digits.slice(0, keep) : digits + "0".repeat(keep - digits.length);
      if (digits.length > keep && digits[keep] >= "5") head = increment(head);
      whole = head.replace(/^0+(?=\d)/, "");
    }
  }
  if (places === 0) return whole;
  whole = whole.padStart(places + 1, "0");
  return whole.slice(0, -places) + "." + whole.slice(-places);
}
function group(integer) {
  if (integer.length < 5) return integer;
  let out = "";
  for (let i = 0; i < integer.length; i++) {
    if (i > 0 && (integer.length - i) % 3 === 0) out += ",";
    out += integer[i];
  }
  return out;
}
function signed(x, body) {
  return x < 0 && /[1-9]/.test(body) ? "-" + body : body;
}
function formatFixed(x, places = 3) {
  if (!isNumber(x)) return EMPTY;
  const [integer, fraction] = fixedDigits(x, places).split(".");
  const body = fraction === void 0 ? group(integer) : group(integer) + "." + fraction;
  return signed(x, body);
}
function formatScientific(x, significant = 3) {
  if (!isNumber(x)) return EMPTY;
  if (x === 0) return "0." + "0".repeat(significant - 1) + "e+0";
  let { digits, exp: exp2 } = decompose(x);
  let head = digits.length >= significant ? digits.slice(0, significant) : digits + "0".repeat(significant - digits.length);
  if (digits.length > significant && digits[significant] >= "5") head = increment(head);
  if (head.length > significant) {
    head = head.slice(0, significant);
    exp2 += 1;
  }
  const mantissa = significant > 1 ? head[0] + "." + head.slice(1) : head;
  return (x < 0 ? "-" : "") + mantissa + "e" + (exp2 < 0 ? "-" : "+") + Math.abs(exp2);
}
function formatNumber(x, decimal = false) {
  if (!isNumber(x)) return EMPTY;
  const size = Math.abs(x);
  if (size >= 1e15 || size > 0 && size < 1e-3) return formatScientific(x, 3);
  if (!decimal && Number.isInteger(x)) return formatFixed(x, 0);
  return formatFixed(x, 3);
}
function formatPValue(p) {
  if (!isNumber(p)) return formatValue(p);
  return p < 1e-3 ? "< 0.001" : formatFixed(p, 3);
}
function formatPercent(x) {
  if (!isNumber(x)) return formatValue(x);
  const percent = x * 100;
  return isNumber(percent) ? formatFixed(percent, 1).replace(/\.0$/, "") + "%" : EMPTY;
}
function formatValue(value, decimal = false) {
  if (value === null || value === void 0) return EMPTY;
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "number") return formatNumber(value, decimal);
  if (typeof value === "string") return value;
  return EMPTY;
}
var LN2_HI = [0.6931471803691238, -0.6931471803691238];
var LN2_LO = [19082149292705877e-26, -19082149292705877e-26];
var INV_LN2 = 1.4426950408889634;
var P1 = 0.16666666666666602;
var P2 = -0.0027777777777015593;
var P3 = 6613756321437934e-20;
var P4 = -16533902205465252e-22;
var P5 = 41381367970572385e-24;
function pow2(k) {
  let result = 1;
  let base = k < 0 ? 0.5 : 2;
  let n = Math.abs(k);
  while (n > 0) {
    if (n % 2 === 1) result *= base;
    base *= base;
    n = Math.floor(n / 2);
  }
  return result;
}
function exp(x) {
  if (!isNumber(x)) return NaN;
  if (x > 709.782712893384) return Infinity;
  if (x < -745.1332191019411) return 0;
  const sign = x < 0 ? 1 : 0;
  const size = Math.abs(x);
  let hi = 0;
  let lo = 0;
  let k = 0;
  if (size > 0.5 * LN2_HI[0]) {
    if (size < 1.5 * LN2_HI[0]) {
      hi = x - LN2_HI[sign];
      lo = LN2_LO[sign];
      k = 1 - sign - sign;
    } else {
      k = Math.trunc(INV_LN2 * x + (sign ? -0.5 : 0.5));
      hi = x - k * LN2_HI[0];
      lo = k * LN2_LO[0];
    }
    x = hi - lo;
  } else if (size < 3725290298461914e-24) {
    return 1 + x;
  }
  const t = x * x;
  const c = x - t * (P1 + t * (P2 + t * (P3 + t * (P4 + t * P5))));
  if (k === 0) return 1 - (x * c / (c - 2) - x);
  const y = 1 - (lo - x * c / (2 - c) - hi);
  return k >= -1021 ? y * pow2(k) : y * pow2(k + 1e3) * pow2(-1e3);
}

// src/render.js
var ROW_LIMIT = 25;
var KINDS = /* @__PURE__ */ new Set(["model", "table", "test", "image", "vega-lite", "page"]);
var COLUMN_LABELS = {
  term: "Term",
  estimate: "Estimate",
  std_error: "Std. error",
  statistic: "Statistic",
  p_value: "p-value",
  conf_low: "CI lower",
  conf_high: "CI upper",
  group: "Group",
  variance: "Variance",
  std_dev: "Std. dev.",
  correlation: "Correlation",
  feature: "Feature",
  importance: "Importance",
  edf: "EDF",
  ref_df: "Ref. df",
  df: "df"
};
var METRIC_LABELS = {
  nobs: "Observations",
  df_resid: "Residual df",
  df_model: "Model df",
  aic: "AIC",
  bic: "BIC",
  rsquared: "R\xB2",
  "r.squared": "R\xB2",
  rsquared_adj: "Adjusted R\xB2",
  "adj.r.squared": "Adjusted R\xB2",
  pseudo_rsquared: "Pseudo R\xB2",
  sigma: "Residual std. dev.",
  llf: "Log likelihood",
  log_likelihood: "Log likelihood",
  deviance: "Deviance",
  "null.deviance": "Null deviance",
  dispersion: "Dispersion",
  converged: "Converged",
  reml: "REML",
  confidence_level: "Confidence level",
  interval_method: "Interval method",
  vcov_type: "Standard errors",
  n_estimators: "Trees",
  max_depth: "Maximum depth",
  learning_rate: "Learning rate",
  n_features: "Features",
  importance_type: "Importance type",
  objective: "Objective",
  n_events: "Events",
  concordance: "Concordance",
  theta: "Theta",
  command: "Command"
};
var INTERVAL_METHODS = { "wald-t": "Wald t", "wald-normal": "Wald normal", profile: "Profile likelihood", bootstrap: "Bootstrap" };
var MODEL_TYPES = {
  linear: "Linear model",
  glm: "Generalized linear model",
  mixed: "Mixed model",
  survival: "Survival model",
  ordinal: "Ordinal model",
  multinomial: "Multinomial model",
  additive: "Additive model",
  fixed_effects: "Fixed-effects model",
  quantile: "Quantile regression",
  linear_ml: "Linear model",
  tree_ensemble: "Tree ensemble"
};
var ALTERNATIVES = { "two.sided": "Two-sided alternative", less: "One-sided alternative (less)", greater: "One-sided alternative (greater)" };
var COEFFICIENT_COLUMNS = /* @__PURE__ */ new Set(["term", "estimate", "std_error", "statistic", "p_value", "conf_low", "conf_high", "df"]);
var ESCAPES = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };
var escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ESCAPES[c]);
var isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
var list = (value) => Array.isArray(value) ? value : [];
var text = (value) => typeof value === "string" && value !== "" ? value : null;
function safeUrl(value) {
  return typeof value === "string" && /^https?:\/\/[^\x00-\x20\x7f"'<>\\`]+$/i.test(value) ? value : null;
}
function scriptJson(value) {
  return JSON.stringify(value).replace(/[<>&\u2028\u2029]/g, (c) => "\\u" + c.charCodeAt(0).toString(16).padStart(4, "0"));
}
function render(doc) {
  if (!isObject(doc)) return "";
  let kind = KINDS.has(doc.kind) ? doc.kind : "page";
  let body = null;
  if (kind === "model") body = renderModel(doc);
  else if (kind === "table") body = renderTableKind(doc);
  else if (kind === "test") body = renderTest(doc);
  else if (kind === "image") body = renderImage(doc);
  else if (kind === "vega-lite") body = renderChart(doc);
  if (body === null) {
    kind = "page";
    body = renderPage(doc);
  }
  let open = `<figure class="fw-embed fw-embed--${kind}" data-fw-embed-kind="${kind}"`;
  if (text(doc.key) !== null) open += ` data-fw-embed-key="${escapeHtml(doc.key)}"`;
  if (Number.isInteger(doc.version)) open += ` data-fw-embed-version="${doc.version}"`;
  return [open + ">", `<div class="fw-embed__body">`, body, "</div>", renderCaption(doc, kind), "</figure>", ""].join("\n");
}
function renderCaption(doc, kind) {
  const parts = ['<figcaption class="fw-embed__caption">'];
  if (kind !== "page") {
    if (text(doc.title) !== null) parts.push(`<span class="fw-embed__title">${escapeHtml(doc.title)}</span>`);
    if (text(doc.description) !== null) parts.push(`<span class="fw-embed__description">${escapeHtml(doc.description)}</span>`);
    const author = isObject(doc.author) ? doc.author : {};
    if (text(author.name) !== null) {
      const href2 = safeUrl(author.url);
      const name = escapeHtml(author.name);
      parts.push(`<span class="fw-embed__author">${href2 === null ? name : `<a href="${escapeHtml(href2)}">${name}</a>`}</span>`);
    }
  }
  const attribution = isObject(doc.attribution) ? doc.attribution : {};
  const credit = escapeHtml(text(attribution.text) ?? "Framework");
  const href = safeUrl(attribution.url) ?? safeUrl(doc.url);
  parts.push(href === null ? `<span class="fw-embed__credit">${credit}</span>` : `<a class="fw-embed__credit" href="${escapeHtml(href)}" target="_blank" rel="noopener">${credit}</a>`);
  parts.push("</figcaption>");
  return parts.join("\n");
}
function usableTables(data) {
  return list(data.tables).filter((table) => isObject(table) && Array.isArray(table.rows) && Array.isArray(table.columns) && table.columns.length > 0 && table.columns.every((column) => isObject(column) && text(column.name) !== null));
}
function usableMetrics(data) {
  return list(data.metrics).filter((metric) => isObject(metric) && text(metric.name) !== null);
}
function renderModel(doc) {
  const data = doc.data;
  if (!isObject(data)) return null;
  const tables = usableTables(data);
  const metrics = usableMetrics(data);
  const formula = text(data.formula);
  if (tables.length === 0 && metrics.length === 0 && formula === null) return null;
  const model = isObject(data.model) ? data.model : {};
  const parts = [];
  const meta = modelMeta(model);
  if (meta !== null) parts.push(`<p class="fw-embed__meta">${escapeHtml(meta)}</p>`);
  if (formula !== null) parts.push(`<p class="fw-embed__formula"><code>${escapeHtml(formula)}</code></p>`);
  let level = null;
  for (const metric of metrics) if (metric.name === "confidence_level" && isNumber(metric.value)) {
    level = metric.value;
    break;
  }
  tables.forEach((table, index) => {
    const coefficients = (table.role === "coefficients" || (table.role === null || table.role === void 0) && index === 0) && columnIndex(table, "term") >= 0 && columnIndex(table, "estimate") >= 0;
    parts.push(coefficients ? renderCoefficients(table, model, level, doc) : renderTable(table, true, true, doc));
  });
  if (metrics.length > 0) parts.push(renderMetrics(metrics));
  return parts.join("\n");
}
function renderTableKind(doc) {
  const data = doc.data;
  if (!isObject(data)) return null;
  const tables = usableTables(data);
  if (tables.length === 0) return null;
  const parts = tables.map((table) => renderTable(table, tables.length > 1, false, doc));
  const metrics = usableMetrics(data);
  if (metrics.length > 0) parts.push(renderMetrics(metrics));
  return parts.join("\n");
}
function renderTest(doc) {
  const data = doc.data;
  if (!isObject(data) || !isObject(data.test) || text(data.test.method) === null) return null;
  const test = data.test;
  const parts = [`<p class="fw-embed__method">${escapeHtml(test.method)}</p>`];
  if (typeof test.alternative === "string" && Object.hasOwn(ALTERNATIVES, test.alternative)) {
    parts.push(`<p class="fw-embed__meta">${ALTERNATIVES[test.alternative]}</p>`);
  }
  const stats = [];
  if (isObject(test.statistic) && text(test.statistic.name) !== null) stats.push([test.statistic.name, formatNumber(test.statistic.value)]);
  for (const entry of named(test.parameters)) stats.push([entry.name, formatNumber(entry.value)]);
  if (isNumber(test.p_value)) stats.push(["p-value", formatPValue(test.p_value)]);
  for (const entry of named(test.estimates)) stats.push([asciiCapitalize(entry.name), formatNumber(entry.value)]);
  for (const entry of named(test.null_value)) stats.push([`Null value: ${entry.name}`, formatNumber(entry.value)]);
  if (isObject(test.conf_int) && isNumber(test.conf_int.level)) {
    const low = isNumber(test.conf_int.low) ? formatNumber(test.conf_int.low) : "-\u221E";
    const high = isNumber(test.conf_int.high) ? formatNumber(test.conf_int.high) : "\u221E";
    stats.push([`${formatPercent(test.conf_int.level)} CI`, `${low} to ${high}`]);
  }
  if (stats.length > 0) parts.push(renderPairs(stats));
  for (const table of usableTables(data)) parts.push(renderTable(table, true, true, doc));
  const metrics = usableMetrics(data);
  if (metrics.length > 0) parts.push(renderMetrics(metrics));
  return parts.join("\n");
}
function named(value) {
  return list(value).filter((entry) => isObject(entry) && text(entry.name) !== null);
}
function modelMeta(model) {
  const parts = [];
  if (text(model.type) !== null && model.type !== "other") parts.push(Object.hasOwn(MODEL_TYPES, model.type) ? MODEL_TYPES[model.type] : model.type);
  const family = text(model.family);
  const link = text(model.link);
  if (family !== null && link !== null) parts.push(`${family} family, ${link} link`);
  else if (family !== null) parts.push(`${family} family`);
  else if (link !== null) parts.push(`${link} link`);
  if (text(model.response) !== null) parts.push(`Response: ${model.response}`);
  return parts.length > 0 ? parts.join(" \xB7 ") : null;
}
function columnIndex(table, name) {
  return table.columns.findIndex((column) => column.name === name);
}
function cell(row, index) {
  return Array.isArray(row) && index >= 0 && index < row.length ? row[index] : null;
}
function shownRows(table) {
  return table.rows.slice(0, ROW_LIMIT).filter((row) => Array.isArray(row));
}
function isDecimalColumn(rows, index) {
  return rows.some((row) => isNumber(cell(row, index)) && !Number.isInteger(cell(row, index)));
}
function formatCell(value, column, decimal) {
  if (isNumber(value) && isPValueName(column.name)) return formatPValue(value);
  return formatValue(value, decimal);
}
function columnLabel(column, modelLabels) {
  if (text(column.label) !== null) return column.label;
  if (modelLabels && Object.hasOwn(COLUMN_LABELS, column.name)) return COLUMN_LABELS[column.name];
  return column.name;
}
function th(label, numeric) {
  return `<th scope="col"${numeric ? ' class="fw-embed__num"' : ""}>${escapeHtml(label)}</th>`;
}
function td(value, numeric) {
  return `<td${numeric ? ' class="fw-embed__num"' : ""}>${escapeHtml(value)}</td>`;
}
function openTable(table, showCaption, modifier) {
  const title = text(table.title) ?? "Table";
  const lines = [
    `<div class="fw-embed__scroll" role="region" tabindex="0" aria-label="${escapeHtml(title)}">`,
    `<table class="fw-embed__table${modifier ? " fw-embed__table--" + modifier : ""}">`
  ];
  if (showCaption) lines.push(`<caption>${escapeHtml(title)}</caption>`);
  return lines;
}
function tableNotes(table, rows, doc) {
  const notes = [];
  const total = Number.isInteger(table.row_count) ? table.row_count : table.rows.length;
  const columnTotal = Number.isInteger(table.column_count) ? table.column_count : table.columns.length;
  const parts = [];
  if (total > rows.length) parts.push(`${formatNumber(rows.length)} of ${formatNumber(total)} rows`);
  if (columnTotal > table.columns.length) parts.push(`${formatNumber(table.columns.length)} of ${formatNumber(columnTotal)} columns`);
  if (parts.length > 0) {
    const href = safeUrl(doc.url);
    notes.push(`<p class="fw-embed__note">Showing ${parts.join(" and ")}.${href === null ? "" : ` <a href="${escapeHtml(href)}">View the full table</a>`}</p>`);
  }
  if (text(table.precision_note) !== null) notes.push(`<p class="fw-embed__note">${escapeHtml(table.precision_note)}</p>`);
  return notes;
}
function renderTable(table, showCaption, modelLabels, doc) {
  const rows = shownRows(table);
  const columns = table.columns;
  const decimal = columns.map((_, index) => isDecimalColumn(rows, index));
  const lines = openTable(table, showCaption, null);
  lines.push("<thead><tr>" + columns.map((column) => th(columnLabel(column, modelLabels), column.type === "number")).join("") + "</tr></thead>");
  lines.push("<tbody>");
  for (const row of rows) {
    lines.push("<tr>" + columns.map((column, index) => td(formatCell(cell(row, index), column, decimal[index]), column.type === "number")).join("") + "</tr>");
  }
  lines.push("</tbody>", "</table>", "</div>");
  return [...lines, ...tableNotes(table, rows, doc)].join("\n");
}
function renderCoefficients(table, model, level, doc) {
  const rows = shownRows(table);
  const at = (name) => columnIndex(table, name);
  const term = at("term");
  const estimate = at("estimate");
  const error = at("std_error");
  const pValue = at("p_value");
  const low = at("conf_low");
  const high = at("conf_high");
  const interval = low >= 0 && high >= 0;
  const ratio = text(model.exponentiate);
  const extras = [];
  table.columns.forEach((column, index) => {
    if (!COEFFICIENT_COLUMNS.has(column.name)) extras.push(index);
  });
  const decimal = table.columns.map((_, index) => isDecimalColumn(rows, index));
  const head = [th(columnLabel(table.columns[term], true), false)];
  for (const index of extras) head.push(th(columnLabel(table.columns[index], true), table.columns[index].type === "number"));
  head.push(th("Estimate", true));
  const intervalLabel = level === null ? "CI" : `${formatPercent(level)} CI`;
  if (ratio !== null) head.push(th(interval ? `${ratio} (${intervalLabel})` : ratio, true));
  if (error >= 0) head.push(th("Std. error", true));
  if (interval) head.push(th(intervalLabel, true));
  if (pValue >= 0) head.push(th("p-value", true));
  const lines = openTable(table, true, "coefficients");
  lines.push("<thead><tr>" + head.join("") + "</tr></thead>", "<tbody>");
  for (const row of rows) {
    const cells = [`<th scope="row">${escapeHtml(formatValue(cell(row, term)))}</th>`];
    for (const index of extras) cells.push(td(formatCell(cell(row, index), table.columns[index], decimal[index]), table.columns[index].type === "number"));
    const value = cell(row, estimate);
    cells.push(td(formatValue(value, true), true));
    if (ratio !== null) cells.push(td(ratioCell(value, interval ? [cell(row, low), cell(row, high)] : null, cell(row, term)), true));
    if (error >= 0) cells.push(td(formatValue(cell(row, error), true), true));
    if (interval) {
      const a = cell(row, low);
      const b = cell(row, high);
      cells.push(td(a === null && b === null ? EMPTY : `${formatValue(a, true)} to ${formatValue(b, true)}`, true));
    }
    if (pValue >= 0) cells.push(td(isNumber(cell(row, pValue)) ? formatPValue(cell(row, pValue)) : formatValue(cell(row, pValue)), true));
    lines.push("<tr>" + cells.join("") + "</tr>");
  }
  lines.push("</tbody>", "</table>", "</div>");
  return [...lines, ...tableNotes(table, rows, doc)].join("\n");
}
function ratioCell(estimate, bounds, term) {
  if (!isNumber(estimate) || typeof term === "string" && isIntercept(term)) return EMPTY;
  const value = formatNumber(exp(estimate), true);
  if (bounds === null) return value;
  const [low, high] = bounds.map((bound) => isNumber(bound) ? formatNumber(exp(bound), true) : EMPTY);
  return `${value} (${low} to ${high})`;
}
function isIntercept(term) {
  return /^(\(intercept\)|intercept|const|_cons)$/.test(term.replace(/[A-Z]/g, (c) => c.toLowerCase()));
}
function metricLabel(name) {
  if (Object.hasOwn(METRIC_LABELS, name)) return METRIC_LABELS[name];
  if (name.startsWith("n_groups.") && name.length > 9) return `Groups: ${name.slice(9)}`;
  return asciiCapitalize(name.replace(/[_.]/g, " "));
}
function metricValue(name, value) {
  if (name === "confidence_level" && isNumber(value)) return formatPercent(value);
  if (name === "interval_method" && typeof value === "string" && Object.hasOwn(INTERVAL_METHODS, value)) return INTERVAL_METHODS[value];
  if (isNumber(value) && isPValueName(name)) return formatPValue(value);
  return formatValue(value);
}
function renderMetrics(metrics) {
  return renderPairs(metrics.map((metric) => [metricLabel(metric.name), metricValue(metric.name, metric.value)]));
}
function renderPairs(pairs) {
  return ['<dl class="fw-embed__stats">', ...pairs.map(([term, value]) => `<div><dt>${escapeHtml(term)}</dt><dd>${escapeHtml(value)}</dd></div>`), "</dl>"].join("\n");
}
function renderImage(doc) {
  const image = isObject(doc.image) ? doc.image : null;
  const src = image === null ? null : safeUrl(image.url);
  if (src === null) return null;
  const alt = text(doc.alt) ?? text(doc.title) ?? "";
  let tag = `<img class="fw-embed__img" src="${escapeHtml(src)}" alt="${escapeHtml(alt)}" loading="lazy" decoding="async"`;
  if (Number.isInteger(image.width) && image.width > 0 && Number.isInteger(image.height) && image.height > 0) {
    tag += ` width="${image.width}" height="${image.height}"`;
  }
  return `<div class="fw-embed__media">${tag}></div>`;
}
function renderChart(doc) {
  if (!isObject(doc.spec)) return null;
  const href = safeUrl(doc.url);
  const alt = escapeHtml(text(doc.alt) ?? text(doc.title) ?? "Chart");
  return [
    '<div class="fw-embed__chart" data-fw-embed-chart>',
    `<script type="application/json">${scriptJson(doc.spec)}<\/script>`,
    `<p class="fw-embed__fallback">${alt}${href === null ? "" : ` <a href="${escapeHtml(href)}">View the chart</a>`}</p>`,
    "</div>"
  ].join("\n");
}
function renderPage(doc) {
  const title = escapeHtml(text(doc.title) ?? "Untitled");
  const href = safeUrl(doc.url);
  const lines = [
    '<div class="fw-embed__card">',
    href === null ? `<span class="fw-embed__card-title">${title}</span>` : `<a class="fw-embed__card-title" href="${escapeHtml(href)}">${title}</a>`
  ];
  if (text(doc.description) !== null) lines.push(`<p class="fw-embed__card-description">${escapeHtml(doc.description)}</p>`);
  const author = isObject(doc.author) ? doc.author : {};
  if (text(author.name) !== null) {
    const authorHref = safeUrl(author.url);
    const name = escapeHtml(author.name);
    lines.push(`<p class="fw-embed__author">${authorHref === null ? name : `<a href="${escapeHtml(authorHref)}">${name}</a>`}</p>`);
  }
  lines.push("</div>");
  return lines.join("\n");
}

// src/url.js
var DEFAULT_HOSTS = Object.freeze(["framework.pub"]);
var URL_PATTERN = /^([a-z][a-z0-9+.-]*):\/\/([^/?#]*)([^?#]*)(\?[^#]*)?(#.*)?$/i;
var AUTHORITY_PATTERN = /^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::([0-9]{1,5}))?$/i;
var DEFAULT_PORTS = { http: "80", https: "443" };
var KEY = "([A-Za-z0-9_-]{1,64})";
var NAME = "([A-Za-z0-9_.-]{1,200})";
var VERSION = "([1-9][0-9]{0,8})";
var PATHS = [
  ["embed", new RegExp(`^/api/embed/v1/artifacts/${KEY}(?:/versions/${VERSION})?$`)],
  ["key", new RegExp(`^/d/${KEY}(?:/versions/${VERSION})?/?$`)],
  ["path", new RegExp(`^/@${NAME}/${NAME}/${NAME}(?:/versions/${VERSION})?/?$`)]
];
function parse(url) {
  if (typeof url !== "string" || url.length === 0 || url.length > 2048 || /[^\x21-\x7e]|\\/.test(url)) return null;
  const parts = URL_PATTERN.exec(url);
  if (!parts) return null;
  const scheme = parts[1].toLowerCase();
  if (scheme !== "https" && scheme !== "http") return null;
  const authority = AUTHORITY_PATTERN.exec(parts[2]);
  if (!authority) return null;
  const host = authority[1].toLowerCase();
  if (/(^|\.)(\d+|0x[0-9a-f]*)$/.test(host)) return null;
  let port = authority[2] ?? null;
  if (port !== null) {
    const number = Number(port);
    if (number < 1 || number > 65535) return null;
    port = String(number) === DEFAULT_PORTS[scheme] ? null : String(number);
  }
  return { scheme, host, port, path: parts[3] || "/", query: parts[4] ?? "" };
}
function parseAllowed(entry) {
  if (typeof entry !== "string") return null;
  if (!entry.includes("://")) entry = "https://" + entry;
  const parsed = parse(entry);
  return parsed && parsed.path === "/" && parsed.query === "" ? parsed : null;
}
function originOf(parsed) {
  return `${parsed.scheme}://${parsed.host}${parsed.port === null ? "" : ":" + parsed.port}`;
}
function allowedOrigin(url, hosts = DEFAULT_HOSTS) {
  const parsed = parse(url);
  if (!parsed) return null;
  for (const entry of hosts) {
    const allowed = parseAllowed(entry);
    if (allowed && allowed.scheme === parsed.scheme && allowed.host === parsed.host && allowed.port === parsed.port) return originOf(parsed);
  }
  return null;
}
function isAllowedUrl(url, hosts = DEFAULT_HOSTS) {
  return allowedOrigin(url, hosts) !== null;
}
function matchArtifactUrl(url, hosts = DEFAULT_HOSTS) {
  const origin = allowedOrigin(url, hosts);
  if (!origin) return null;
  const { path } = parse(url);
  for (const [type, pattern] of PATHS) {
    const m = pattern.exec(path);
    if (!m) continue;
    if (type === "path") {
      if ([m[1], m[2], m[3]].some((part) => part === "." || part === "..")) return null;
      return { type, origin, url: origin + path, handle: m[1], project: m[2], slug: m[3], key: null, version: m[4] ? Number(m[4]) : null };
    }
    return { type, origin, url: origin + path, handle: null, project: null, slug: null, key: m[1], version: m[2] ? Number(m[2]) : null };
  }
  return null;
}
function embedRequestUrl(url, hosts = DEFAULT_HOSTS) {
  const match = matchArtifactUrl(url, hosts);
  if (!match) return null;
  if (match.type === "embed") return match.url;
  return `${match.origin}/api/embed/v1/resolve?url=${encodeURIComponent(match.url)}`;
}

// src/browser.js
var DEFAULT_VEGA_SCRIPTS = Object.freeze([
  { src: "https://cdn.jsdelivr.net/npm/vega@6.2.0/build/vega.min.js", integrity: "sha384-0Wc8+KeboSkAq/fK81pd4uFbWKu4ouB+y4KWCYxlC69hRWol7vnc6zZSruXOtc0F" },
  { src: "https://cdn.jsdelivr.net/npm/vega-lite@6.4.3/build/vega-lite.min.js", integrity: "sha384-9/70gNCfOu6G7xXvkdreMfuqAEsoaGJVXV2BN/JLRXkSmcGvnMqtsRx8HZtUWAvI" },
  { src: "https://cdn.jsdelivr.net/npm/vega-embed@7.1.0/build/vega-embed.min.js", integrity: "sha384-giTAWqsEDsWuWzWKi6NCvjgwi160SClnTYYXWPLuy/DnaQTqmk4soinrpxcCS4dx" }
]);
var config = {
  hosts: [...DEFAULT_HOSTS],
  vegaScripts: [...DEFAULT_VEGA_SCRIPTS],
  vegaEmbed: null,
  vegaInterpreter: null,
  vegaOptions: {}
};
function configure(options = {}) {
  if (Array.isArray(options.hosts)) config.hosts = [...options.hosts];
  if (Array.isArray(options.vegaScripts)) config.vegaScripts = options.vegaScripts.map((s) => typeof s === "string" ? { src: s } : s);
  if (typeof options.vegaEmbed === "function" || options.vegaEmbed === null) config.vegaEmbed = options.vegaEmbed;
  if (typeof options.vegaInterpreter === "object" || options.vegaInterpreter === null) config.vegaInterpreter = options.vegaInterpreter;
  if (options.vegaOptions && typeof options.vegaOptions === "object") config.vegaOptions = { ...options.vegaOptions };
  return { ...config, hosts: [...config.hosts], vegaScripts: [...config.vegaScripts] };
}
var EmbedError = class extends Error {
  constructor(code, message) {
    super(message);
    this.name = "EmbedError";
    this.code = code;
  }
};
async function fetchEmbed(url, options = {}) {
  const hosts = options.hosts ?? config.hosts;
  const target = embedRequestUrl(url, hosts);
  if (target === null) throw new EmbedError("not_allowed", "The URL is not a Framework artifact on an allowed host.");
  const request = options.fetch ?? globalThis.fetch;
  const response = await request(target, {
    method: "GET",
    mode: "cors",
    credentials: "omit",
    redirect: "error",
    referrerPolicy: "strict-origin-when-cross-origin",
    headers: { Accept: "application/json" },
    signal: options.signal
  });
  if (response.status === 404 || response.status === 410) return null;
  if (!response.ok) throw new EmbedError("http", `The embed request failed with status ${response.status}.`);
  const doc = await response.json();
  if (doc === null || typeof doc !== "object" || doc.schema !== "framework.embed/v1") throw new EmbedError("invalid", "The answer is not a framework.embed/v1 document.");
  return doc;
}
function mount(element, doc, options = {}) {
  element.innerHTML = render(doc);
  enhanceCharts(element, options);
  return element.firstElementChild;
}
var vegaPromise = null;
function loadScript(entry) {
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = entry.src;
    script.async = false;
    if (entry.integrity) {
      script.integrity = entry.integrity;
      script.crossOrigin = "anonymous";
    }
    script.onload = () => resolve();
    script.onerror = () => reject(new EmbedError("vega", `Could not load ${entry.src}.`));
    document.head.append(script);
  });
}
function loadVegaEmbed() {
  if (config.vegaEmbed) return Promise.resolve(config.vegaEmbed);
  if (typeof window !== "undefined" && typeof window.vegaEmbed === "function") return Promise.resolve(window.vegaEmbed);
  vegaPromise ?? (vegaPromise = config.vegaScripts.reduce((chain, entry) => chain.then(() => loadScript(entry)), Promise.resolve()).then(() => {
    if (typeof window.vegaEmbed !== "function") throw new EmbedError("vega", "vega-embed did not load.");
    return window.vegaEmbed;
  }).catch((error) => {
    vegaPromise = null;
    throw error;
  }));
  return vegaPromise;
}
function parseColor(value) {
  if (typeof value !== "string" || value === "") return null;
  const rgb = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.]+%?))?\s*\)$/i.exec(value.trim());
  if (rgb) {
    const alpha = rgb[4] === void 0 ? 1 : rgb[4].endsWith("%") ? parseFloat(rgb[4]) / 100 : parseFloat(rgb[4]);
    return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3]), alpha];
  }
  if (value.trim() === "transparent") return [0, 0, 0, 0];
  try {
    const canvas = document.createElement("canvas");
    canvas.width = 1;
    canvas.height = 1;
    const context = canvas.getContext("2d", { willReadFrequently: true });
    if (!context) return null;
    context.fillStyle = value;
    context.fillRect(0, 0, 1, 1);
    const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;
    return a === 0 ? [0, 0, 0, 0] : [r, g, b, Math.round(a / 255 * 1e3) / 1e3];
  } catch {
    return null;
  }
}
function luminance([r, g, b]) {
  const channel = (c) => {
    const v = c / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}
function styleOf(element) {
  return typeof getComputedStyle === "function" ? getComputedStyle(element) : null;
}
function prefersDark(element) {
  for (let node = element; node && node.nodeType === 1; node = node.parentElement) {
    const theme = node.getAttribute("data-theme");
    if (theme === "dark" || theme === "light") return theme === "dark";
    if (node.classList.contains("dark")) return true;
    if (node.classList.contains("light")) return false;
  }
  for (let node = element; node && node.nodeType === 1; node = node.parentElement) {
    const color = parseColor(styleOf(node)?.backgroundColor);
    if (color && color[3] >= 0.5) return luminance(color) < 0.2;
  }
  const scheme = (styleOf(element)?.colorScheme ?? "").trim().split(/\s+/);
  if (scheme.length === 1 && (scheme[0] === "dark" || scheme[0] === "light")) return scheme[0] === "dark";
  return typeof matchMedia === "function" && matchMedia("(prefers-color-scheme: dark)").matches;
}
var refuse = () => Promise.reject(new EmbedError("blocked", "Framework charts load no external resources."));
var blockingLoader = Object.freeze({ load: refuse, sanitize: refuse, http: refuse, file: refuse, options: Object.freeze({}) });
function referencesUrl(value, depth = 0) {
  if (depth > 64 || value === null || typeof value !== "object") return depth > 64;
  if (Array.isArray(value)) return value.some((item) => referencesUrl(item, depth + 1));
  for (const [key, item] of Object.entries(value)) {
    if (key === "url" && typeof item === "string") return true;
    if (referencesUrl(item, depth + 1)) return true;
  }
  return false;
}
function chartSpec(spec) {
  if (spec === null || typeof spec !== "object" || Array.isArray(spec) || referencesUrl(spec)) return null;
  const copy = JSON.parse(JSON.stringify(spec));
  delete copy.usermeta;
  return copy;
}
function tooltipText(value) {
  if (value === null || value === void 0) return "";
  if (typeof value !== "object") return String(value);
  return Object.entries(value).map(([key, item]) => `${key}: ${item !== null && typeof item === "object" ? JSON.stringify(item) : String(item)}`).join("\n");
}
function textTooltip() {
  let tip = null;
  return (handler, event, item, value) => {
    const text2 = tooltipText(value);
    if (text2 === "") {
      if (tip) tip.hidden = true;
      return;
    }
    if (!tip) {
      tip = document.createElement("div");
      tip.className = "fw-embed__tooltip";
      tip.setAttribute("role", "tooltip");
      document.body.append(tip);
    }
    tip.textContent = text2;
    tip.hidden = false;
    tip.style.left = `${(event?.clientX ?? 0) + 12}px`;
    tip.style.top = `${(event?.clientY ?? 0) + 12}px`;
  };
}
var COMPOSITE = ["facet", "repeat", "concat", "hconcat", "vconcat"];
function isVegaLite(spec) {
  const schema = typeof spec.$schema === "string" ? spec.$schema : "";
  if (schema.includes("/vega-lite/")) return true;
  if (schema.includes("/vega/")) return false;
  return "mark" in spec || "layer" in spec;
}
function responsiveSpec(spec) {
  if (!isVegaLite(spec) || spec.width !== void 0 || COMPOSITE.some((key) => key in spec)) return spec;
  const encoding = spec.encoding ?? {};
  if (encoding.row || encoding.column || encoding.facet) return spec;
  return { ...spec, width: "container", autosize: spec.autosize ?? { type: "fit", contains: "padding" } };
}
function rgba(color) {
  return color === null ? null : `rgba(${color[0]}, ${color[1]}, ${color[2]}, ${Math.round(color[3] * 1e3) / 1e3})`;
}
function chartTheme(container) {
  const figure = container.closest(".fw-embed") ?? container;
  const style = styleOf(figure);
  const font = style?.fontFamily || "sans-serif";
  const fgColor = parseColor(style?.color) ?? [0, 0, 0, 1];
  let muted = null;
  let border = null;
  if (style) {
    const probe = document.createElement("span");
    probe.style.cssText = "position:absolute;visibility:hidden;color:var(--fw-embed-muted);border:1px solid var(--fw-embed-border)";
    figure.append(probe);
    const probeStyle = styleOf(probe);
    muted = parseColor(probeStyle?.color);
    border = parseColor(probeStyle?.borderTopColor);
    probe.remove();
  }
  if (muted === null || muted[3] === 1 && muted.slice(0, 3).join() === fgColor.slice(0, 3).join()) muted = [...fgColor.slice(0, 3), 0.64];
  if (border === null || border.slice(0, 3).join() === fgColor.slice(0, 3).join() && border[3] === 1) border = [...fgColor.slice(0, 3), 0.16];
  const fg = rgba(fgColor);
  const mutedColor = rgba(muted);
  const borderColor = rgba(border);
  return {
    dark: prefersDark(container),
    config: {
      background: "transparent",
      font,
      title: { color: fg, subtitleColor: mutedColor, font },
      style: { "guide-label": { fill: mutedColor, font }, "guide-title": { fill: fg, font } },
      axis: { labelColor: mutedColor, titleColor: fg, gridColor: borderColor, domainColor: borderColor, tickColor: borderColor, labelFont: font, titleFont: font },
      legend: { labelColor: mutedColor, titleColor: fg, labelFont: font, titleFont: font },
      header: { labelColor: mutedColor, titleColor: fg, labelFont: font, titleFont: font },
      view: { stroke: borderColor }
    }
  };
}
function chartOptions(container, theme = chartTheme(container)) {
  return {
    renderer: "svg",
    ...theme.dark ? { theme: "dark" } : {},
    config: theme.config,
    ...config.vegaOptions,
    actions: false,
    ast: true,
    ...config.vegaInterpreter ? { expr: config.vegaInterpreter } : {},
    loader: blockingLoader,
    tooltip: textTooltip()
  };
}
var drawn = /* @__PURE__ */ new Map();
var themeObserver = null;
var themeScheduled = false;
function themeKey(theme) {
  return JSON.stringify(theme);
}
function recheckThemes() {
  themeScheduled = false;
  for (const [container, state] of drawn) {
    if (!container.isConnected) {
      state.result?.finalize?.();
      drawn.delete(container);
      continue;
    }
    if (themeKey(chartTheme(container)) !== state.key) drawChart(container);
  }
}
function scheduleThemeCheck() {
  if (themeScheduled) return;
  themeScheduled = true;
  (typeof requestAnimationFrame === "function" ? requestAnimationFrame : setTimeout)(recheckThemes);
}
function watchThemes() {
  if (themeObserver || typeof MutationObserver !== "function") return;
  themeObserver = new MutationObserver(scheduleThemeCheck);
  const options = { attributes: true, attributeFilter: ["class", "data-theme", "style"] };
  themeObserver.observe(document.documentElement, options);
  if (document.body) themeObserver.observe(document.body, options);
  if (typeof matchMedia === "function") matchMedia("(prefers-color-scheme: dark)").addEventListener?.("change", scheduleThemeCheck);
}
async function drawChart(container) {
  const previous = drawn.get(container);
  const generation = (previous?.generation ?? 0) + 1;
  drawn.set(container, { ...previous, generation });
  if (!previous) container.dataset.fwEmbedChartState = "loading";
  try {
    const script = container.querySelector('script[type="application/json"]');
    const spec = chartSpec(JSON.parse(script?.textContent ?? "null"));
    if (spec === null) throw new EmbedError("blocked", "The chart has no usable inline specification.");
    const vegaEmbed = await loadVegaEmbed();
    const theme = chartTheme(container);
    const old = container.querySelector(".fw-embed__chart-view");
    const view = document.createElement("div");
    view.className = "fw-embed__chart-view";
    if (old) view.style.minHeight = `${old.offsetHeight}px`;
    previous?.result?.finalize?.();
    old?.remove();
    container.prepend(view);
    const result = await vegaEmbed(view, responsiveSpec(spec), chartOptions(container, theme));
    view.style.minHeight = "";
    if (drawn.get(container)?.generation !== generation) {
      result?.finalize?.();
      return;
    }
    drawn.set(container, { generation, key: themeKey(theme), result });
    container.querySelector(".fw-embed__fallback")?.classList.add("fw-embed__sr-only");
    container.dataset.fwEmbedChartState = "ready";
    watchThemes();
  } catch (error) {
    if (drawn.get(container)?.generation !== generation) return;
    drawn.delete(container);
    container.querySelector(".fw-embed__chart-view")?.remove();
    container.querySelector(".fw-embed__fallback")?.classList.remove("fw-embed__sr-only");
    container.dataset.fwEmbedChartState = "error";
  }
}
function enhanceCharts(root = document, options = {}) {
  const charts = [...root.querySelectorAll("[data-fw-embed-chart]:not([data-fw-embed-chart-state])")];
  if (charts.length === 0) return 0;
  const lazy = options.lazy !== false && typeof IntersectionObserver === "function";
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
  }, { rootMargin: "200px" });
  for (const chart of charts) {
    chart.dataset.fwEmbedChartState = "waiting";
    observer.observe(chart);
  }
  return charts.length;
}
var Base = typeof HTMLElement === "undefined" ? class {
} : HTMLElement;
var FrameworkArtifactElement = class extends Base {
  static get observedAttributes() {
    return ["src"];
  }
  connectedCallback() {
    if (this.getAttribute("data-fw-embed-state") !== "ready") this.load();
  }
  attributeChangedCallback(name, previous, next) {
    if (previous !== null && previous !== next && this.isConnected) this.load();
  }
  // A stored document in a direct <script type="application/json"> child, if any.
  storedDocument() {
    const script = [...this.children].find((child) => child.tagName === "SCRIPT" && child.getAttribute("type") === "application/json");
    if (!script) return null;
    try {
      const doc = JSON.parse(script.textContent);
      return doc !== null && typeof doc === "object" && doc.schema === "framework.embed/v1" ? doc : null;
    } catch {
      return null;
    }
  }
  async load() {
    const token = Symbol("load");
    this._token = token;
    const stored = this.storedDocument();
    if (stored) {
      this.show(stored);
      return;
    }
    const src = this.getAttribute("src");
    if (src === null) return;
    this.setAttribute("data-fw-embed-state", "loading");
    try {
      const doc = await fetchEmbed(src);
      if (this._token !== token) return;
      if (doc === null) {
        this.setAttribute("data-fw-embed-state", "gone");
        return;
      }
      this.show(doc);
    } catch (error) {
      if (this._token !== token) return;
      this.setAttribute("data-fw-embed-state", "error");
      this.dispatchEvent(new CustomEvent("framework-embed:error", { detail: { error } }));
    }
  }
  show(doc) {
    mount(this, doc);
    this.setAttribute("data-fw-embed-state", "ready");
    this.dispatchEvent(new CustomEvent("framework-embed:load", { detail: { doc } }));
  }
};
function defineElement(name = "framework-artifact") {
  if (typeof customElements === "undefined" || customElements.get(name)) return false;
  customElements.define(name, class extends FrameworkArtifactElement {
  });
  return true;
}
export {
  DEFAULT_HOSTS,
  DEFAULT_VEGA_SCRIPTS,
  EmbedError,
  FrameworkArtifactElement,
  ROW_LIMIT,
  allowedOrigin,
  blockingLoader,
  chartOptions,
  chartSpec,
  chartTheme,
  configure,
  defineElement,
  embedRequestUrl,
  enhanceCharts,
  escapeHtml,
  fetchEmbed,
  formatFixed,
  formatNumber,
  formatPValue,
  formatPercent,
  formatScientific,
  formatValue,
  isAllowedUrl,
  loadVegaEmbed,
  matchArtifactUrl,
  mount,
  parseColor,
  prefersDark,
  referencesUrl,
  render,
  responsiveSpec,
  safeUrl,
  scriptJson,
  textTooltip
};

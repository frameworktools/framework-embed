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
var DATA_PLACES = 3;
var RESULT_PLACES = 2;
var SMALL_PLACES = 10;
function formatNumber(x, decimal = false, places = DATA_PLACES) {
  if (!isNumber(x)) return EMPTY;
  if (!decimal && Number.isInteger(x)) return formatFixed(x, 0);
  const fixed = formatFixed(x, places);
  if (x === 0 || /[1-9]/.test(fixed)) return fixed;
  for (let more = places + 1; more <= SMALL_PLACES; more++) {
    const small = formatFixed(x, more);
    const significant = small.replace(/^-?0\.0*/, "");
    if (significant.length >= 2 || more === SMALL_PLACES && /[1-9]/.test(significant)) return small.replace(/0+$/, "");
  }
  const smallest = "0." + "0".repeat(SMALL_PLACES - 1) + "1";
  return x > 0 ? `< ${smallest}` : `> -${smallest}`;
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
function formatValue(value, decimal = false, places = DATA_PLACES) {
  if (value === null || value === void 0) return EMPTY;
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "number") return formatNumber(value, decimal, places);
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
var FEATURE_LIMIT = 10;
var WIDE_TEXT = 24;
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
  sigma: "Residual SD",
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
  tree_ensemble: "Tree ensemble model"
};
var ALTERNATIVES = { "two.sided": "Two-sided", less: "One-sided (less)", greater: "One-sided (greater)" };
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
  tips = { prefix: tipPrefix(doc), count: 0 };
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
  const caption = renderCaption(doc, kind);
  const inner = [`<div class="fw-embed__body">`, body, "</div>"];
  if (kind === "page") return [open + ">", ...inner, caption, "</figure>", ""].join("\n");
  return [open + ">", caption, ...inner, ...renderAbout(doc), "</figure>", ""].join("\n");
}
function renderCaption(doc, kind) {
  const parts = ['<figcaption class="fw-embed__caption">'];
  if (kind !== "page" && text(doc.title) !== null) parts.push(`<span class="fw-embed__title">${escapeHtml(doc.title)}</span>`);
  const attribution = isObject(doc.attribution) ? doc.attribution : {};
  const credit = escapeHtml(text(attribution.text) ?? "Framework");
  const href = safeUrl(attribution.url) ?? safeUrl(doc.url);
  parts.push(href === null ? `<span class="fw-embed__credit">${credit}</span>` : `<a class="fw-embed__credit" href="${escapeHtml(href)}" target="_blank" rel="noopener">${credit}</a>`);
  parts.push("</figcaption>");
  return parts.join("\n");
}
function renderAbout(doc) {
  const parts = [];
  if (text(doc.description) !== null) parts.push(`<span class="fw-embed__description">${escapeHtml(doc.description)}</span>`);
  const author = isObject(doc.author) ? doc.author : {};
  if (text(author.name) !== null) {
    const href = safeUrl(author.url);
    const name = escapeHtml(author.name);
    parts.push(`<span class="fw-embed__author">${href === null ? name : `<a href="${escapeHtml(href)}">${name}</a>`}</span>`);
  }
  return parts.length === 0 ? [] : ['<div class="fw-embed__about">', ...parts, "</div>"];
}
var tips = { prefix: "fw-embed-tip-", count: 0 };
function tipPrefix(doc) {
  const key = text(doc.key);
  if (key === null || !/^[A-Za-z0-9_-]+$/.test(key)) return "fw-embed-tip-";
  return `fw-embed-tip-${key}-${Number.isInteger(doc.version) ? doc.version : 0}-`;
}
function tip(label, blocks) {
  const id = `${tips.prefix}${++tips.count}`;
  return `<button type="button" class="fw-embed__term" popovertarget="${id}">${escapeHtml(label)}</button><span class="fw-embed__tip" id="${id}" popover="">${blocks.join("")}</span>`;
}
function tipBlock(name, label, detail) {
  let html = `<span class="fw-embed__tip-name">${escapeHtml(name)}</span>`;
  if (label !== null) html += `<span class="fw-embed__tip-label">${escapeHtml(label)}</span>`;
  if (detail !== "") html += `<span class="fw-embed__tip-detail">${escapeHtml(detail)}</span>`;
  return `<span class="fw-embed__tip-block">${html}</span>`;
}
function count(x) {
  return formatNumber(x);
}
function plural(n, one, many) {
  return `${count(n)} ${n === 1 ? one : many}`;
}
function summaryDetail(type, summary, levels, level, reference) {
  const sentences = [];
  const s = isObject(summary) ? summary : {};
  const stat = (x) => formatValue(x, false, RESULT_PLACES);
  if (level !== null) {
    if (reference !== null && reference !== level) sentences.push(`Compared with ${reference}.`);
    const top = list(s.top).find((entry) => isObject(entry) && isLevel(entry.value, level) && Number.isInteger(entry.count));
    if (top !== void 0 && Number.isInteger(s.count)) sentences.push(`${count(top.count)} of ${plural(s.count, "row", "rows")}.`);
    return sentences.join(" ");
  }
  if (type === "number") {
    const parts = [];
    if (isNumber(s.mean)) parts.push(`mean ${stat(s.mean)}`);
    if (isNumber(s.sd)) parts.push(`SD ${stat(s.sd)}`);
    if (isNumber(s.min) && isNumber(s.max)) {
      const decimal = !Number.isInteger(s.min) || !Number.isInteger(s.max);
      parts.push(`range ${formatValue(s.min, decimal, RESULT_PLACES)} to ${formatValue(s.max, decimal, RESULT_PLACES)}`);
    }
    if (parts.length > 0) sentences.push(asciiCapitalize(parts.join(", ")) + ".");
  } else if (type === "date" || type === "datetime") {
    if (typeof s.min === "string" && typeof s.max === "string") sentences.push(`From ${s.min} to ${s.max}.`);
  } else {
    if (levels.length > 0) sentences.push(`${plural(levels.length, "level", "levels")}${reference !== null ? `, compared with ${reference}` : ""}.`);
    else if (Number.isInteger(s.distinct)) sentences.push(`${plural(s.distinct, "distinct value", "distinct values")}.`);
    const top = list(s.top).filter((entry) => isObject(entry) && Number.isInteger(entry.count)).slice(0, 3);
    if (top.length > 0) sentences.push(`Most common: ${top.map((entry) => `${formatValue(entry.value)} (${count(entry.count)})`).join(", ")}.`);
  }
  if (Number.isInteger(s.count)) {
    const missing = Number.isInteger(s.missing) && s.missing > 0 ? `, ${count(s.missing)} missing` : "";
    sentences.push(`${plural(s.count, "row", "rows")}${missing}.`);
  }
  return sentences.join(" ");
}
function isLevel(value, level) {
  if (typeof value === "string") return value === level;
  if (typeof value === "boolean") return asciiLower(level) === String(value);
  return Number.isInteger(value) && String(value) === level;
}
function modelVariables(doc) {
  const variables = /* @__PURE__ */ new Map();
  for (const variable of list(doc.data?.variables)) {
    if (isObject(variable) && text(variable.name) !== null && !variables.has(variable.name)) variables.set(variable.name, variable);
  }
  return variables;
}
function termCell(label, doc) {
  const entry = list(doc.data?.terms).find((term) => isObject(term) && term.term === label);
  if (entry === void 0) return escapeHtml(label);
  const variables = modelVariables(doc);
  const blocks = [];
  for (const part of list(entry.parts)) {
    const variable = isObject(part) ? variables.get(part.variable) : void 0;
    if (variable === void 0) return escapeHtml(label);
    const level = text(part.level);
    const levels = list(variable.levels).filter((value) => typeof value === "string");
    const name = level === null ? variable.name : `${variable.name} = ${level}`;
    const variableLabel = text(variable.label);
    blocks.push(tipBlock(
      name,
      variableLabel === variable.name ? null : variableLabel,
      summaryDetail(variable.type, variable.summary, levels, level, text(variable.reference))
    ));
  }
  return blocks.length === 0 ? escapeHtml(label) : tip(label, blocks);
}
function columnHeader(column) {
  const label = columnLabel(column, false);
  const detail = summaryDetail(column.type === "number" || column.type === "date" || column.type === "datetime" ? column.type : "string", column.summary, [], null, null);
  const named2 = label !== column.name;
  if (detail === "" && !named2) return escapeHtml(label);
  return tip(label, [tipBlock(column.name, named2 ? label : null, detail)]);
}
function usableTables(data) {
  return list(data.tables).filter((table) => isObject(table) && Array.isArray(table.rows) && Array.isArray(table.columns) && table.columns.length > 0 && table.columns.every((column) => isObject(column) && text(column.name) !== null));
}
function usableMetrics(data) {
  return list(data.metrics).filter((metric) => isObject(metric) && text(metric.name) !== null);
}
function details(label, blocks) {
  if (blocks.length === 0) return [];
  return [`<details class="fw-embed__details">`, `<summary>${label}</summary>`, ...blocks, "</details>"];
}
function isCoefficientTable(table, index) {
  return (table.role === "coefficients" || (table.role === null || table.role === void 0) && index === 0) && columnIndex(table, "term") >= 0 && columnIndex(table, "estimate") >= 0;
}
function isCommand(metric) {
  return metric.name === "command" && text(metric.value) !== null;
}
function renderModel(doc) {
  const data = doc.data;
  if (!isObject(data)) return null;
  const tables = usableTables(data);
  const metrics = usableMetrics(data);
  let formula = text(data.formula);
  if (tables.length === 0 && metrics.length === 0 && formula === null) return null;
  const command = metrics.find(isCommand);
  if (formula === null && command !== void 0) formula = command.value;
  const stats = metrics.filter((metric) => !isCommand(metric));
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
  const coefficients = tables.map((table, index) => isCoefficientTable(table, index));
  const importance = coefficients.includes(true) ? -1 : tables.findIndex((table) => table.role === "importance");
  const visible = [];
  const rest = [];
  tables.forEach((table, index) => {
    if (coefficients[index]) {
      visible.push(renderCoefficients(table, model, level, doc));
    } else if (index === importance) {
      const continued = shownRows(table, FEATURE_LIMIT, ROW_LIMIT).length > 0;
      visible.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, FEATURE_LIMIT, continued ? "Model details" : null));
      if (continued) rest.push(renderTable(table, true, true, doc, RESULT_PLACES, FEATURE_LIMIT, ROW_LIMIT, null));
    } else if (table.role === "random_effects") {
      visible.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, ROW_LIMIT, null));
    } else {
      rest.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, ROW_LIMIT, null));
    }
  });
  if (stats.length > 0) rest.push(renderMetrics(stats));
  if (visible.length === 0) return [...parts, ...rest].join("\n");
  return [...visible, ...details("Model details", [...parts, ...rest])].join("\n");
}
function renderTableKind(doc) {
  const data = doc.data;
  if (!isObject(data)) return null;
  const tables = usableTables(data);
  if (tables.length === 0) return null;
  const parts = tables.map((table) => renderTable(table, tables.length > 1, false, doc, DATA_PLACES, 0, ROW_LIMIT, null));
  const metrics = usableMetrics(data);
  if (metrics.length > 0) parts.push(renderMetrics(metrics));
  return parts.join("\n");
}
function intervalText(low, high) {
  return `${isNumber(low) ? formatNumber(low, true, RESULT_PLACES) : "-\u221E"} to ${isNumber(high) ? formatNumber(high, true, RESULT_PLACES) : "\u221E"}`;
}
function renderTest(doc) {
  const data = doc.data;
  if (!isObject(data) || !isObject(data.test) || text(data.test.method) === null) return null;
  const test = data.test;
  const result = (value) => formatNumber(value, false, RESULT_PLACES);
  const main = [];
  if (isObject(test.statistic) && text(test.statistic.name) !== null) main.push([test.statistic.name, result(test.statistic.value), true]);
  if (isNumber(test.p_value)) main.push(["p-value", formatPValue(test.p_value), true]);
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
  if (typeof test.alternative === "string" && Object.hasOwn(ALTERNATIVES, test.alternative)) extra.push(["Alternative", ALTERNATIVES[test.alternative], false]);
  for (const entry of named(test.parameters)) extra.push([entry.name, result(entry.value), true]);
  for (const entry of named(test.null_value)) extra.push([`Null value: ${entry.name}`, result(entry.value), true]);
  const rest = [];
  if (extra.length > 0) rest.push(renderPairs(extra));
  for (const table of usableTables(data)) rest.push(renderTable(table, true, true, doc, RESULT_PLACES, 0, ROW_LIMIT, null));
  const metrics = usableMetrics(data);
  if (metrics.length > 0) rest.push(renderMetrics(metrics));
  const parts = [`<p class="fw-embed__method">${escapeHtml(test.method)}</p>`];
  if (main.length > 0) parts.push(renderPairs(main));
  return [...parts, ...details("Test details", rest)].join("\n");
}
function named(value) {
  return list(value).filter((entry) => isObject(entry) && text(entry.name) !== null);
}
var FAMILY_NAMES = { gaussian: "Gaussian", tweedie: "Tweedie", poisson: "Poisson" };
var LINK_NAMES = { sqrt: "square-root", cloglog: "complementary log-log" };
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
function modelName(model) {
  const lower = (value) => text(value) === null ? null : asciiLower(value);
  const type = lower(model.type);
  const family = lower(model.family);
  const link = lower(model.link);
  const withLink = (name, implied) => link === null || link === implied ? name : `${name} (${linkName(link)} link)`;
  if (type === "linear") return "Ordinary least squares regression";
  if (type === "glm") {
    if (family === "gaussian" && (link === null || link === "identity")) return "Ordinary least squares regression";
    if (family === "binomial") {
      if (link === null || link === "logit") return "Logistic regression";
      if (link === "probit") return "Probit regression";
      if (link === "cloglog") return "Complementary log-log regression";
      return `Binomial regression (${linkName(link)} link)`;
    }
    if (family === "poisson") return withLink("Poisson regression", "log");
    if (family === "negative binomial") return withLink("Negative binomial regression", "log");
    if (family === "gamma") return link === null ? "Gamma regression" : `Gamma regression (${linkName(link)} link)`;
    if (family !== null && family.startsWith("quasi")) {
      const rest = family.slice(5).replace(/^[- ]+/, "");
      return rest === "" ? "Quasi-likelihood regression" : `Quasi-${rest === "poisson" ? "Poisson" : rest} regression`;
    }
    return null;
  }
  if (type === "mixed") {
    if (family === null || family === "gaussian") return withLink("Linear mixed model", "identity");
    if (family === "binomial") {
      if (link === null || link === "logit") return "Logistic mixed model";
      if (link === "probit") return "Probit mixed model";
      return `Binomial mixed model (${linkName(link)} link)`;
    }
    if (family === "poisson") return withLink("Poisson mixed model", "log");
    if (family === "negative binomial") return withLink("Negative binomial mixed model", "log");
    if (family === "gamma") return link === null ? "Gamma mixed model" : `Gamma mixed model (${linkName(link)} link)`;
    return null;
  }
  if (type === "survival") return text(model.exponentiate) === "Hazard ratio" ? "Cox proportional hazards model" : "Parametric survival model";
  if (type === "ordinal") {
    if (link === null || link === "logit") return "Ordinal logistic regression";
    if (link === "probit") return "Ordinal probit regression";
    return `Ordinal regression (${linkName(link)} link)`;
  }
  if (type === "multinomial") return "Multinomial logistic regression";
  if (type === "additive") {
    const detail = family === null || family === "gaussian" ? null : familyLink(family, link);
    return detail === null ? "Generalized additive model" : `Generalized additive model (${detail})`;
  }
  if (type === "fixed_effects") {
    if (family === "poisson") return "Fixed-effects Poisson regression";
    if (family === "binomial" && (link === null || link === "logit")) return "Fixed-effects logistic regression";
    return "Fixed-effects regression";
  }
  if (type === "quantile") return "Quantile regression";
  if (type === "linear_ml") return "Linear model";
  if (type === "tree_ensemble") return "Tree ensemble model";
  return null;
}
function modelMeta(model) {
  const parts = [];
  const name = modelName(model);
  if (name !== null) {
    parts.push(name);
  } else {
    if (text(model.type) !== null && model.type !== "other") parts.push(Object.hasOwn(MODEL_TYPES, model.type) ? MODEL_TYPES[model.type] : model.type);
    const detail = familyLink(text(model.family), text(model.link));
    if (detail !== null) parts.push(detail);
  }
  if (text(model.response) !== null) parts.push(`Outcome: ${model.response}`);
  return parts.length > 0 ? asciiCapitalize(parts.join(" \xB7 ")) : null;
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
function numberSpan(count2) {
  return `<span class="fw-embed__num">${escapeHtml(formatNumber(count2))}</span>`;
}
function tableNotes(table, rows, start, doc, rest) {
  const notes = [];
  const total = Number.isInteger(table.row_count) ? table.row_count : table.rows.length;
  const columnTotal = Number.isInteger(table.column_count) ? table.column_count : table.columns.length;
  const href = safeUrl(doc.url);
  const link = href === null ? "" : ` <a href="${escapeHtml(href)}">View full table</a>`;
  if (start > 0) {
    const last = start + rows.length;
    notes.push(`<p class="fw-embed__note">Showing rows ${numberSpan(start + 1)} to ${numberSpan(last)} of ${numberSpan(total)}.${total > last ? link : ""}</p>`);
  } else {
    const parts = [];
    if (total > rows.length) parts.push(`${numberSpan(rows.length)} of ${numberSpan(total)} rows`);
    if (rest === null && columnTotal > table.columns.length) parts.push(`${numberSpan(table.columns.length)} of ${numberSpan(columnTotal)} columns`);
    if (parts.length > 0) notes.push(`<p class="fw-embed__note">Showing ${parts.join(" and ")}.${rest !== null ? ` The rest are in ${rest}.` : link}</p>`);
  }
  if (rest === null && text(table.precision_note) !== null) notes.push(`<p class="fw-embed__note">${escapeHtml(table.precision_note)}</p>`);
  return notes;
}
function renderTable(table, showCaption, modelLabels, doc, places, start, end, rest) {
  const rows = shownRows(table, start, end);
  const sample = shownRows(table, 0, ROW_LIMIT);
  const columns = table.columns;
  const decimal = columns.map((_, index) => isDecimalColumn(sample, index));
  const lines = openTable(table, showCaption, null);
  lines.push("<thead><tr>" + columns.map((column) => modelLabels ? th(columnLabel(column, true), column.type === "number") : `<th scope="col"${column.type === "number" ? ' class="fw-embed__num"' : ""}>${columnHeader(column)}</th>`).join("") + "</tr></thead>");
  lines.push("<tbody>");
  for (const row of rows) {
    lines.push("<tr>" + columns.map((column, index) => td(formatCell(cell(row, index), column, decimal[index], places), column.type === "number")).join("") + "</tr>");
  }
  lines.push("</tbody>", "</table>", "</div>");
  return [...lines, ...tableNotes(table, rows, start, doc, rest)].join("\n");
}
function coefficientTable(table, doc, modifier, columns) {
  const rows = shownRows(table, 0, ROW_LIMIT);
  const term = columnIndex(table, "term");
  const lines = openTable(table, true, modifier);
  lines.push("<thead><tr>" + th(columnLabel(table.columns[term], true), false) + columns.map(([header, numeric]) => th(header, numeric)).join("") + "</tr></thead>", "<tbody>");
  for (const row of rows) {
    lines.push(`<tr><th scope="row">${termCell(formatValue(cell(row, term)), doc)}</th>` + columns.map(([, numeric, value]) => td(value(row), numeric)).join("") + "</tr>");
  }
  lines.push("</tbody>", "</table>", "</div>");
  return [...lines, ...tableNotes(table, rows, 0, doc, null)].join("\n");
}
function extraColumns(table) {
  const rows = shownRows(table, 0, ROW_LIMIT);
  const columns = [];
  table.columns.forEach((column, index) => {
    if (COEFFICIENT_COLUMNS.has(column.name)) return;
    const decimal = isDecimalColumn(rows, index);
    columns.push([columnLabel(column, true), column.type === "number", (row) => formatCell(cell(row, index), column, decimal, RESULT_PLACES)]);
  });
  return columns;
}
function intervalLabel(level) {
  return level === null ? "CI" : `${formatPercent(level)} CI`;
}
function decimalCell(index) {
  return (row) => formatValue(cell(row, index), true, RESULT_PLACES);
}
var LOG_SCALES = { "Odds ratio": "Log odds", "Hazard ratio": "Log hazard", "Rate ratio": "Log rate", "Incidence rate ratio": "Log rate", "Time ratio": "Log time" };
function renderCoefficients(table, model, level, doc) {
  const ratio = text(model.exponentiate);
  if (ratio === null) return coefficientTable(table, doc, "coefficients", coefficientColumns(table, level, "Estimate", false));
  const logScale = Object.hasOwn(LOG_SCALES, ratio) ? LOG_SCALES[ratio] : "Coefficient";
  return [
    '<form class="fw-embed__scales">',
    `<div class="fw-embed__switch" role="radiogroup" aria-label="Scale">`,
    `<label><input type="radio" name="fw-embed-scale" value="ratio" checked=""> ${escapeHtml(ratio)}</label>`,
    `<label><input type="radio" name="fw-embed-scale" value="model"> ${escapeHtml(logScale)}</label>`,
    "</div>",
    '<div class="fw-embed__scale fw-embed__scale--ratio">',
    coefficientTable(table, doc, "coefficients", coefficientColumns(table, level, ratio, true)),
    "</div>",
    '<div class="fw-embed__scale fw-embed__scale--model">',
    coefficientTable(table, doc, "coefficients", coefficientColumns(table, level, logScale, false)),
    "</div>",
    "</form>"
  ].join("\n");
}
function coefficientColumns(table, level, estimateLabel, exponentiated) {
  const at = (name) => columnIndex(table, name);
  const estimate = at("estimate");
  const low = at("conf_low");
  const high = at("conf_high");
  const shown = (value) => exponentiated ? ratioValue(value) : formatValue(value, true, RESULT_PLACES);
  const columns = extraColumns(table);
  columns.push([estimateLabel, true, (row) => shown(cell(row, estimate))]);
  if (at("std_error") >= 0) {
    const index = at("std_error");
    columns.push(["Std. error", true, (row) => {
      const se = cell(row, index);
      const b = cell(row, estimate);
      if (!exponentiated) return formatValue(se, true, RESULT_PLACES);
      return isNumber(se) && isNumber(b) ? formatNumber(exp(b) * se, true, RESULT_PLACES) : formatValue(se, true, RESULT_PLACES);
    }]);
  }
  if (at("statistic") >= 0) columns.push(["Statistic", true, decimalCell(at("statistic"))]);
  if (at("p_value") >= 0) columns.push(["p-value", true, pValueCell(at("p_value"))]);
  if (low >= 0 && high >= 0) {
    columns.push([intervalLabel(level), true, (row) => {
      const a = cell(row, low);
      const b = cell(row, high);
      return a === null && b === null ? EMPTY : `${shown(a)} to ${shown(b)}`;
    }]);
  }
  return columns;
}
function pValueCell(index) {
  return (row) => isNumber(cell(row, index)) ? formatPValue(cell(row, index)) : formatValue(cell(row, index));
}
function ratioValue(value) {
  return isNumber(value) ? formatNumber(exp(value), true, RESULT_PLACES) : formatValue(value, true, RESULT_PLACES);
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
  return formatValue(value, false, RESULT_PLACES);
}
function renderMetrics(metrics) {
  return renderPairs(metrics.map((metric) => [metricLabel(metric.name), metricValue(metric.name, metric.value), isNumber(metric.value)]));
}
function renderPairs(pairs) {
  return ['<dl class="fw-embed__stats">', ...pairs.map(([term, value, numeric]) => {
    if (numeric) return `<div><dt>${escapeHtml(term)}</dt><dd class="fw-embed__num">${escapeHtml(value)}</dd></div>`;
    const wide = [...value].length > WIDE_TEXT ? " fw-embed__stat--wide" : "";
    return `<div class="fw-embed__stat--text${wide}"><dt>${escapeHtml(term)}</dt><dd>${escapeHtml(value)}</dd></div>`;
  }), "</dl>"].join("\n");
}
function renderImage(doc) {
  const image = isObject(doc.image) ? doc.image : null;
  const src = image === null ? null : safeUrl(image.url);
  if (src === null) return null;
  const alt = text(doc.alt) ?? text(doc.title) ?? "";
  const type = text(image.content_type);
  const vector = type !== null && asciiLower(type).startsWith("image/svg+xml");
  let tag = `<img class="fw-embed__img${vector ? " fw-embed__img--vector" : ""}" src="${escapeHtml(src)}" alt="${escapeHtml(alt)}" loading="lazy" decoding="async"`;
  if (Number.isInteger(image.width) && image.width > 0 && Number.isInteger(image.height) && image.height > 0) {
    tag += ` width="${image.width}" height="${image.height}"`;
    if (vector) tag += ` style="max-width: min(100%, calc(80vh * ${image.width} / ${image.height}))"`;
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
    `<p class="fw-embed__fallback">${alt}${href === null ? "" : ` <a href="${escapeHtml(href)}">View chart</a>`}</p>`,
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
  if (doc === null || typeof doc !== "object" || doc.schema !== "framework.embed/v1") throw new EmbedError("invalid", "The response is not a framework.embed/v1 document.");
  return doc;
}
function mount(element, doc, options = {}) {
  element.innerHTML = render(doc);
  enhanceCharts(element, options);
  enhanceTips(element);
  return element.firstElementChild;
}
var tipsWatched = false;
var tipSerial = 0;
function placeTip(tip2) {
  const button = tip2.previousElementSibling;
  if (!button || typeof tip2.getBoundingClientRect !== "function") return;
  tip2.setAttribute("data-fw-embed-placed", "");
  const anchor = button.getBoundingClientRect();
  const box = tip2.getBoundingClientRect();
  const gap = 6;
  const width = document.documentElement.clientWidth;
  const height = document.documentElement.clientHeight;
  let top = anchor.bottom + gap;
  if (top + box.height > height - 8 && anchor.top - gap - box.height >= 8) top = anchor.top - gap - box.height;
  const left = Math.max(8, Math.min(anchor.left, width - box.width - 8));
  tip2.style.top = `${Math.round(top)}px`;
  tip2.style.left = `${Math.round(left)}px`;
}
function isOpen(tip2) {
  try {
    return tip2.matches(":popover-open");
  } catch {
    return false;
  }
}
function show(tip2) {
  if (typeof tip2.showPopover === "function" && !isOpen(tip2)) {
    try {
      tip2.showPopover();
    } catch {
    }
  }
}
function hide(tip2) {
  if (typeof tip2.hidePopover === "function" && isOpen(tip2)) {
    try {
      tip2.hidePopover();
    } catch {
    }
  }
}
function enhanceTips(root = document) {
  if (typeof document === "undefined") return;
  for (const button of root.querySelectorAll(".fw-embed__term[popovertarget]")) {
    const tip2 = button.nextElementSibling;
    if (!tip2 || !tip2.classList.contains("fw-embed__tip")) continue;
    if (document.getElementById(tip2.id) !== tip2) {
      tip2.id = `fw-embed-tip-r${++tipSerial}`;
      button.setAttribute("popovertarget", tip2.id);
    }
  }
  if (tipsWatched) return;
  tipsWatched = true;
  let timer = null;
  let hovered = null;
  const later = (fn) => {
    clearTimeout(timer);
    timer = setTimeout(fn, 120);
  };
  document.addEventListener("toggle", (event) => {
    const tip2 = event.target;
    if (tip2 instanceof Element && tip2.classList.contains("fw-embed__tip") && event.newState === "open") placeTip(tip2);
  }, true);
  document.addEventListener("pointerover", (event) => {
    if (event.pointerType !== "mouse" || !(event.target instanceof Element)) return;
    const button = event.target.closest(".fw-embed__term");
    const tip2 = event.target.closest(".fw-embed__tip") ?? button?.nextElementSibling;
    if (!tip2 || !tip2.classList.contains("fw-embed__tip")) return;
    hovered = tip2;
    later(() => {
      if (hovered === tip2) show(tip2);
    });
  });
  document.addEventListener("pointerout", (event) => {
    if (event.pointerType !== "mouse" || !(event.target instanceof Element)) return;
    const from = event.target.closest(".fw-embed__term, .fw-embed__tip");
    if (!from) return;
    const to = event.relatedTarget instanceof Element ? event.relatedTarget.closest(".fw-embed__term, .fw-embed__tip") : null;
    const tip2 = from.classList.contains("fw-embed__tip") ? from : from.nextElementSibling;
    if (to === from || to === tip2 || to === tip2?.previousElementSibling) return;
    hovered = null;
    later(() => {
      if (hovered !== tip2 && tip2 && !tip2.hasAttribute("data-fw-embed-pinned")) hide(tip2);
    });
  });
  document.addEventListener("click", (event) => {
    if (!(event.target instanceof Element)) return;
    const button = event.target.closest(".fw-embed__term");
    if (!button) return;
    const tip2 = button.nextElementSibling;
    if (!tip2 || !tip2.classList.contains("fw-embed__tip")) return;
    if (isOpen(tip2) && !tip2.hasAttribute("data-fw-embed-pinned")) {
      event.preventDefault();
      tip2.setAttribute("data-fw-embed-pinned", "");
      return;
    }
    if (isOpen(tip2)) tip2.removeAttribute("data-fw-embed-pinned");
    else tip2.setAttribute("data-fw-embed-pinned", "");
  }, true);
  document.addEventListener("toggle", (event) => {
    if (event.target instanceof Element && event.target.classList.contains("fw-embed__tip") && event.newState === "closed") event.target.removeAttribute("data-fw-embed-pinned");
  }, true);
  window.addEventListener("scroll", () => {
    for (const tip2 of document.querySelectorAll(".fw-embed__tip[data-fw-embed-placed]")) if (isOpen(tip2)) placeTip(tip2);
  }, { passive: true, capture: true });
  window.addEventListener("resize", () => {
    for (const tip2 of document.querySelectorAll(".fw-embed__tip")) hide(tip2);
  });
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
  let tip2 = null;
  return (handler, event, item, value) => {
    const text2 = tooltipText(value);
    if (text2 === "") {
      if (tip2) tip2.hidden = true;
      return;
    }
    if (!tip2) {
      tip2 = document.createElement("div");
      tip2.className = "fw-embed__tooltip";
      tip2.setAttribute("role", "tooltip");
      document.body.append(tip2);
    }
    tip2.textContent = text2;
    tip2.hidden = false;
    tip2.style.left = `${(event?.clientX ?? 0) + 12}px`;
    tip2.style.top = `${(event?.clientY ?? 0) + 12}px`;
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
var printing = false;
function expandDetailsForPrint() {
  if (printing || typeof window === "undefined" || typeof window.addEventListener !== "function") return;
  printing = true;
  let opened = [];
  window.addEventListener("beforeprint", () => {
    opened = [...document.querySelectorAll("details.fw-embed__details:not([open])")];
    for (const details2 of opened) details2.open = true;
  });
  window.addEventListener("afterprint", () => {
    for (const details2 of opened) details2.open = false;
    opened = [];
  });
}
export {
  DATA_PLACES,
  DEFAULT_HOSTS,
  DEFAULT_VEGA_SCRIPTS,
  EmbedError,
  FEATURE_LIMIT,
  FrameworkArtifactElement,
  RESULT_PLACES,
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
  enhanceTips,
  escapeHtml,
  expandDetailsForPrint,
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

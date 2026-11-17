// Number and value formatting. SPEC.md, "Number formatting", defines every rule
// here; php/src/Format.php implements the same rules and both are checked
// against spec/format-cases.json.

export const EMPTY = '—';

const P_VALUE_NAMES = new Set(['p_value', 'p.value', 'p-value', 'pvalue', 'pr(>|t|)', 'pr(>|z|)', 'pr(>chisq)', 'p>|t|', 'p>|z|']);

export function isPValueName(name) {
    return typeof name === 'string' && P_VALUE_NAMES.has(asciiLower(name));
}

export function asciiLower(value) {
    return value.replace(/[A-Z]/g, (c) => c.toLowerCase());
}

export function asciiCapitalize(value) {
    return /^[a-z]/.test(value) ? value[0].toUpperCase() + value.slice(1) : value;
}

export const isNumber = (value) => typeof value === 'number' && Number.isFinite(value);

// The shortest round-trip decimal digits of |x| and the exponent of the first digit.
function decompose(x) {
    const [mantissa, exponent] = Math.abs(x).toExponential().split('e');
    const digits = mantissa.replace('.', '').replace(/0+$/, '') || '0';
    return { digits, exp: Number(exponent) };
}

function increment(digits) {
    const out = digits.split('');
    let i = out.length - 1;
    while (i >= 0) {
        if (out[i] === '9') { out[i] = '0'; i -= 1; } else { out[i] = String(Number(out[i]) + 1); return out.join(''); }
    }
    return '1' + out.join('');
}

// |x| rounded half away from zero to `places` decimals, from its shortest decimal form.
function fixedDigits(x, places) {
    let whole;
    if (x === 0) {
        whole = '0';
    } else {
        const { digits, exp } = decompose(x);
        const keep = exp + 1 + places;
        if (keep < 0) {
            whole = '0';
        } else if (keep === 0) {
            whole = digits[0] >= '5' ? '1' : '0';
        } else {
            let head = digits.length >= keep ? digits.slice(0, keep) : digits + '0'.repeat(keep - digits.length);
            if (digits.length > keep && digits[keep] >= '5') head = increment(head);
            whole = head.replace(/^0+(?=\d)/, '');
        }
    }
    if (places === 0) return whole;
    whole = whole.padStart(places + 1, '0');
    return whole.slice(0, -places) + '.' + whole.slice(-places);
}

function group(integer) {
    if (integer.length < 5) return integer;
    let out = '';
    for (let i = 0; i < integer.length; i++) {
        if (i > 0 && (integer.length - i) % 3 === 0) out += ',';
        out += integer[i];
    }
    return out;
}

function signed(x, body) {
    return x < 0 && /[1-9]/.test(body) ? '-' + body : body;
}

export function formatFixed(x, places = 3) {
    if (!isNumber(x)) return EMPTY;
    const [integer, fraction] = fixedDigits(x, places).split('.');
    const body = fraction === undefined ? group(integer) : group(integer) + '.' + fraction;
    return signed(x, body);
}

export function formatScientific(x, significant = 3) {
    if (!isNumber(x)) return EMPTY;
    if (x === 0) return '0.' + '0'.repeat(significant - 1) + 'e+0';
    let { digits, exp } = decompose(x);
    let head = digits.length >= significant ? digits.slice(0, significant) : digits + '0'.repeat(significant - digits.length);
    if (digits.length > significant && digits[significant] >= '5') head = increment(head);
    if (head.length > significant) { head = head.slice(0, significant); exp += 1; }
    const mantissa = significant > 1 ? head[0] + '.' + head.slice(1) : head;
    return (x < 0 ? '-' : '') + mantissa + 'e' + (exp < 0 ? '-' : '+') + Math.abs(exp);
}

// Decimal places: 3 for data tables, 2 for model and test results.
export const DATA_PLACES = 3;
export const RESULT_PLACES = 2;
// A number in a table cell, statistic, or metric. `decimal` forces `places` decimals for whole numbers.
// No scientific notation: large numbers are written in full. A nonzero number that rounds to zero
// at `places` gets the decimals it needs for two significant digits (0.00031, not "< 0.01"), up to
// SMALL_PLACES; past that it is written as "< 0.0000000001" (or "> -0.0000000001").
export const SMALL_PLACES = 10;
export function formatNumber(x, decimal = false, places = DATA_PLACES) {
    if (!isNumber(x)) return EMPTY;
    if (!decimal && Number.isInteger(x)) return formatFixed(x, 0);
    const fixed = formatFixed(x, places);
    if (x === 0 || /[1-9]/.test(fixed)) return fixed;
    for (let more = places + 1; more <= SMALL_PLACES; more++) {
        const small = formatFixed(x, more);
        const significant = small.replace(/^-?0\.0*/, '');
        if (significant.length >= 2 || (more === SMALL_PLACES && /[1-9]/.test(significant))) return small.replace(/0+$/, '');
    }
    const smallest = '0.' + '0'.repeat(SMALL_PLACES - 1) + '1';
    return x > 0 ? `< ${smallest}` : `> -${smallest}`;
}

export function formatPValue(p) {
    if (!isNumber(p)) return formatValue(p);
    return p < 0.001 ? '< 0.001' : formatFixed(p, 3);
}

export function formatPercent(x) {
    if (!isNumber(x)) return formatValue(x);
    const percent = x * 100;
    return isNumber(percent) ? formatFixed(percent, 1).replace(/\.0$/, '') + '%' : EMPTY;
}

export function formatValue(value, decimal = false, places = DATA_PLACES) {
    if (value === null || value === undefined) return EMPTY;
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'number') return formatNumber(value, decimal, places);
    if (typeof value === 'string') return value;
    return EMPTY;
}

// fdlibm's exp, using only IEEE basic operations so JS and PHP get the same bits
// (Math.exp and libm exp may differ in the last place, which changes rounding).
const LN2_HI = [6.93147180369123816490e-01, -6.93147180369123816490e-01];
const LN2_LO = [1.90821492927058770002e-10, -1.90821492927058770002e-10];
const INV_LN2 = 1.44269504088896338700e+00;
const P1 = 1.66666666666666019037e-01;
const P2 = -2.77777777770155933842e-03;
const P3 = 6.61375632143793436117e-05;
const P4 = -1.65339022054652515390e-06;
const P5 = 4.13813679705723846039e-08;

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

export function exp(x) {
    if (!isNumber(x)) return NaN;
    if (x > 7.09782712893383973096e+02) return Infinity;
    if (x < -7.45133219101941108420e+02) return 0;
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
    } else if (size < 3.725290298461914e-9) {
        return 1 + x;
    }
    const t = x * x;
    const c = x - t * (P1 + t * (P2 + t * (P3 + t * (P4 + t * P5))));
    if (k === 0) return 1 - ((x * c) / (c - 2) - x);
    const y = 1 - ((lo - (x * c) / (2 - c)) - hi);
    return k >= -1021 ? y * pow2(k) : y * pow2(k + 1000) * pow2(-1000);
}

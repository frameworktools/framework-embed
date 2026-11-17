// Regenerates spec/format-cases.json: number formatting cases both renderers must reproduce.
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { formatNumber, formatPValue, formatPercent } from '../src/format.js';

const fixed = [0, 1, -1, 2026, 9999, 10000, -10000, 123456789, 0.5, 0.0625, 1.0005, 2.0005, -1.0005, 0.1 + 0.2, 1 / 3, -2 / 3,
    9999.9995, 9999.9996, 99999.9995, 12345.6785, 0.001, 0.0009995, 0.00099949, -0.0004, 2.5e-5, 1e-300, 5e-324,
    999999999999999, 1e15, -1.23456e16, 1.7976931348623157e308, 3.14159, 2.675, 1.005, 8.345, 1e-7, 0.000123456, 4.35, 0.045];
let seed = 20261116;
const random = () => ((seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648);
for (let i = 0; i < 300; i++) {
    const exponent = Math.floor(random() * 24) - 8;
    fixed.push(Number(((random() - 0.4) * 10 ** exponent).toPrecision(1 + Math.floor(random() * 17))));
}
const cases = [];
for (const x of fixed) {
    cases.push({ format: 'number', value: x, expected: formatNumber(x) });
    cases.push({ format: 'decimal', value: x, expected: formatNumber(x, true) });
}
for (const p of [0, 1e-12, 0.000999, 0.001, 0.0005, 0.0105, 0.0606345078809341, 0.5, 0.9995, 1]) cases.push({ format: 'p', value: p, expected: formatPValue(p) });
for (const x of [0.95, 0.94999999999999996, 0.9, 0.99, 0.999, 0.8, 0.975, 0.5, 0.123456]) cases.push({ format: 'percent', value: x, expected: formatPercent(x) });
const out = join(dirname(fileURLToPath(import.meta.url)), '../../spec/format-cases.json');
writeFileSync(out, JSON.stringify(cases, null, 1) + '\n');
console.log(cases.length, 'cases');

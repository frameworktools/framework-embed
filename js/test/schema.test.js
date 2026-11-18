import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import { describe, expect, it } from 'vitest';

const spec = join(dirname(fileURLToPath(import.meta.url)), '../../spec');
const schema = JSON.parse(readFileSync(join(spec, 'embed.schema.json'), 'utf8'));
const validate = new Ajv2020({ strict: false, allErrors: true }).compile(schema);
// Fixtures that break the schema on purpose, to show renderers survive a hostile or broken server.
const INVALID = ['hostile-image.json', 'hostile-snippet.json'];
const names = readdirSync(join(spec, 'fixtures')).filter((f) => f.endsWith('.json') && !INVALID.includes(f)).sort();

describe('embed.schema.json', () => {
    it.each(names)('accepts %s', (name) => {
        const doc = JSON.parse(readFileSync(join(spec, 'fixtures', name), 'utf8'));
        expect(validate(doc), JSON.stringify(validate.errors)).toBe(true);
    });

    it.each(INVALID)('rejects %s', (name) => {
        expect(validate(JSON.parse(readFileSync(join(spec, 'fixtures', name), 'utf8')))).toBe(false);
    });

    it('rejects other schemas and missing kinds', () => {
        expect(validate({ schema: 'framework.artifact/v1', kind: 'model', url: 'https://framework.pub/d/x' })).toBe(false);
        expect(validate({ schema: 'framework.embed/v1', url: 'https://framework.pub/d/x' })).toBe(false);
    });

    it('allows unknown fields', () => {
        expect(validate({ schema: 'framework.embed/v1', kind: 'page', url: 'https://framework.pub/d/x', later: { anything: true } })).toBe(true);
    });
});

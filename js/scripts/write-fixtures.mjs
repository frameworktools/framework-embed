// Regenerates spec/fixtures/*.html from the JS renderer. Review every diff by hand:
// the HTML files are the specification both renderers are tested against.
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { render } from '../src/render.js';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '../../spec/fixtures');
for (const file of readdirSync(fixtures).filter((f) => f.endsWith('.json')).sort()) {
    const html = render(JSON.parse(readFileSync(join(fixtures, file), 'utf8')));
    writeFileSync(join(fixtures, file.replace(/\.json$/, '.html')), html);
    console.log(file.replace(/\.json$/, '.html'));
}

// Builds dist/framework-embed.js (ESM), dist/framework-embed.iife.js, and dist/framework-embed.css.
import { build } from 'esbuild';
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'dist');
mkdirSync(dist, { recursive: true });

const common = { bundle: true, target: 'es2020', legalComments: 'none', logLevel: 'warning' };
await build({ ...common, entryPoints: [join(root, 'src/index.js')], format: 'esm', outfile: join(dist, 'framework-embed.js') });
await build({ ...common, entryPoints: [join(root, 'src/iife.js')], format: 'iife', minify: true, outfile: join(dist, 'framework-embed.iife.js') });
await build({ entryPoints: [join(root, 'css/framework-embed.css')], bundle: true, minify: false, outfile: join(dist, 'framework-embed.css'), logLevel: 'warning' });
console.log('built', dist);

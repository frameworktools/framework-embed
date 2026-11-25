import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { render } from '../src/render.js';

const fixtures = join(dirname(fileURLToPath(import.meta.url)), '../../spec/fixtures');
const load = (name) => JSON.parse(readFileSync(join(fixtures, name + '.json'), 'utf8'));
const dom = (doc) => { const host = document.createElement('div'); host.innerHTML = render(doc); return host; };
const tree = (children, extra = {}) => ({ kind: 'tree', title: 'T', data: { schema: 'framework.artifact/v1', kind: 'tree', tree: { root: 'p', children }, ...extra } });
const badges = (host) => [...host.querySelectorAll('.fw-embed__tree-badge')].map((badge) => badge.textContent);

describe('tree view', () => {
    it('draws folders as disclosures, open as the document says, and rows as fw_structure prints them', () => {
        const host = dom(load('r-tree'));
        expect(host.querySelector('figure').dataset.fwEmbedKind).toBe('tree');
        const folders = [...host.querySelectorAll('details.fw-embed__tree-dir')];
        expect(folders.map((folder) => [folder.querySelector('summary .fw-embed__tree-name').textContent, folder.open]))
            .toEqual([['├─  data/', true], ['├─  outputs/', true], ['├─  R/', true]]);
        const names = [...host.querySelectorAll('.fw-embed__tree-name')].map((name) => name.textContent);
        expect(names).toEqual(['my-project/', '├─  data/', '│   ├── private/', '│   └── raw/', '├─  outputs/', '│   ├── table.csv',
            '│   └── *.rds', '├─  R/', '│   └── clean.R', '├── renv/', '└── README.md']);
        expect(host.querySelector('.fw-embed__tree').getAttribute('style')).toBe('--fw-embed-tree-names: 17ch');
    });

    it('dims stubs and groups with a badge, and names the rule source in its title', () => {
        const host = dom(load('r-tree'));
        expect(badges(host)).toEqual(['private · 3 files', 'ignored · data/raw/ · 12 files', 'ignored · *.rds · 4 files', '1,520 files']);
        expect([...host.querySelectorAll('.fw-embed__tree-badge')].map((badge) => badge.getAttribute('title')))
            .toEqual([null, '.gitignore line 4', '.gitignore line 7', null]);
        expect(host.querySelectorAll('.fw-embed__tree-row--stub')).toHaveLength(4);
        expect([...host.querySelectorAll('.fw-embed__tree-note')].map((note) => note.textContent)).toEqual(['Reusable functions', 'Start here']);
        expect(host.querySelector('.fw-embed__tree-row--highlight .fw-embed__tree-name').textContent).toBe('└── README.md');
    });

    it('reads 10,000 as 10,000+ files and one as 1 file, and names rules from outside the project', () => {
        const host = dom(load('tree-subfolder'));
        expect(badges(host)).toEqual(['ignored · fits/ · 10,000+ files', 'private · ignored · scratch/ · 1 file', 'ignored · *.log · 11 files']);
        expect([...host.querySelectorAll('.fw-embed__tree-badge[title]')].map((badge) => badge.title)).toEqual(['global excludes file', '.git/info/exclude line 2']);
        expect(host.querySelector('.fw-embed__tree-row--root').textContent).toBe('analysis/models/');
        expect(host.querySelector('.fw-embed__tree-crumb').textContent).toBe('analysis/');
    });

    it('leaves out notes and ignored paths the owner turned off, and keeps private folders', () => {
        const host = dom(load('tree-display-hidden'));
        expect(host.querySelectorAll('.fw-embed__tree-note')).toHaveLength(0);
        expect(badges(host)).toEqual(['private · 3 files', '1,520 files']);
        const doc = tree([{ name: 'secret_private', type: 'dir', private: true, ignored: { rule: 'secret_private/' }, hidden: 2, children: [] }], { display: { ignored: false } });
        expect(badges(dom(doc))).toEqual(['private · 2 files']);
    });

    it('draws a bare root when the tree is empty and renders a document without a tree as page', () => {
        expect(dom(tree([])).querySelector('.fw-embed__tree').textContent.trim()).toBe('p/');
        for (const data of [null, {}, { tree: [] }, { tree: { children: [] } }, { tree: { root: '', children: [] } }]) {
            expect(render({ kind: 'tree', title: 'T', data })).toContain('fw-embed--page');
        }
    });

    it('stops at 32 levels and 5,000 rows', () => {
        let node = { name: 'f', type: 'file' };
        for (let i = 0; i < 40; i++) node = { name: `d${i}`, type: 'dir', open: true, children: [node] };
        expect(dom(tree([node])).querySelectorAll('details')).toHaveLength(31);
        const many = Array.from({ length: 6000 }, (_, i) => ({ name: `f${i}`, type: 'file' }));
        expect(dom(tree(many)).querySelectorAll('.fw-embed__tree-row--file')).toHaveLength(5000);
    });

    it('shows the link card for a kind this renderer does not know', () => {
        const doc = { ...load('r-tree'), kind: 'tree-v2' };
        expect(render(doc)).toContain('data-fw-embed-kind="page"');
    });
});

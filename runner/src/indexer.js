// @ts-check
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { git } from './exec.js';
import { isSecretFile, redactText } from './redact.js';
import { SKIP_DIRS } from './scanner/files.js';

/**
 * Code index (Plan §41): files plus the symbols agents need to find relevant
 * code without reading the whole repository (Plan §2.4, §40). Symbols are
 * extracted with deterministic patterns — no AI involved.
 *
 * @typedef {{ kind: string, name: string, line: number, detail?: string }} IndexSymbol
 * @typedef {{ path: string, language: string, size: number, sha1: string, symbols: IndexSymbol[] }} IndexFile
 */

export const LIMITS = { maxFiles: 20_000, maxFileBytes: 512 * 1024, maxSymbolsPerFile: 400 };

/** @type {Record<string, string>} */
const LANGUAGES = {
    '.php': 'php',
    '.ts': 'typescript',
    '.tsx': 'typescript',
    '.mts': 'typescript',
    '.cts': 'typescript',
    '.js': 'javascript',
    '.jsx': 'javascript',
    '.mjs': 'javascript',
    '.cjs': 'javascript',
    '.vue': 'vue',
    '.svelte': 'svelte',
    '.css': 'css',
    '.scss': 'scss',
    '.json': 'json',
    '.md': 'markdown',
    '.sql': 'sql',
    '.yml': 'yaml',
    '.yaml': 'yaml',
    '.xml': 'xml',
    '.html': 'html',
    '.py': 'python',
    '.sh': 'shell',
    '.ps1': 'powershell',
};

/**
 * @param {string} rel
 */
export function languageOf(rel) {
    if (rel.endsWith('.blade.php')) return 'blade';

    return LANGUAGES[path.extname(rel).toLowerCase()] ?? null;
}

/**
 * Files to index: Git's view (tracked + untracked, respecting .gitignore) when
 * available, otherwise a walk that skips dependency and build folders.
 *
 * @param {string} folder
 * @param {{ workspacesRoot: string, gitEnabled: boolean }} options
 * @returns {Promise<string[]>}
 */
export async function listFiles(folder, { workspacesRoot, gitEnabled }) {
    /** @type {string[]} */
    let files;

    if (gitEnabled) {
        const out = await git(['ls-files', '-co', '--exclude-standard', '-z'], { cwd: folder, workspacesRoot, timeoutMs: 120_000 });
        files = out.stdout.split('\0').filter(Boolean);
    } else {
        files = [];
        /** @param {string} rel @param {number} depth */
        const walk = (rel, depth) => {
            if (depth > 12 || files.length > LIMITS.maxFiles) return;

            for (const entry of safeReaddir(path.join(folder, rel))) {
                const child = rel ? `${rel}/${entry.name}` : entry.name;

                if (entry.isDirectory()) {
                    if (!SKIP_DIRS.has(entry.name) && !SKIP_DIRS.has(child)) walk(child, depth + 1);
                } else if (entry.isFile()) {
                    files.push(child);
                }
            }
        };
        walk('', 0);
    }

    return files.filter((f) => !isSecretFile(f) && !f.split('/').some((seg) => SKIP_DIRS.has(seg)) && languageOf(f) !== null).slice(0, LIMITS.maxFiles);
}

/**
 * @param {string} dir
 * @returns {fs.Dirent[]}
 */
function safeReaddir(dir) {
    try {
        return fs.readdirSync(dir, { withFileTypes: true });
    } catch {
        return [];
    }
}

/**
 * Index one file. Returns null for files that cannot or must not be read.
 *
 * @param {string} folder
 * @param {string} rel
 * @returns {IndexFile | null}
 */
export function indexFile(folder, rel) {
    const language = languageOf(rel);

    if (!language || isSecretFile(rel)) return null;

    let buffer;
    try {
        const abs = path.join(folder, rel);
        const stat = fs.statSync(abs);
        if (!stat.isFile() || stat.size > LIMITS.maxFileBytes) {
            return stat.isFile() ? { path: rel, language, size: stat.size, sha1: '', symbols: [] } : null;
        }
        buffer = fs.readFileSync(abs);
    } catch {
        return null;
    }

    if (buffer.includes(0)) return null; // binary

    const text = buffer.toString('utf8');
    const symbols = extractSymbols(rel, language, text).slice(0, LIMITS.maxSymbolsPerFile);

    return {
        path: rel,
        language,
        size: buffer.length,
        sha1: crypto.createHash('sha1').update(buffer).digest('hex'),
        // Symbol details are code fragments: redact anything credential-shaped before they leave the PC.
        symbols: symbols.map((s) => (s.detail ? { ...s, detail: redactText(s.detail).slice(0, 500) } : s)),
    };
}

/**
 * @param {string} rel
 * @param {string} language
 * @param {string} text
 * @returns {IndexSymbol[]}
 */
export function extractSymbols(rel, language, text) {
    /** @type {IndexSymbol[]} */
    const out = [];
    const lineAt = lineIndex(text);
    const unix = rel.replace(/\\/g, '/');

    /**
     * @param {RegExp} re
     * @param {(m: RegExpMatchArray) => IndexSymbol | null} make
     */
    const each = (re, make) => {
        for (const m of text.matchAll(re)) {
            const s = make(m);
            if (s) out.push(s);
        }
    };

    if (language === 'php') {
        const isTest = /(^|\/)tests?\//i.test(unix);
        const isModel = /(^|\/)app\/(Models\/)?[A-Z]\w*\.php$/.test(unix);

        each(/^[ \t]*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_]\w*)([^{\n]*)/gm, (m) => {
            const extendsModel = /extends\s+(\\?[\w\\]*\\)?(Model|Authenticatable|Pivot)\b/.test(m[3]);
            const kind = m[1] === 'class' ? (extendsModel || (isModel && /extends/.test(m[3]) && /Model/.test(m[3])) ? 'model' : isTest ? 'test' : 'class') : m[1];

            return { kind, name: m[2], line: lineAt(m.index ?? 0), detail: m[3].trim() || undefined };
        });

        each(/^[ \t]*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+&?([A-Za-z_]\w*)\s*\(/gm, (m) => ({
            kind: isTest && /^test/i.test(m[1]) ? 'test' : 'function',
            name: m[1],
            line: lineAt(m.index ?? 0),
        }));

        if (isTest) {
            each(/^\s*(?:it|test)\(\s*(['"])(.+?)\1/gm, (m) => ({ kind: 'test', name: m[2].slice(0, 200), line: lineAt(m.index ?? 0) }));
        }

        if (/^routes\//.test(unix)) {
            each(/Route::(get|post|put|patch|delete|options|any|match|resource|apiResource|view|redirect)\(\s*(?:\[[^\]]*\]\s*,\s*)?(['"])([^'"]*)\2\s*,?\s*([^\n;]{0,200})/g, (m) => ({
                kind: 'route',
                name: `${m[1].toUpperCase()} /${m[3].replace(/^\//, '')}`,
                line: lineAt(m.index ?? 0),
                detail: m[4].replace(/\)\s*$/, '').trim() || undefined,
            }));
        }

        if (/^database\/migrations\//.test(unix)) {
            each(/Schema::(create|table)\(\s*(['"])(\w+)\2/g, (m) => ({ kind: 'table', name: m[3], line: lineAt(m.index ?? 0), detail: m[1] === 'create' ? 'create' : 'alter' }));
        }
    }

    if (language === 'blade') {
        const view = unix.replace(/^resources\/views\//, '').replace(/\.blade\.php$/, '').replace(/\//g, '.');
        out.push({ kind: unix.includes('/components/') ? 'component' : 'view', name: view, line: 1 });
    }

    if (language === 'typescript' || language === 'javascript') {
        const jsx = /\.(t|j)sx$/.test(unix);

        each(/^export\s+(default\s+)?(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)/gm, (m) => ({
            kind: classifyJs(m[2], jsx),
            name: m[2],
            line: lineAt(m.index ?? 0),
        }));
        each(/^export\s+(?:const|let)\s+([A-Za-z_$][\w$]*)\s*(?::[^=]+)?=\s*(?:async\s*)?(\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>/gm, (m) => ({
            kind: classifyJs(m[1], jsx),
            name: m[1],
            line: lineAt(m.index ?? 0),
        }));
        each(/^export\s+(?:default\s+)?(?:abstract\s+)?class\s+([A-Za-z_$][\w$]*)/gm, (m) => ({ kind: 'class', name: m[1], line: lineAt(m.index ?? 0) }));
        each(/^export\s+(?:declare\s+)?(interface|type|enum)\s+([A-Za-z_$][\w$]*)/gm, (m) => ({ kind: 'type', name: m[2], line: lineAt(m.index ?? 0), detail: m[1] }));

        if (/\.(test|spec)\.(t|j)sx?$/.test(unix)) {
            each(/^\s*(?:it|test)\(\s*(['"`])(.+?)\1/gm, (m) => ({ kind: 'test', name: m[2].slice(0, 200), line: lineAt(m.index ?? 0) }));
        }

        // Next.js routes are files: app/**/page.tsx and route.ts, pages/**.
        const appRoute = /^(?:src\/)?app\/(.*?)\/?(page|route)\.(t|j)sx?$/.exec(unix);
        if (appRoute) {
            const urlPath = '/' + appRoute[1].split('/').filter((seg) => seg && !/^\(.*\)$/.test(seg) && !seg.startsWith('@')).join('/');

            if (appRoute[2] === 'page') {
                out.push({ kind: 'route', name: `PAGE ${urlPath}`, line: 1 });
            } else {
                each(/^export\s+(?:async\s+)?(?:function|const)\s+(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\b/gm, (m) => ({ kind: 'route', name: `${m[1]} ${urlPath}`, line: lineAt(m.index ?? 0) }));
            }
        }

        const pagesRoute = /^(?:src\/)?pages\/(.*)\.(t|j)sx?$/.exec(unix);
        if (pagesRoute && !/^_(app|document|error)$/.test(pagesRoute[1])) {
            const urlPath = '/' + pagesRoute[1].replace(/(^|\/)index$/, '').replace(/^\/$/, '');
            out.push({ kind: 'route', name: `${pagesRoute[1].startsWith('api/') ? 'API' : 'PAGE'} ${urlPath === '/' ? '/' : urlPath}`, line: 1 });
        }
    }

    if (language === 'vue' || language === 'svelte') {
        out.push({ kind: 'component', name: path.basename(unix).replace(/\.(vue|svelte)$/, ''), line: 1 });
    }

    if (unix === 'composer.json' || unix === 'package.json') {
        try {
            const json = JSON.parse(text);
            const deps = unix === 'composer.json' ? { ...json.require, ...json['require-dev'] } : { ...json.dependencies, ...json.devDependencies };

            for (const [name, version] of Object.entries(deps ?? {})) {
                out.push({ kind: 'dependency', name, line: 1, detail: String(version) });
            }
        } catch {
            // Malformed manifest: nothing to index.
        }
    }

    return out;
}

/**
 * @param {string} name
 * @param {boolean} jsx
 */
function classifyJs(name, jsx) {
    if (/^use[A-Z]/.test(name)) return 'hook';
    if (jsx && /^[A-Z]/.test(name)) return 'component';

    return 'function';
}

/**
 * @param {string} text
 * @returns {(offset: number) => number}
 */
function lineIndex(text) {
    /** @type {number[]} */
    const starts = [0];
    for (let i = 0; i < text.length; i++) if (text.charCodeAt(i) === 10) starts.push(i + 1);

    return (offset) => {
        let lo = 0;
        let hi = starts.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (starts[mid] <= offset) lo = mid;
            else hi = mid - 1;
        }

        return lo + 1;
    };
}

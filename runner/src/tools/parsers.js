// @ts-check
import fs from 'node:fs';

/**
 * Tool output parsers (Plan §48.8, §89.1): "only parsed tool output counts".
 * Each parser turns what a real tool printed (or wrote as a report) into the
 * same shape. Parsers never decide pass/fail — the exit code does; they
 * explain it.
 *
 * @typedef {{ file: string | null, line: number | null, column: number | null, severity: 'error' | 'warning', message: string, rule: string | null }} Issue
 * @typedef {{ name: string, file: string | null, message: string }} Failure
 * @typedef {{ total: number, passed: number, failed: number, skipped: number }} TestCounts
 * @typedef {{ tests: TestCounts | null, failures: Failure[], issues: Issue[], files: string[] }} Parsed
 */

const MAX_ITEMS = 200;

/** @returns {Parsed} */
function empty() {
    return { tests: null, failures: [], issues: [], files: [] };
}

/**
 * @param {string} parser
 * @param {{ output: string, report: string | null, root: string }} input
 * @returns {Parsed}
 */
export function parseToolOutput(parser, { output, report, root }) {
    const parsed = (() => {
        switch (parser) {
            case 'junit':
                return report ? parseJUnit(report) : parseJsTests(output);
            case 'phpstan':
                return parsePhpstan(output);
            case 'psalm':
                return parsePsalm(output);
            case 'eslint':
                return parseEslint(output);
            case 'tsc':
                return parseTsc(output);
            case 'pint':
                return parsePint(output);
            case 'php-cs-fixer':
                return parsePhpCsFixer(output);
            case 'prettier':
                return parsePrettier(output);
            case 'js-tests':
                return parseJsTests(output);
            case 'build':
                return parseBuild(output);
            default:
                return empty();
        }
    })();

    return {
        tests: parsed.tests,
        failures: parsed.failures.slice(0, 50).map((f) => ({ ...f, file: relativize(f.file, root), message: f.message.slice(0, 2000) })),
        issues: parsed.issues.slice(0, MAX_ITEMS).map((i) => ({ ...i, file: relativize(i.file, root), message: i.message.slice(0, 1000) })),
        files: [...new Set(parsed.files.map((f) => relativize(f, root) ?? f))].slice(0, MAX_ITEMS),
    };
}

// JUnit XML (PHPUnit, Pest, Playwright, pytest) -------------------------------

/**
 * @param {string} xml
 * @returns {Parsed}
 */
export function parseJUnit(xml) {
    const out = empty();
    const counts = { total: 0, passed: 0, failed: 0, skipped: 0 };
    const testcase = /<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g;

    for (const m of xml.matchAll(testcase)) {
        const attrs = attributes(m[1]);
        const body = m[2] ?? '';
        counts.total++;

        const failure = /<(failure|error)\b([^>]*?)(?:\/>|>([\s\S]*?)<\/\1>)/.exec(body);

        if (failure) {
            counts.failed++;
            const fa = attributes(failure[2]);
            const detail = decode(failure[3] ?? '').replace(/\r\n?/g, '\n').trim();
            const name = [attrs.classname, attrs.name].filter(Boolean).join(' › ') || 'unnamed test';
            // PHPUnit 10+ has no message attribute and starts the body with "Class::method": skip that line.
            const body = detail.split('\n').filter((line, i) => !(i === 0 && /::\w+/.test(line) && !/\s/.test(line.trim()))).join('\n').trim();
            out.failures.push({
                name,
                file: attrs.file ?? null,
                message: fa.message ? decode(fa.message) + (body ? `\n${body}` : '') : body,
            });
        } else if (/<skipped\b/.test(body)) {
            counts.skipped++;
        } else {
            counts.passed++;
        }
    }

    out.tests = counts;

    return out;
}

/** @param {string} raw */
function attributes(raw) {
    /** @type {Record<string, string>} */
    const out = {};

    for (const m of raw.matchAll(/([\w:.-]+)\s*=\s*"([^"]*)"/g)) out[m[1]] = decode(m[2]);

    return out;
}

/** @param {string} s */
function decode(s) {
    return s
        .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&apos;/g, "'")
        .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
        .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
        .replace(/&amp;/g, '&');
}

// PHPStan / Psalm (JSON) ------------------------------------------------------

/**
 * @param {string} output
 * @returns {Parsed}
 */
export function parsePhpstan(output) {
    const out = empty();
    const json = firstJson(output);

    if (!json || typeof json !== 'object') return out;

    for (const [file, entry] of Object.entries(json.files ?? {})) {
        for (const msg of /** @type {any} */ (entry).messages ?? []) {
            out.issues.push({ file, line: num(msg.line), column: null, severity: 'error', message: String(msg.message ?? ''), rule: msg.identifier ?? null });
        }
    }

    for (const msg of json.errors ?? []) {
        out.issues.push({ file: null, line: null, column: null, severity: 'error', message: String(msg), rule: null });
    }

    return out;
}

/**
 * @param {string} output
 * @returns {Parsed}
 */
export function parsePsalm(output) {
    const out = empty();
    const json = firstJson(output, '[');

    for (const item of Array.isArray(json) ? json : []) {
        out.issues.push({
            file: item.file_path ?? item.file_name ?? null,
            line: num(item.line_from),
            column: num(item.column_from),
            severity: item.severity === 'error' ? 'error' : 'warning',
            message: String(item.message ?? ''),
            rule: item.type ?? null,
        });
    }

    return out;
}

// ESLint (stylish), TypeScript ------------------------------------------------

/**
 * @param {string} output
 * @returns {Parsed}
 */
export function parseEslint(output) {
    const out = empty();
    /** @type {string | null} */
    let file = null;

    for (const line of output.split('\n')) {
        const issue = /^\s+(\d+):(\d+)\s+(error|warning)\s+(.+?)(?:\s{2,}(\S+))?\s*$/.exec(line);

        if (issue && file) {
            out.issues.push({ file, line: num(issue[1]), column: num(issue[2]), severity: /** @type {'error' | 'warning'} */ (issue[3]), message: issue[4].trim(), rule: issue[5] ?? null });
        } else if (/^(\/|[A-Za-z]:[\\/]|\.{0,2}[\w@-][^\s:]*\.[\w]+$)/.test(line.trim()) && !/^\s/.test(line) && !/problems?\s*\(/.test(line)) {
            file = line.trim();
        } else if (line.trim() === '') {
            file = null;
        }
    }

    // Also understand tsc-style errors when "lint" runs a type check.
    if (out.issues.length === 0) out.issues.push(...parseTsc(output).issues);

    return out;
}

/**
 * @param {string} output
 * @returns {Parsed}
 */
export function parseTsc(output) {
    const out = empty();
    const plain = /^(.+?)\((\d+),(\d+)\):\s+(error|warning)\s+(TS\d+):\s+(.+)$/;
    const pretty = /^(.+?):(\d+):(\d+)\s+-\s+(error|warning)\s+(TS\d+):\s+(.+)$/;

    for (const line of output.split('\n')) {
        const m = plain.exec(line.trim()) ?? pretty.exec(line.trim());

        if (m) out.issues.push({ file: m[1], line: num(m[2]), column: num(m[3]), severity: /** @type {'error' | 'warning'} */ (m[4]), message: m[6], rule: m[5] });
    }

    return out;
}

// Formatters ----------------------------------------------------------------

/**
 * Laravel Pint: "  ⨯ app/Models/User.php   class_attributes_separation, …"
 * @param {string} output
 * @returns {Parsed}
 */
export function parsePint(output) {
    const out = empty();

    for (const line of output.split('\n')) {
        const m = /^\s*[⨯✗×xX✓✔]\s+(\S+\.php)\s*(.*)$/.exec(line);

        if (m) {
            out.files.push(m[1]);
            out.issues.push({ file: m[1], line: null, column: null, severity: 'error', message: m[2].trim() ? `Style: ${m[2].trim()}` : 'Not formatted', rule: null });
        }
    }

    return out;
}

/**
 * PHP-CS-Fixer --dry-run: "   1) app/Foo.php (no_unused_imports)"
 * @param {string} output
 * @returns {Parsed}
 */
export function parsePhpCsFixer(output) {
    const out = empty();

    for (const line of output.split('\n')) {
        const m = /^\s*\d+\)\s+(\S+)(?:\s+\((.+)\))?/.exec(line);

        if (m) {
            out.files.push(m[1]);
            out.issues.push({ file: m[1], line: null, column: null, severity: 'error', message: m[2] ? `Style: ${m[2]}` : 'Not formatted', rule: null });
        }
    }

    return out;
}

/**
 * Prettier --check: "[warn] src/App.tsx"
 * @param {string} output
 * @returns {Parsed}
 */
export function parsePrettier(output) {
    const out = empty();

    for (const line of output.split('\n')) {
        const m = /^\[warn\]\s+(\S.*)$/.exec(line.trim());

        if (m && !/code style issues/i.test(m[1])) {
            out.files.push(m[1].trim());
            out.issues.push({ file: m[1].trim(), line: null, column: null, severity: 'error', message: 'Not formatted', rule: null });
        }
    }

    return out;
}

// JS test runners (text) --------------------------------------------------------

/**
 * Vitest: "Tests  2 failed | 10 passed | 1 skipped (13)"; " FAIL  src/a.test.ts > suite > name"
 * Jest:   "Tests:       1 failed, 1 skipped, 11 passed, 13 total"; "  ● suite › name"
 * @param {string} output
 * @returns {Parsed}
 */
export function parseJsTests(output) {
    const out = empty();
    const lines = output.split('\n');

    const vitest = lines.map((l) => /^\s*Tests\s+(.+)\((\d+)\)\s*$/.exec(l)).filter(Boolean).pop();
    const jest = lines.map((l) => /^\s*Tests:\s+(.+?),?\s*(\d+)\s+total\s*$/.exec(l)).filter(Boolean).pop();
    const summary = vitest ?? jest;

    if (summary) {
        const part = (/** @type {string} */ word) => Number(new RegExp(`(\\d+)\\s+${word}`).exec(summary[1])?.[1] ?? 0);
        const total = Number(summary[2]);
        out.tests = { total, passed: part('passed'), failed: part('failed'), skipped: part('skipped') + part('todo') + part('pending') };
    }

    const seen = new Set();
    lines.forEach((line, i) => {
        const v = /^\s*(?:FAIL|×|✗)\s+(\S+\.[cm]?[jt]sx?)\s+>\s+(.+?)(?:\s+\d+m?s)?\s*$/.exec(line);
        const j = /^\s*●\s+(.+?)\s*$/.exec(line);
        const name = v ? `${v[2]}` : j && !/Console|Test suite failed to run/.test(j[1]) ? j[1] : null;

        if (name && !seen.has(name)) {
            seen.add(name);
            const message = lines.slice(i + 1, i + 8).map((l) => l.trim()).find((l) => l && !/^[─-]+$/.test(l)) ?? '';
            out.failures.push({ name, file: v ? v[1] : null, message });
        }
    });

    return out;
}

// Builds ----------------------------------------------------------------------

/**
 * Builds report errors in many formats; keep TypeScript errors exactly and
 * otherwise the lines that say "error".
 * @param {string} output
 * @returns {Parsed}
 */
export function parseBuild(output) {
    const out = parseTsc(output);

    if (out.issues.length === 0) {
        for (const line of output.split('\n')) {
            const text = line.trim();

            if (/\berror\b/i.test(text) && !/\b0 errors?\b/i.test(text) && text.length < 1000) {
                const loc = /([\w./\\-]+\.[a-z]{1,5}):(\d+)(?::(\d+))?/i.exec(text);
                out.issues.push({ file: loc?.[1] ?? null, line: num(loc?.[2]), column: num(loc?.[3]), severity: 'error', message: text, rule: null });
            }

            if (out.issues.length >= 20) break;
        }
    }

    return out;
}

// helpers ---------------------------------------------------------------------

/**
 * The first JSON value in mixed output (tools may print warnings first).
 * @param {string} text
 * @param {'{' | '['} open
 */
function firstJson(text, open = '{') {
    for (let start = text.indexOf(open); start !== -1; start = text.indexOf(open, start + 1)) {
        const end = text.lastIndexOf(open === '{' ? '}' : ']');

        if (end <= start) return null;

        try {
            return JSON.parse(text.slice(start, end + 1));
        } catch {
            // Keep looking.
        }
    }

    return null;
}

/** @param {unknown} v */
function num(v) {
    const n = Number(v);

    return Number.isFinite(n) && v !== null && v !== undefined && v !== '' ? n : null;
}

/**
 * Report paths relative to the workspace, with forward slashes; paths outside
 * it are reduced to their file name so no local folder structure leaves the PC.
 * @param {string | null} file
 * @param {string} root
 */
function relativize(file, root) {
    if (!file) return null;

    const f = file.replace(/\\/g, '/');

    // The workspace may be known by an alias (C:\Users\ADMINI~1, a junction) while tools report the real path.
    for (const r of rootsOf(root)) {
        if (f.toLowerCase().startsWith(`${r.toLowerCase()}/`)) return f.slice(r.length + 1);
    }
    if (/^([A-Za-z]:\/|\/)/.test(f)) return f.split('/').pop() ?? f;

    return f.replace(/^\.\//, '');
}

/** @type {Map<string, string[]>} */
const rootCache = new Map();

/** @param {string} root */
function rootsOf(root) {
    let list = rootCache.get(root);

    if (!list) {
        const variants = [root];
        try {
            variants.push(fs.realpathSync.native(root));
        } catch {
            // Not on this machine (e.g. parsing a sample): the given path is all there is.
        }
        list = [...new Set(variants.map((v) => v.replace(/\\/g, '/').replace(/\/+$/, '')))];
        rootCache.set(root, list);
    }

    return list;
}

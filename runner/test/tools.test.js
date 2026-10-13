import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, describe, test } from 'node:test';
import { isDangerous } from '../src/policy.js';
import { describeTools, detectTools } from '../src/tools/catalog.js';
import { parseEslint, parseJsTests, parseJUnit, parsePhpstan, parsePint, parsePrettier, parseToolOutput, parseTsc } from '../src/tools/parsers.js';
import { runTool } from '../src/tools/run.js';
import { phpTestEnvironment } from '../src/tools/test-env.js';
import { createWorkspace } from '../src/workspace.js';
import { cleanup, makeRepo, tempDir, writeFiles } from './helpers.js';

const root = tempDir();
after(() => cleanup(root));

const laravel = {
    'composer.json': {
        require: { 'laravel/framework': '^11.0' },
        'require-dev': { 'laravel/pint': '^1.13', 'pestphp/pest': '^2.0', 'larastan/larastan': '^2.9' },
    },
    'phpstan.neon': 'parameters:\n  level: 5\n',
    'phpunit.xml': '<phpunit><php><env name="APP_ENV" value="testing"/></php></phpunit>',
    'package.json': {
        scripts: { dev: 'vite', build: 'vite build', lint: 'eslint resources/js', test: 'vitest' },
        devDependencies: { vite: '^5', prettier: '^3', typescript: '^5', vitest: '^1', '@playwright/test': '^1.45' },
    },
    'package-lock.json': '{}',
    'tsconfig.json': '{}',
    'playwright.config.ts': 'export default {}',
};

describe('tool catalog (Plan §34, §84)', () => {
    const project = path.join(root, 'catalog-laravel');
    writeFiles(project, laravel);

    test('detects only the tools the project really has, in gate order', () => {
        const tools = describeTools(detectTools(project));
        const keys = tools.map((t) => t.key);

        assert.deepEqual(keys, ['php.install', 'js.install', 'php.format', 'js.format', 'php.static', 'js.lint', 'js.typecheck', 'php.test', 'js.test', 'js.build', 'e2e.playwright']);

        const byKey = Object.fromEntries(tools.map((t) => [t.key, t]));
        assert.equal(byKey['php.test'].command, 'php vendor/bin/pest --colors=never --log-junit <report>');
        assert.equal(byKey['php.format'].command, 'php vendor/bin/pint --test');
        assert.equal(byKey['php.format'].supports_fix, true);
        assert.equal(byKey['js.install'].command, 'npm ci --no-audit --no-fund');
        assert.equal(byKey['js.build'].command, 'npm run build');
        assert.equal(byKey['js.test'].label, 'JS tests (Vitest)');
        assert.equal(byKey['php.static'].label, 'PHP static analysis (Larastan)');
        assert.ok(tools.every((t) => !/[A-Za-z]:\\|^\//.test(t.command)), 'no local paths in what the Studio stores');
    });

    test('never assumes tools: no config, no static analysis; npm placeholder test script is not a test suite', () => {
        const bare = path.join(root, 'catalog-bare');
        writeFiles(bare, {
            'composer.json': { 'require-dev': { 'phpstan/phpstan': '^1' } },
            'package.json': { scripts: { test: 'echo "Error: no test specified" && exit 1' } },
        });

        assert.deepEqual(detectTools(bare).map((t) => t.key), ['php.install', 'js.install']);
    });

    test('scripts that run dangerous commands are listed as blocked (Plan §85)', () => {
        const risky = path.join(root, 'catalog-risky');
        writeFiles(risky, { 'package.json': { scripts: { build: 'rm -rf / && vite build', prebuild: 'echo ok' } } });

        const build = describeTools(detectTools(risky)).find((t) => t.key === 'js.build');
        assert.match(String(build?.blocked), /needs explicit approval/);
    });

    test('format flags in ordinary commands are not mistaken for dangerous ones', () => {
        assert.equal(isDangerous('php vendor/bin/phpstan analyse --error-format=json'), false);
        assert.equal(isDangerous('npm run format'), false);
        assert.equal(isDangerous('format C: /q'), true);
        assert.equal(isDangerous('vite build && shutdown -h now'), true);
    });
});

describe('result parsers (Plan §48.8)', () => {
    test('JUnit (PHPUnit/Pest/Playwright/pytest)', () => {
        const xml = `<?xml version="1.0"?><testsuites><testsuite name="Feature">
            <testcase name="test_creates_booking" classname="Tests\\Feature\\BookingTest" file="/ws/tests/Feature/BookingTest.php" time="0.1"/>
            <testcase name="test_rejects_overlap" classname="Tests\\Feature\\BookingTest" file="/ws/tests/Feature/BookingTest.php">
              <failure type="AssertionFailedError" message="Failed asserting that 200 is 422.">Tests\\Feature\\BookingTest::test_rejects_overlap
Failed asserting that 200 is 422.
/ws/tests/Feature/BookingTest.php:41</failure></testcase>
            <testcase name="test_later"><skipped/></testcase>
        </testsuite></testsuites>`;

        const parsed = parseJUnit(xml);
        assert.deepEqual(parsed.tests, { total: 3, passed: 1, failed: 1, skipped: 1 });
        assert.equal(parsed.failures[0].name, 'Tests\\Feature\\BookingTest › test_rejects_overlap');
        assert.match(parsed.failures[0].message, /^Failed asserting that 200 is 422\./);

        const relative = parseToolOutput('junit', { output: '', report: xml, root: '/ws' });
        assert.equal(relative.failures[0].file, 'tests/Feature/BookingTest.php');
    });

    test('PHPUnit 10+ failures (no message attribute) and workspaces known by an alias path', () => {
        const alias = fs.mkdtempSync(path.join(os.tmpdir(), 'eaj-alias-'));
        const real = fs.realpathSync.native(alias);
        const xml = `<testsuite><testcase name="test_adds_up" classname="Tests\\Unit\\MathTest" file="${real}${path.sep}tests${path.sep}Unit${path.sep}MathTest.php">
            <failure type="PHPUnit\\Framework\\ExpectationFailedException">Tests\\Unit\\MathTest::test_adds_up
Failed asserting that 3 is identical to 2.

${real}${path.sep}tests${path.sep}Unit${path.sep}MathTest.php:12</failure></testcase></testsuite>`;

        const parsed = parseToolOutput('junit', { output: '', report: xml, root: alias });
        cleanup(alias);

        assert.equal(parsed.failures[0].file, 'tests/Unit/MathTest.php');
        assert.match(parsed.failures[0].message, /^Failed asserting that 3 is identical to 2\./);
    });

    test('PHPStan JSON, even with noise before it', () => {
        const out = `Note: Using configuration file phpstan.neon.\n{"totals":{"errors":0,"file_errors":1},"files":{"/ws/app/Booking.php":{"errors":1,"messages":[{"message":"Undefined variable: $total","line":12,"ignorable":true,"identifier":"variable.undefined"}]}},"errors":[]}`;
        const parsed = parsePhpstan(out);
        assert.deepEqual(parsed.issues[0], { file: '/ws/app/Booking.php', line: 12, column: null, severity: 'error', message: 'Undefined variable: $total', rule: 'variable.undefined' });
    });

    test('ESLint stylish and tsc', () => {
        const eslint = `\nC:\\ws\\resources\\js\\app.tsx\n  3:10  error    'x' is defined but never used  @typescript-eslint/no-unused-vars\n  8:1   warning  Unexpected console statement    no-console\n\n✖ 2 problems (1 error, 1 warning)\n`;
        const issues = parseEslint(eslint).issues;
        assert.equal(issues.length, 2);
        assert.deepEqual(issues[0], { file: 'C:\\ws\\resources\\js\\app.tsx', line: 3, column: 10, severity: 'error', message: "'x' is defined but never used", rule: '@typescript-eslint/no-unused-vars' });

        const tsc = parseTsc("src/app.ts(4,7): error TS2322: Type 'string' is not assignable to type 'number'.\nsrc/b.ts:9:2 - error TS2304: Cannot find name 'foo'.");
        assert.deepEqual(tsc.issues.map((i) => [i.file, i.line, i.rule]), [['src/app.ts', 4, 'TS2322'], ['src/b.ts', 9, 'TS2304']]);
    });

    test('formatters: Pint and Prettier list the files that need formatting', () => {
        const pint = parsePint('\n  ⨯ app/Models/Booking.php   class_attributes_separation, no_unused_imports\n\n  FAIL   ....... 12 files, 1 style issue\n');
        assert.deepEqual(pint.files, ['app/Models/Booking.php']);

        const prettier = parsePrettier('Checking formatting...\n[warn] resources/js/app.tsx\n[warn] Code style issues found in the above file. Run Prettier with --write to fix.');
        assert.deepEqual(prettier.files, ['resources/js/app.tsx']);
    });

    test('Vitest and Jest summaries and failure names', () => {
        const vitest = parseJsTests(' FAIL  src/cart.test.ts > cart > applies discount\nAssertionError: expected 90 to be 80\n\n Test Files  1 failed | 2 passed (3)\n      Tests  1 failed | 10 passed | 1 skipped (12)\n');
        assert.deepEqual(vitest.tests, { total: 12, passed: 10, failed: 1, skipped: 1 });
        assert.deepEqual(vitest.failures.map((f) => [f.name, f.file]), [['cart > applies discount', 'src/cart.test.ts']]);

        const jest = parseJsTests('  ● Cart › applies discount\n\n    expect(received).toBe(expected)\n\nTests:       1 failed, 2 skipped, 11 passed, 14 total\n');
        assert.deepEqual(jest.tests, { total: 14, passed: 11, failed: 1, skipped: 2 });
        assert.equal(jest.failures[0].message, 'expect(received).toBe(expected)');
    });
});

describe('test database safety (Plan §15)', () => {
    test('no database chosen → in-memory SQLite and a throwaway APP_KEY', () => {
        const ws = path.join(root, 'db-none');
        writeFiles(ws, { 'phpunit.xml': '<phpunit><php><env name="APP_ENV" value="testing"/></php></phpunit>' });

        const result = phpTestEnvironment({ workspace: ws, projectPath: null, taskKey: 'SHOP-1' });
        assert.equal(result.env.DB_CONNECTION, 'sqlite');
        assert.equal(result.env.DB_DATABASE, ':memory:');
        assert.match(result.env.APP_KEY, /^base64:/);
        assert.equal(result.database.source, 'injected');
    });

    test('a test config pointing at the development database is refused', () => {
        const project = path.join(root, 'db-dev', 'shop');
        const ws = path.join(root, 'db-dev', 'ws');
        writeFiles(project, { '.env': 'DB_CONNECTION=mysql\nDB_DATABASE=shop\nDB_PASSWORD=secret' });
        writeFiles(ws, { 'phpunit.xml': '<phpunit><php><env name="DB_CONNECTION" value="mysql"/><env name="DB_DATABASE" value="shop"/></php></phpunit>' });

        assert.throws(() => phpTestEnvironment({ workspace: ws, projectPath: project, taskKey: 'SHOP-1' }), { code: 'TEST_DB_UNSAFE' });
    });

    test('a server connection without a database name gets a per-task test database, never the default', () => {
        const ws = path.join(root, 'db-mysql');
        writeFiles(ws, { 'phpunit.xml': '<phpunit><php><env name="DB_CONNECTION" value="mysql"/><env name="APP_KEY" value="base64:abc"/></php></phpunit>' });

        const result = phpTestEnvironment({ workspace: ws, projectPath: path.join(root, 'shop'), taskKey: 'SHOP-12' });
        assert.equal(result.env.DB_DATABASE, 'eaj_shop_shop-12_test'.replace(/-/g, '_'));
        assert.equal(result.env.APP_KEY, undefined, 'the project APP_KEY from phpunit.xml is kept');
    });
});

describe('running tools in a task workspace (Plan §33, §83)', () => {
    const projects = path.join(root, 'www');
    const workspacesRoot = path.join(root, 'EAJ', 'workspaces');
    const ctx = { allowedRoots: [projects], workspacesRoot };
    const project = path.join(projects, 'shop');

    // Small Node scripts stand in for real tools: same exit codes and output formats.
    makeRepo(project, {
        '.gitignore': 'node_modules\n',
        'package.json': {
            name: 'shop',
            scripts: { lint: 'node tools/lint.js', test: 'node tools/test.js', build: 'node tools/sleep.js' },
            devDependencies: { prettier: '^3', jest: '^29' },
        },
        'tools/lint.js': `console.log('\\n' + require('path').resolve('src/cart.js') + '\\n  2:7  error  Unexpected var, use let or const instead  no-var\\n\\n✖ 1 problem (1 error, 0 warnings)'); process.exit(1);`,
        'tools/test.js': [
            `const filtered = process.argv.includes('-t');`,
            `console.log('secret visible: ' + (process.env.EAJ_TEST_LEAK ?? 'no'));`,
            `console.log('db: ' + (process.env.DB_PASSWORD ?? 'none'));`,
            `if (filtered) { console.log('Tests:       1 passed, 1 total'); process.exit(0); }`,
            `console.log('  ● Cart › applies discount\\n\\n    expected 80 got 90 (token sk-proj-abcdefghijklmnopqrstuvwxyz123456)\\n');`,
            `console.log('Tests:       1 failed, 2 passed, 3 total'); process.exit(1);`,
        ].join('\n'),
        'tools/sleep.js': 'setTimeout(() => {}, 60_000);',
        'src/cart.js': 'var total = 1;\n',
        'src/untouched.js': 'var legacy = 1;\n',
    });

    /** @type {string} */
    let ws;
    /** @type {string} */
    let base;
    const run = (/** @type {Record<string, any>} */ payload, io = {}) => runTool({ workspace_path: ws, project_path: project, task_key: 'SHOP-7', ...payload }, ctx, io);

    test('setup: a workspace with installed node_modules', async () => {
        const created = await createWorkspace({ project_path: project, task_key: 'SHOP-7', slug: 'cart' }, ctx);
        ws = created.path;
        base = created.base_sha;

        // A stand-in prettier: --check lists files without a "// formatted" header; --write adds it.
        writeFiles(ws, {
            'node_modules/prettier/package.json': { name: 'prettier', bin: 'bin.js' },
            'node_modules/prettier/bin.js': [
                `const fs = require('fs'); const [mode, , ...files] = process.argv.slice(2);`,
                `let bad = 0; for (const f of files) { const t = fs.readFileSync(f, 'utf8'); if (t.startsWith('// formatted')) continue;`,
                `  if (mode === '--write') fs.writeFileSync(f, '// formatted\\n' + t); else { console.log('[warn] ' + f.replace(/^\\.\\//, '')); bad++; } }`,
                `process.exit(bad ? 1 : 0);`,
            ].join('\n'),
        });
    });

    test('lint failures are parsed into issues with workspace-relative paths', async () => {
        const result = await run({ tool: 'js.lint' });

        assert.equal(result.status, 'failed');
        assert.equal(result.exit_code, 1);
        assert.equal(result.command, 'npm run lint');
        assert.deepEqual(result.issues, [{ file: 'src/cart.js', line: 2, column: 7, severity: 'error', message: 'Unexpected var, use let or const instead', rule: 'no-var' }]);
        assert.match(result.head_sha, /^[0-9a-f]{40}$/);
    });

    test('tests run with a sanitized environment, redacted output and a flaky re-run', async () => {
        process.env.EAJ_TEST_LEAK = 'leaked';
        process.env.DB_PASSWORD = 'hunter2';
        const progress = /** @type {string[]} */ ([]);

        try {
            const result = await run({ tool: 'js.test', rerun_failed: true }, { progress: async (/** @type {string} */ m) => void progress.push(m) });

            assert.equal(result.status, 'flaky', 'the only failure passed when re-run on its own');
            assert.deepEqual(result.tests, { total: 3, passed: 2, failed: 1, skipped: 0 });
            assert.deepEqual(result.flaky, ['Cart › applies discount']);
            assert.equal(result.failures[0].flaky, true);
            assert.match(result.output_tail, /secret visible: no/);
            assert.match(result.output_tail, /db: none/);
            assert.doesNotMatch(JSON.stringify(result), /sk-proj-/);
            assert.ok(progress.some((m) => /re-running "applies discount"/.test(m)), 'the re-run is reported as it happens');
        } finally {
            delete process.env.EAJ_TEST_LEAK;
            delete process.env.DB_PASSWORD;
        }
    });

    test('formatter check is scoped to the files the task changed', async () => {
        assert.equal((await run({ tool: 'js.format', base_sha: base })).status, 'skipped', 'nothing changed yet');

        fs.appendFileSync(path.join(ws, 'src/cart.js'), 'total += 1;\n');
        const result = await run({ tool: 'js.format', base_sha: base });

        assert.equal(result.status, 'failed');
        assert.equal(result.scoped_files, 1);
        assert.deepEqual(result.unformatted, ['src/cart.js'], 'src/untouched.js is legacy code the task did not touch');
        assert.deepEqual(result.files_changed, []);
    });

    test('formatter fix mode reports exactly which files it changed', async () => {
        const result = await run({ tool: 'js.format', mode: 'fix', base_sha: base });

        assert.equal(result.status, 'passed');
        assert.deepEqual(result.files_changed, ['src/cart.js']);
        assert.deepEqual(result.issues, []);
        assert.match(fs.readFileSync(path.join(ws, 'src/cart.js'), 'utf8'), /^\/\/ formatted/);
        assert.equal(fs.readFileSync(path.join(project, 'src/cart.js'), 'utf8'), 'var total = 1;\n', 'the original project is untouched');
    });

    test('install is skipped when dependencies are already there', async () => {
        const result = await run({ tool: 'js.install' });
        assert.equal(result.status, 'skipped');
    });

    test('a running tool stops when the Studio cancels it or AI is stopped', async () => {
        const controller = new AbortController();
        setTimeout(() => controller.abort(), 1500);
        const started = Date.now();

        await assert.rejects(run({ tool: 'js.build' }, { signal: controller.signal }), { code: 'CANCELLED' });
        assert.ok(Date.now() - started < 20_000);
    });

    test('refuses the original project folder, unknown tools and unsafe filters', async () => {
        await assert.rejects(runTool({ tool: 'js.lint', workspace_path: project, task_key: 'SHOP-7' }, ctx), { code: 'PATH_NOT_ALLOWED' });
        await assert.rejects(run({ tool: 'js.deploy' }), { code: 'TOOL_NOT_AVAILABLE' });
        await assert.rejects(run({ tool: 'e2e.playwright' }), { code: 'TOOL_NOT_AVAILABLE' }, 'a valid key this project does not have');
        await assert.rejects(run({ tool: 'js.test', filter: 'x" & calc' }), { code: 'INVALID_INPUT' });
        await assert.rejects(run({ tool: 'js.lint', mode: 'fix' }), { code: 'INVALID_INPUT' });
    });

    test('a script that turned dangerous in the workspace is blocked', async () => {
        const pkgFile = path.join(ws, 'package.json');
        const pkg = JSON.parse(fs.readFileSync(pkgFile, 'utf8'));
        fs.writeFileSync(pkgFile, JSON.stringify({ ...pkg, scripts: { ...pkg.scripts, lint: 'git push --force origin main' } }));

        await assert.rejects(run({ tool: 'js.lint' }), { code: 'COMMAND_BLOCKED' });
    });
});

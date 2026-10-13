// @ts-check
import fs from 'node:fs';
import path from 'node:path';
import { isDangerous } from '../policy.js';
import { RunnerError } from '../paths.js';
import { ProjectFiles } from '../scanner/files.js';

/**
 * Engineering tools a project really has (Plan §34, §73, §84). Every tool is
 * derived from the project's own files — composer.json, package.json scripts,
 * tool config files — and the Studio can only ask for one by its key. The
 * argv is built here, on the PC; there is no way to send a command line.
 *
 * @typedef {'install' | 'format' | 'static' | 'test' | 'build' | 'e2e'} ToolKind
 * @typedef {'check' | 'fix'} ToolMode
 *
 * @typedef {{
 *   root: string,
 *   mode: ToolMode,
 *   files: string[] | null,
 *   reportPath: string,
 *   filter: string | null,
 * }} BuildOptions
 *
 * @typedef {{
 *   key: string,
 *   kind: ToolKind,
 *   ecosystem: 'php' | 'js' | 'python',
 *   label: string,
 *   command: string,
 *   parser: string,
 *   supportsFix: boolean,
 *   scoped: boolean,
 *   extensions: string[] | null,
 *   filterable: boolean,
 *   blocked: string | null,
 *   installedCheck: string | null,
 *   build: (o: BuildOptions) => { argv: string[], env?: Record<string, string> },
 * }} ToolDef
 *
 * @typedef {{ php?: string, composer?: string, python?: string }} ToolPaths
 */

const PHP_EXT = ['.php'];
const PRETTIER_EXT = ['.js', '.jsx', '.ts', '.tsx', '.mjs', '.cjs', '.mts', '.cts', '.vue', '.svelte', '.css', '.scss', '.less', '.json', '.md', '.mdx', '.html', '.yml', '.yaml', '.graphql'];
const NPM_PLACEHOLDER_TEST = /no test specified/i;

/** PHP extensions that cannot be installed on Windows; Composer may skip only these platform checks there. */
export const WINDOWS_MISSING_EXTENSIONS = ['ext-pcntl', 'ext-posix'];

/** Ordered as the quality gates run them (Plan §33): install → format → static → test → build → e2e. */
export const KIND_ORDER = ['install', 'format', 'static', 'test', 'build', 'e2e'];

/**
 * @param {string} root
 * @param {{ toolPaths?: ToolPaths }} [options]
 * @returns {ToolDef[]}
 */
export function detectTools(root, { toolPaths = {} } = {}) {
    const files = new ProjectFiles(root);
    const composer = files.json('composer.json');
    const pkg = files.json('package.json');
    const php = toolPaths.php || 'php';

    /** @type {ToolDef[]} */
    const tools = [];

    // PHP ------------------------------------------------------------------
    if (composer) {
        const req = { ...(composer.require ?? {}), ...(composer['require-dev'] ?? {}) };
        const has = (/** @type {string} */ name) => name in req;
        const composerArgv = composerLauncher(php, toolPaths.composer);

        tools.push(def({
            key: 'php.install', kind: 'install', ecosystem: 'php', label: 'Install PHP dependencies',
            parser: 'install', installedCheck: 'vendor/autoload.php',
            build: () => ({
                argv: [...composerArgv, 'install', '--no-interaction', '--no-progress', '--prefer-dist'],
                // pcntl/posix do not exist for PHP on Windows (Horizon requires them); nothing else is ignored.
                env: /** @type {Record<string, string>} */ (process.platform === 'win32' ? { COMPOSER_IGNORE_PLATFORM_REQ: WINDOWS_MISSING_EXTENSIONS.join(',') } : {}),
            }),
        }));

        if (has('laravel/pint')) {
            tools.push(def({
                key: 'php.format', kind: 'format', ecosystem: 'php', label: 'PHP formatter (Laravel Pint)',
                parser: 'pint', supportsFix: true, scoped: true, extensions: PHP_EXT,
                build: ({ root: r, mode, files: f }) => ({ argv: [php, vendorBin(r, 'pint'), ...(mode === 'check' ? ['--test'] : []), ...(f ?? [])] }),
            }));
        } else if (has('friendsofphp/php-cs-fixer')) {
            tools.push(def({
                key: 'php.format', kind: 'format', ecosystem: 'php', label: 'PHP formatter (PHP-CS-Fixer)',
                parser: 'php-cs-fixer', supportsFix: true, scoped: true, extensions: PHP_EXT,
                build: ({ root: r, mode, files: f }) => ({
                    argv: [php, vendorBin(r, 'php-cs-fixer'), 'fix', '--no-interaction', ...(mode === 'check' ? ['--dry-run'] : []), ...(f ? ['--path-mode=intersection', '--', ...f] : [])],
                }),
            }));
        }

        const phpstanConfig = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'].find((f) => files.exists(f));
        if ((has('phpstan/phpstan') || has('larastan/larastan') || has('nunomaduro/larastan')) && phpstanConfig) {
            tools.push(def({
                key: 'php.static', kind: 'static', ecosystem: 'php', label: has('phpstan/phpstan') ? 'PHP static analysis (PHPStan)' : 'PHP static analysis (Larastan)',
                parser: 'phpstan',
                build: ({ root: r }) => ({ argv: [php, vendorBin(r, 'phpstan'), 'analyse', '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit=1G'] }),
            }));
        } else if (has('vimeo/psalm') && (files.exists('psalm.xml') || files.exists('psalm.xml.dist'))) {
            tools.push(def({
                key: 'php.static', kind: 'static', ecosystem: 'php', label: 'PHP static analysis (Psalm)',
                parser: 'psalm',
                build: ({ root: r }) => ({ argv: [php, vendorBin(r, 'psalm'), '--output-format=json', '--no-progress'] }),
            }));
        }

        const phpunitConfig = files.exists('phpunit.xml') || files.exists('phpunit.xml.dist');
        if (has('pestphp/pest') || (has('phpunit/phpunit') && phpunitConfig)) {
            const bin = has('pestphp/pest') ? 'pest' : 'phpunit';
            tools.push(def({
                key: 'php.test', kind: 'test', ecosystem: 'php', label: `PHP tests (${bin === 'pest' ? 'Pest' : 'PHPUnit'})`,
                parser: 'junit', filterable: true,
                build: ({ root: r, reportPath, filter }) => ({
                    argv: [php, vendorBin(r, bin), '--colors=never', '--log-junit', reportPath, ...(filter ? ['--filter', filter] : [])],
                }),
            }));
        }
    }

    // JavaScript / TypeScript ---------------------------------------------
    if (pkg) {
        const deps = { ...(pkg.dependencies ?? {}), ...(pkg.devDependencies ?? {}) };
        /** @type {Record<string, string>} */
        const scripts = pkg.scripts ?? {};
        const pm = packageManager(files);
        const runner = scriptRunner(pm);

        /**
         * A tool that runs one of the project's own package.json scripts. npm also
         * runs pre/post scripts, so those are checked for dangerous commands too.
         * @param {string} name
         */
        const script = (name) => {
            const bodies = [scripts[`pre${name}`], scripts[name], scripts[`post${name}`]].filter(Boolean).join(' && ');

            return {
                command: `${runner.display} ${name}`,
                blocked: isDangerous(bodies) ? `The "${name}" script runs a command that needs explicit approval (Plan §85): ${bodies.slice(0, 160)}` : null,
                /** @param {string[]} [extra] */
                argv: (extra = []) => [...runner.argv, name, ...(extra.length ? [...runner.separator, ...extra] : [])],
            };
        };

        const lock = { npm: files.exists('package-lock.json') || files.exists('npm-shrinkwrap.json'), pnpm: true, yarn: true, bun: true }[pm];
        const install = {
            npm: lock ? ['ci', '--no-audit', '--no-fund'] : ['install', '--no-audit', '--no-fund'],
            pnpm: ['install', '--frozen-lockfile'],
            yarn: ['install', '--frozen-lockfile'],
            bun: ['install', '--frozen-lockfile'],
        }[pm];

        tools.push(def({
            key: 'js.install', kind: 'install', ecosystem: 'js', label: 'Install JS dependencies',
            parser: 'install', installedCheck: 'node_modules',
            command: `${pm} ${install.join(' ')}`,
            build: () => ({ argv: [...runner.base, ...install] }),
        }));

        if ('prettier' in deps) {
            tools.push(def({
                key: 'js.format', kind: 'format', ecosystem: 'js', label: 'JS/CSS formatter (Prettier)',
                parser: 'prettier', supportsFix: true, scoped: true, extensions: PRETTIER_EXT,
                command: 'prettier --check --ignore-unknown',
                build: ({ root: r, mode, files: f }) => ({ argv: [process.execPath, nodeBin(r, 'prettier', 'prettier'), mode === 'check' ? '--check' : '--write', '--ignore-unknown', ...(f ?? ['.'])] }),
            }));
        }

        if (scripts.lint) {
            const s = script('lint');
            tools.push(def({ key: 'js.lint', kind: 'static', ecosystem: 'js', label: 'Lint', parser: 'eslint', command: s.command, blocked: s.blocked, build: () => ({ argv: s.argv() }) }));
        }

        const typecheck = ['typecheck', 'type-check', 'types', 'tsc'].find((n) => scripts[n]);
        if (typecheck) {
            const s = script(typecheck);
            tools.push(def({ key: 'js.typecheck', kind: 'static', ecosystem: 'js', label: 'Type check', parser: 'tsc', command: s.command, blocked: s.blocked, build: () => ({ argv: s.argv() }) }));
        } else if ('typescript' in deps && files.exists('tsconfig.json')) {
            tools.push(def({
                key: 'js.typecheck', kind: 'static', ecosystem: 'js', label: 'Type check (tsc)', parser: 'tsc',
                command: 'tsc --noEmit --pretty false',
                build: ({ root: r }) => ({ argv: [process.execPath, nodeBin(r, 'typescript', 'tsc'), '--noEmit', '--pretty', 'false'] }),
            }));
        }

        if (scripts.test && !NPM_PLACEHOLDER_TEST.test(scripts.test)) {
            const s = script('test');
            const runnerName = 'vitest' in deps ? 'Vitest' : 'jest' in deps || 'react-scripts' in deps ? 'Jest' : null;
            tools.push(def({
                key: 'js.test', kind: 'test', ecosystem: 'js', label: runnerName ? `JS tests (${runnerName})` : 'JS tests',
                parser: 'js-tests', filterable: runnerName !== null, command: s.command, blocked: s.blocked,
                // -t is the test-name filter in both Vitest and Jest.
                build: ({ filter }) => ({ argv: s.argv(filter ? ['-t', filter] : []) }),
            }));
        }

        const buildScript = ['build', 'production', 'prod'].find((n) => scripts[n]);
        if (buildScript) {
            const s = script(buildScript);
            tools.push(def({ key: 'js.build', kind: 'build', ecosystem: 'js', label: 'Frontend build', parser: 'build', command: s.command, blocked: s.blocked, build: () => ({ argv: s.argv() }) }));
        }

        const pwConfig = ['playwright.config.ts', 'playwright.config.js', 'playwright.config.mjs', 'playwright.config.cjs'].find((f) => files.exists(f));
        if (('@playwright/test' in deps || 'playwright' in deps) && pwConfig) {
            const pwPackage = '@playwright/test' in deps ? '@playwright/test' : 'playwright';
            tools.push(def({
                key: 'e2e.playwright', kind: 'e2e', ecosystem: 'js', label: 'End-to-end tests (Playwright)',
                parser: 'junit', filterable: true,
                command: 'playwright test --reporter=line,junit',
                build: ({ root: r, reportPath, filter }) => ({
                    argv: [process.execPath, nodeBin(r, pwPackage, 'playwright'), 'test', '--reporter=line,junit', ...(filter ? ['--grep', filter] : [])],
                    env: { PLAYWRIGHT_JUNIT_OUTPUT_NAME: reportPath },
                }),
            }));
        }
    }

    // Python ---------------------------------------------------------------
    const requirements = (files.read('requirements.txt') ?? '') + (files.read('requirements-dev.txt') ?? '') + (files.read('pyproject.toml') ?? '');
    if (/^\s*pytest\b/im.test(requirements) || files.exists('pytest.ini') || /\[tool\.pytest/.test(requirements)) {
        const python = toolPaths.python || (process.platform === 'win32' ? 'python' : 'python3');
        tools.push(def({
            key: 'py.test', kind: 'test', ecosystem: 'python', label: 'Python tests (pytest)',
            parser: 'junit', filterable: true,
            build: ({ reportPath, filter }) => ({ argv: [python, '-m', 'pytest', '-q', `--junitxml=${reportPath}`, ...(filter ? ['-k', filter] : [])] }),
        }));
    }

    return tools.sort((a, b) => KIND_ORDER.indexOf(a.kind) - KIND_ORDER.indexOf(b.kind));
}

/**
 * What the Studio stores about a tool: no argv, nothing executable.
 * @param {ToolDef[]} tools
 */
export function describeTools(tools) {
    return tools.map((t) => ({
        key: t.key,
        kind: t.kind,
        ecosystem: t.ecosystem,
        label: t.label,
        command: t.command,
        parser: t.parser,
        supports_fix: t.supportsFix,
        scoped: t.scoped,
        filterable: t.filterable,
        blocked: t.blocked,
    }));
}

/**
 * @param {Partial<ToolDef> & Pick<ToolDef, 'key' | 'kind' | 'ecosystem' | 'label' | 'parser' | 'build'>} t
 * @returns {ToolDef}
 */
function def(t) {
    const tool = {
        supportsFix: false,
        scoped: false,
        extensions: null,
        filterable: false,
        blocked: null,
        installedCheck: null,
        command: '',
        ...t,
    };

    if (!tool.command) {
        // Display form, with this PC's absolute paths left out.
        const argv = tool.build({ root: '.', mode: 'check', files: null, reportPath: '<report>', filter: null }).argv;
        tool.command = argv
            .map((a, i) => (i === 0 || path.isAbsolute(a) ? path.basename(a).replace(/\.(exe|cmd|bat)$/i, '') : a))
            .join(' ');
    }

    return tool;
}

/**
 * Composer packages install their binaries as PHP proxy scripts in vendor/bin,
 * so `php vendor/bin/<tool>` works on every OS without a shell.
 * @param {string} root
 * @param {string} name
 */
function vendorBin(root, name) {
    const rel = `vendor/bin/${name}`;

    if (root !== '.' && !fs.existsSync(path.join(root, rel))) {
        throw new RunnerError('TOOL_NOT_INSTALLED', `${rel} is missing in the workspace. Run "Install PHP dependencies" first.`);
    }

    return rel;
}

/**
 * Run a package's own CLI entry with this Node, instead of the node_modules/.bin
 * shim (a .cmd on Windows).
 * @param {string} root
 * @param {string} pkgName
 * @param {string} binName
 */
function nodeBin(root, pkgName, binName) {
    if (root === '.') return binName;

    const pkgJson = path.join(root, 'node_modules', ...pkgName.split('/'), 'package.json');
    let manifest;

    try {
        manifest = JSON.parse(fs.readFileSync(pkgJson, 'utf8'));
    } catch {
        throw new RunnerError('TOOL_NOT_INSTALLED', `${pkgName} is not installed in the workspace. Run "Install JS dependencies" first.`);
    }

    const rel = typeof manifest.bin === 'string' ? manifest.bin : manifest.bin?.[binName];
    if (!rel) throw new RunnerError('TOOL_NOT_INSTALLED', `${pkgName} has no "${binName}" executable.`);

    return path.join(path.dirname(pkgJson), rel);
}

/**
 * @param {ProjectFiles} files
 * @returns {'npm' | 'pnpm' | 'yarn' | 'bun'}
 */
function packageManager(files) {
    if (files.exists('pnpm-lock.yaml')) return 'pnpm';
    if (files.exists('yarn.lock')) return 'yarn';
    if (files.exists('bun.lockb') || files.exists('bun.lock')) return 'bun';

    return 'npm';
}

/**
 * @param {'npm' | 'pnpm' | 'yarn' | 'bun'} pm
 */
function scriptRunner(pm) {
    const base = pm === 'npm' ? npmLauncher() : [pm];

    return {
        base,
        argv: [...base, 'run'],
        display: `${pm} run`,
        // npm and pnpm need "--" before arguments meant for the script; yarn and bun pass them through.
        separator: pm === 'npm' || pm === 'pnpm' ? ['--'] : [],
    };
}

/**
 * npm's own JS entry next to this Node, so no npm.cmd/cmd.exe is involved.
 * Falls back to "npm" from PATH.
 */
function npmLauncher() {
    const dir = path.dirname(process.execPath);
    const candidates = [
        path.join(dir, 'node_modules', 'npm', 'bin', 'npm-cli.js'),
        path.join(dir, '..', 'lib', 'node_modules', 'npm', 'bin', 'npm-cli.js'),
    ];
    const found = candidates.find((c) => fs.existsSync(c));

    return found ? [process.execPath, found] : ['npm'];
}

/**
 * @param {string} php
 * @param {string | undefined} composer
 */
function composerLauncher(php, composer) {
    if (composer && /\.phar$/i.test(composer)) return [php, composer];

    return [composer || 'composer'];
}

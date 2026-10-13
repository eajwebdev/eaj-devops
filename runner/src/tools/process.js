// @ts-check
import { spawn, spawnSync } from 'node:child_process';
import { RunnerError } from '../paths.js';

const MAX_CAPTURE = 4 * 1024 * 1024;

/**
 * Environment variables a project tool may see. Everything else the runner
 * process has (tokens, cloud keys, DB_* from the developer's shell) is dropped,
 * so a test suite or build script can never read it (Plan §16, §83).
 */
const PASS_ENV = new Set(
    [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'TMPDIR', 'HOME', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH',
        'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA', 'PROGRAMFILES', 'PROGRAMFILES(X86)', 'PROGRAMW6432', 'COMMONPROGRAMFILES',
        'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE', 'OS', 'LANG', 'SHELL', 'USER', 'USERNAME', 'LOGNAME',
        'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'NPM_CONFIG_CACHE', 'NVM_HOME', 'NVM_SYMLINK', 'NVM_DIR',
        'PLAYWRIGHT_BROWSERS_PATH', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'JAVA_HOME', 'PYTHONHOME', 'VIRTUAL_ENV',
    ].map((k) => k.toUpperCase()),
);

/**
 * @param {Record<string, string>} extra
 * @returns {Record<string, string>}
 */
export function toolEnv(extra = {}) {
    /** @type {Record<string, string>} */
    const env = {};

    for (const [key, value] of Object.entries(process.env)) {
        if (value !== undefined && PASS_ENV.has(key.toUpperCase())) env[key] = value;
    }

    return { ...env, CI: '1', NO_COLOR: '1', FORCE_COLOR: '0', TERM: 'dumb', GIT_TERMINAL_PROMPT: '0', COMPOSER_NO_INTERACTION: '1', ...extra };
}

/**
 * Characters cmd.exe would interpret. Arguments routed through cmd.exe (only
 * .cmd/.bat shims on Windows) must not contain them — they come from the
 * runner's own catalog, so this is a backstop, not input filtering.
 */
const CMD_UNSAFE = /["%^&|<>!()\r\n]/;

/**
 * @typedef {{
 *   cwd: string,
 *   env: Record<string, string>,
 *   timeoutMs: number,
 *   signal?: AbortSignal,
 *   onOutput?: (chunk: string) => void,
 *   graceMs?: number,
 * }} ExecOptions
 *
 * @typedef {{ code: number | null, output: string, truncated: boolean, timedOut: boolean, cancelled: boolean, durationMs: number }} ExecResult
 */

/**
 * Run one tool process without a shell. On Windows, npm/pnpm/yarn/composer are
 * .cmd/.bat shims that Node can only start through cmd.exe; those go through
 * `cmd.exe /d /s /c` with every argument checked and quoted.
 *
 * Stop (Plan §57): a graceful stop of the whole process tree, then a forced
 * kill after the grace period.
 *
 * @param {string[]} argv
 * @param {ExecOptions} options
 * @returns {Promise<ExecResult>}
 */
export function execTool(argv, { cwd, env, timeoutMs, signal, onOutput, graceMs = 10_000 }) {
    const [program, ...args] = argv;
    const { command, commandArgs, verbatim } = resolveLaunch(program, args);
    const started = Date.now();

    return new Promise((resolve, reject) => {
        const child = spawn(command, commandArgs, {
            cwd,
            env,
            shell: false,
            windowsHide: true,
            windowsVerbatimArguments: verbatim,
            detached: process.platform !== 'win32',
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        let output = '';
        let truncated = false;
        let timedOut = false;
        let cancelled = false;

        /** @param {Buffer} data */
        const collect = (data) => {
            const text = data.toString('utf8');
            if (output.length < MAX_CAPTURE) output += text;
            else truncated = true;
            onOutput?.(text);
        };

        child.stdout?.on('data', collect);
        child.stderr?.on('data', collect);

        // Windows console processes cannot be asked to stop (taskkill without /F refuses), so the tree is ended at once there.
        const stop = () => {
            if (process.platform === 'win32') return killTree(child.pid, true);
            killTree(child.pid, false);
            setTimeout(() => killTree(child.pid, true), graceMs).unref();
        };

        const timer = setTimeout(() => {
            timedOut = true;
            stop();
        }, timeoutMs);

        const onAbort = () => {
            cancelled = true;
            stop();
        };

        if (signal?.aborted) onAbort();
        signal?.addEventListener('abort', onAbort, { once: true });

        child.on('error', (error) => {
            clearTimeout(timer);
            signal?.removeEventListener('abort', onAbort);
            reject(new RunnerError('TOOL_NOT_FOUND', `${program} could not start: ${error.message}. Is it installed and on PATH for the runner?`));
        });

        child.on('close', (code) => {
            clearTimeout(timer);
            signal?.removeEventListener('abort', onAbort);
            resolve({ code, output, truncated, timedOut, cancelled, durationMs: Date.now() - started });
        });
    });
}

/**
 * @param {string} program
 * @param {string[]} args
 * @returns {{ command: string, commandArgs: string[], verbatim: boolean }}
 */
function resolveLaunch(program, args) {
    if (process.platform !== 'win32' || !isWindowsShim(program)) {
        return { command: program, commandArgs: args, verbatim: false };
    }

    for (const part of [program, ...args]) {
        if (CMD_UNSAFE.test(part)) {
            throw new RunnerError('COMMAND_BLOCKED', `Refusing to pass "${part}" through cmd.exe.`);
        }
    }

    const line = [program, ...args].map((a) => (/\s/.test(a) || a === '' ? `"${a}"` : a)).join(' ');

    return { command: process.env.ComSpec ?? 'cmd.exe', commandArgs: ['/d', '/s', '/c', `"${line}"`], verbatim: true };
}

/** @type {Map<string, boolean>} */
const shimCache = new Map();

/**
 * True when `program` resolves to a .cmd/.bat file on PATH (npm, npx, pnpm,
 * yarn, composer under XAMPP/Laragon) rather than an .exe.
 * @param {string} program
 */
function isWindowsShim(program) {
    if (/\.(cmd|bat)$/i.test(program)) return true;
    if (/\.exe$/i.test(program) || /[\\/]/.test(program)) return false;

    const cached = shimCache.get(program.toLowerCase());
    if (cached !== undefined) return cached;

    // "where" also lists extensionless scripts meant for Git Bash (e.g. ...\ComposerSetup\bin\composer);
    // Windows can only start the .exe/.com/.cmd/.bat ones.
    const found = spawnSync('where', [program], { encoding: 'utf8', windowsHide: true });
    const runnable = found.status === 0 ? (found.stdout.split(/\r?\n/).map((l) => l.trim()).find((l) => /\.(exe|com|cmd|bat)$/i.test(l)) ?? '') : '';
    const shim = /\.(cmd|bat)$/i.test(runnable);
    shimCache.set(program.toLowerCase(), shim);

    return shim;
}

/**
 * @param {number | undefined} pid
 * @param {boolean} force
 */
function killTree(pid, force) {
    if (!pid) return;

    try {
        if (process.platform === 'win32') {
            spawnSync('taskkill', ['/pid', String(pid), '/T', ...(force ? ['/F'] : [])], { windowsHide: true, stdio: 'ignore' });
        } else {
            process.kill(-pid, force ? 'SIGKILL' : 'SIGTERM');
        }
    } catch {
        // Already gone.
    }
}

/** Remove ANSI colour/cursor sequences that some tools print even with NO_COLOR. */
export function stripAnsi(/** @type {string} */ text) {
    // eslint-disable-next-line no-control-regex
    return text.replace(/\u001b\[[0-9;?]*[ -/]*[@-~]|\u001b\][^\u0007]*\u0007/g, '').replace(/\r\n?/g, '\n');
}

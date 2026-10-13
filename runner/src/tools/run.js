// @ts-check
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { git } from '../exec.js';
import { assertAllowedProjectPath, RunnerError } from '../paths.js';
import { assertToolCommandAllowed } from '../policy.js';
import { redactText } from '../redact.js';
import { assertWorktree, TASK_KEY } from '../workspace.js';
import { detectTools, WINDOWS_MISSING_EXTENSIONS } from './catalog.js';
import { parseToolOutput } from './parsers.js';
import { execTool, stripAnsi, toolEnv } from './process.js';
import { phpTestEnvironment } from './test-env.js';

/**
 * Runs one approved project tool inside a task workspace (Plan §33, §34, §84).
 * Never in the original project folder: tools write caches, reports and — for
 * formatters — source files, and only workspaces may change (guarantee G1).
 *
 * @typedef {import('./catalog.js').ToolPaths} ToolPaths
 * @typedef {{ allowedRoots: string[], workspacesRoot: string, toolPaths?: ToolPaths }} Ctx
 * @typedef {{
 *   tool: string,
 *   workspace_path: string,
 *   project_path?: string | null,
 *   task_key: string,
 *   mode?: 'check' | 'fix',
 *   scope?: 'changed' | 'all',
 *   base_sha?: string | null,
 *   filter?: string | null,
 *   rerun_failed?: boolean,
 *   force?: boolean,
 * }} ToolPayload
 */

const TOOL_KEY = /^[a-z][a-z0-9]*\.[a-z0-9-]+$/;
const SHA = /^[0-9a-f]{7,64}$/;
// Test-name filters: plain words only, so they mean the same in every runner and never reach a shell.
const FILTER = /^[\w\\:. -]{1,200}$/;
const TIMEOUT_MS = { install: 15, format: 5, static: 15, test: 30, build: 15, e2e: 30 };
const MAX_SCOPED_FILES = 300;
const PROGRESS_EVERY_MS = 5_000;
const ALIVE_EVERY_MS = 30_000;
const OUTPUT_TAIL_LINES = 150;
const OUTPUT_TAIL_BYTES = 48 * 1024;
const MAX_FLAKY_RERUNS = 5;

/**
 * @param {ToolPayload} payload
 * @param {Ctx} ctx
 * @param {{ signal?: AbortSignal, progress?: (message: string) => Promise<void> }} [io]
 */
export async function runTool(payload, ctx, { signal, progress = async () => {} } = {}) {
    const workspace = await assertWorktree(payload.workspace_path, ctx);
    const mode = payload.mode ?? 'check';
    const scope = payload.scope ?? 'changed';

    if (!TOOL_KEY.test(payload.tool ?? '')) throw new RunnerError('INVALID_INPUT', 'Invalid tool key.');
    if (!TASK_KEY.test(payload.task_key ?? '')) throw new RunnerError('INVALID_INPUT', 'Invalid task key.');
    if (mode !== 'check' && mode !== 'fix') throw new RunnerError('INVALID_INPUT', 'Mode must be check or fix.');
    if (scope !== 'changed' && scope !== 'all') throw new RunnerError('INVALID_INPUT', 'Scope must be changed or all.');
    if (payload.base_sha && !SHA.test(payload.base_sha)) throw new RunnerError('INVALID_INPUT', 'Invalid base SHA.');
    if (payload.filter && !FILTER.test(payload.filter)) throw new RunnerError('INVALID_INPUT', 'Invalid test filter.');

    const projectPath = payload.project_path ? assertAllowedProjectPath(payload.project_path, ctx.allowedRoots) : null;

    // The catalog is re-derived from the workspace itself: the Studio names a tool, the PC decides what that means.
    const tool = detectTools(workspace, { toolPaths: ctx.toolPaths }).find((t) => t.key === payload.tool);

    if (!tool) throw new RunnerError('TOOL_NOT_AVAILABLE', `This project has no "${payload.tool}" tool (it is not configured in the workspace).`);
    if (tool.blocked) throw new RunnerError('COMMAND_BLOCKED', tool.blocked);
    if (mode === 'fix' && !tool.supportsFix) throw new RunnerError('INVALID_INPUT', `${tool.label} has no fix mode.`);

    const opts = { cwd: workspace, workspacesRoot: ctx.workspacesRoot };
    const head = (await git(['rev-parse', 'HEAD'], opts)).stdout.trim();
    const base = { tool: tool.key, kind: tool.kind, label: tool.label, command: tool.command, mode, scope, head_sha: head };

    if (tool.installedCheck && !payload.force && fs.existsSync(path.join(workspace, tool.installedCheck))) {
        return skipped(base, `${tool.installedCheck} is already present in the workspace.`);
    }

    /** @type {string[] | null} */
    let files = null;

    if (tool.scoped && scope === 'changed') {
        const changed = (await changedFiles(workspace, ctx.workspacesRoot, payload.base_sha ?? null)).filter(
            (f) => !tool.extensions || tool.extensions.includes(path.extname(f).toLowerCase()),
        );

        if (changed.length === 0) return skipped(base, 'No changed files for this tool in the workspace.');

        // Very large change sets: check the whole project instead of an over-long command line.
        files = changed.length <= MAX_SCOPED_FILES ? changed.map((f) => `./${f}`) : null;
    }

    /** @type {string[]} */
    const notes = [];
    /** @type {Record<string, string>} */
    let extraEnv = {};
    /** @type {import('./test-env.js').TestDatabase | null} */
    let database = null;

    if (tool.key === 'php.install' && process.platform === 'win32') {
        notes.push(`Platform checks for ${WINDOWS_MISSING_EXTENSIONS.join(' and ')} were skipped: PHP on Windows cannot have them.`);
    }

    if (tool.key === 'php.test') {
        const testEnv = phpTestEnvironment({ workspace, projectPath, taskKey: payload.task_key });
        extraEnv = testEnv.env;
        database = testEnv.database;
        notes.push(...testEnv.notes);
    }

    const reportDir = fs.mkdtempSync(path.join(os.tmpdir(), 'eaj-tool-'));
    const reportPath = path.join(reportDir, 'report.xml');

    try {
        const before = await dirtyState(workspace, ctx.workspacesRoot);

        await progress(`${tool.label}: starting${files ? ` on ${files.length} changed file(s)` : ''}…`);

        const first = await execute(tool, { root: workspace, mode, files, reportPath, filter: payload.filter ?? null }, extraEnv, { signal, progress });
        let parsed = parseToolOutput(tool.parser, { output: first.output, report: readReport(reportPath), root: workspace });
        let status = verdict(first, parsed);

        if (first.timedOut) notes.push(`Stopped after ${TIMEOUT_MS[tool.kind]} minutes (time limit).`);
        if (first.truncated) notes.push('Output was longer than 4 MB; only the start was parsed.');

        // §48.8 flaky handling: re-run each failing test once on its own. Passing alone marks it flaky —
        // reported, and still blocking until a person accepts it.
        /** @type {string[]} */
        const flaky = [];

        if (status === 'failed' && payload.rerun_failed && tool.filterable && !payload.filter && parsed.failures.length > 0 && parsed.failures.length <= MAX_FLAKY_RERUNS) {
            for (const failure of parsed.failures) {
                const name = testName(failure.name);

                if (!name || !FILTER.test(name)) continue;

                await progress(`${tool.label}: re-running "${name}" on its own to check for flakiness…`);
                fs.rmSync(reportPath, { force: true });
                const again = await execute(tool, { root: workspace, mode, files, reportPath, filter: name }, extraEnv, { signal, progress });

                if (again.code === 0 && !again.timedOut) flaky.push(failure.name);
            }

            if (flaky.length === parsed.failures.length) status = 'flaky';
        }

        const after = await dirtyState(workspace, ctx.workspacesRoot);
        const filesChanged = [...new Set([...after.keys(), ...before.keys()])].filter((f) => before.get(f) !== after.get(f)).sort();

        if (mode === 'fix') {
            // Fixed files are the result, not problems.
            parsed = { ...parsed, issues: [] };
        } else if (filesChanged.length > 0 && tool.kind !== 'install') {
            notes.push(`The tool changed ${filesChanged.length} file(s) while checking.`);
        }

        return {
            ...base,
            status,
            exit_code: first.code,
            duration_ms: first.durationMs,
            tests: parsed.tests,
            failures: parsed.failures.map((f) => ({ ...f, message: redactText(f.message), flaky: flaky.includes(f.name) })),
            issues: parsed.issues.map((i) => ({ ...i, message: redactText(i.message) })),
            unformatted: parsed.files,
            files_changed: filesChanged.slice(0, 500),
            scoped_files: files?.length ?? null,
            flaky,
            database,
            notes,
            output_tail: redactText(tail(first.output)),
        };
    } finally {
        fs.rmSync(reportDir, { recursive: true, force: true });
    }
}

/**
 * @param {import('./catalog.js').ToolDef} tool
 * @param {import('./catalog.js').BuildOptions} build
 * @param {Record<string, string>} extraEnv
 * @param {{ signal?: AbortSignal, progress: (message: string) => Promise<void> }} io
 */
async function execute(tool, build, extraEnv, { signal, progress }) {
    const { argv, env } = tool.build(build);
    assertToolCommandAllowed(argv);

    let lastLine = '';
    let lastSent = Date.now();
    const started = Date.now();
    /** @type {Promise<void>} */
    let sending = Promise.resolve();

    const send = (/** @type {string} */ message) => {
        lastSent = Date.now();
        sending = sending.then(() => progress(message)).catch(() => {});
    };

    // Liveness for the Studio (extends the command lease) even when a tool prints nothing for minutes.
    const alive = setInterval(() => {
        if (Date.now() - lastSent >= ALIVE_EVERY_MS) send(`${tool.label}: still running (${Math.round((Date.now() - started) / 1000)}s)…`);
    }, ALIVE_EVERY_MS / 3);

    try {
        const result = await execTool(argv, {
            cwd: build.root,
            env: toolEnv({ ...env, ...extraEnv }),
            timeoutMs: TIMEOUT_MS[tool.kind] * 60_000,
            signal,
            onOutput: (chunk) => {
                const line = stripAnsi(chunk).split('\n').map((l) => l.trim()).filter(Boolean).pop();
                if (line) lastLine = line;
                if (lastLine && Date.now() - lastSent >= PROGRESS_EVERY_MS) send(`${tool.label}: ${redactText(lastLine).slice(0, 300)}`);
            },
        });

        if (result.cancelled) throw new RunnerError('CANCELLED', `${tool.label} was stopped.`);

        return { ...result, output: stripAnsi(result.output) };
    } finally {
        clearInterval(alive);
        await sending;
    }
}

/**
 * Exit code decides; parsed output explains. A non-zero exit with nothing
 * parseable means the tool itself could not run properly.
 *
 * @param {{ code: number | null, timedOut: boolean }} run
 * @param {import('./parsers.js').Parsed} parsed
 * @returns {'passed' | 'failed' | 'error' | 'flaky'}
 */
function verdict(run, parsed) {
    if (run.timedOut) return 'error';

    const evidence = parsed.failures.length > 0 || parsed.issues.some((i) => i.severity === 'error') || (parsed.tests?.failed ?? 0) > 0;

    if (run.code === 0) return (parsed.tests?.failed ?? 0) > 0 ? 'failed' : 'passed';

    return evidence ? 'failed' : 'error';
}

/**
 * @param {Record<string, unknown>} base
 * @param {string} reason
 */
function skipped(base, reason) {
    return {
        ...base,
        status: 'skipped',
        exit_code: null,
        duration_ms: 0,
        tests: null,
        failures: [],
        issues: [],
        unformatted: [],
        files_changed: [],
        scoped_files: 0,
        flaky: [],
        database: null,
        notes: [reason],
        output_tail: '',
    };
}

/**
 * Files the task changed: committed since the base, plus uncommitted and new.
 * @param {string} workspace
 * @param {string} workspacesRoot
 * @param {string | null} baseSha
 */
async function changedFiles(workspace, workspacesRoot, baseSha) {
    const opts = { cwd: workspace, workspacesRoot };
    const diff = await git(['diff', '--name-only', '-z', '--diff-filter=ACMR', baseSha ?? 'HEAD'], opts);
    const untracked = await git(['ls-files', '--others', '--exclude-standard', '-z'], opts);

    return [...new Set([...diff.stdout.split('\0'), ...untracked.stdout.split('\0')].filter(Boolean))]
        .filter((f) => fs.existsSync(path.join(workspace, f)))
        .sort();
}

/**
 * Content hash of every uncommitted path, to see exactly which files a tool changed.
 * @param {string} workspace
 * @param {string} workspacesRoot
 * @returns {Promise<Map<string, string>>}
 */
async function dirtyState(workspace, workspacesRoot) {
    const status = await git(['status', '--porcelain=v1', '-z', '--untracked-files=all'], { cwd: workspace, workspacesRoot });
    const entries = status.stdout.split('\0');
    /** @type {Map<string, string>} */
    const state = new Map();

    for (let i = 0; i < entries.length && state.size < 5000; i++) {
        const entry = entries[i];
        if (entry.length < 4) continue;

        const file = entry.slice(3);
        // Renames list the original path as the next NUL-separated entry.
        if (entry[0] === 'R' || entry[0] === 'C') i++;

        state.set(file, hashFile(path.join(workspace, file)));
    }

    return state;
}

/** @param {string} file */
function hashFile(file) {
    try {
        const stat = fs.statSync(file);
        if (!stat.isFile()) return 'dir';
        if (stat.size > 20 * 1024 * 1024) return `size:${stat.size}:${stat.mtimeMs}`;

        return crypto.createHash('sha1').update(fs.readFileSync(file)).digest('hex');
    } catch {
        return 'deleted';
    }
}

/** @param {string} file */
function readReport(file) {
    try {
        const stat = fs.statSync(file);

        return stat.size <= 20 * 1024 * 1024 ? fs.readFileSync(file, 'utf8') : null;
    } catch {
        return null;
    }
}

/**
 * The innermost test name ("Tests\\Feature\\BookingTest › test_overlap" → "test_overlap").
 * @param {string} name
 */
function testName(name) {
    return name.split(/\s+[›>]\s+/).pop()?.trim() ?? '';
}

/** @param {string} output */
function tail(output) {
    const lines = output.split('\n');
    const text = lines.slice(-OUTPUT_TAIL_LINES).join('\n');

    return text.length > OUTPUT_TAIL_BYTES ? text.slice(-OUTPUT_TAIL_BYTES) : text;
}

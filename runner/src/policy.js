// @ts-check
import path from 'node:path';
import { isInside, RunnerError } from './paths.js';

/**
 * Local command policy (Plan §84–85, §89.2). Enforced on the PC, so even a
 * compromised Studio cannot make the runner run anything outside this list.
 *
 * Git runs through assertGitAllowed; project tools (tests, linters, builds)
 * are built by tools/catalog.js and pass assertToolCommandAllowed.
 */

/** Git subcommands the runner may run anywhere inside an allowed project. */
const READ_ONLY_GIT = new Set(['rev-parse', 'status', 'branch', 'log', 'remote', 'ls-files', 'worktree', 'show-ref', 'symbolic-ref', 'config', 'diff', 'check-ref-format', 'stash', 'count-objects', 'cat-file']);

/** Git subcommands that write; each has extra rules below. */
const WRITING_GIT = new Set(['init', 'add', 'commit', 'rm', 'reset', 'clean', 'push']);

/**
 * @typedef {{ cwd: string, workspacesRoot: string }} PolicyContext
 */

/**
 * Throws unless `git <args>` may run in `ctx.cwd`.
 * @param {string[]} args
 * @param {PolicyContext} ctx
 */
export function assertGitAllowed(args, ctx) {
    const sub = args.find((a) => !a.startsWith('-') && !isConfigValue(args, a));

    if (!sub || (!READ_ONLY_GIT.has(sub) && !WRITING_GIT.has(sub))) {
        throw new RunnerError('COMMAND_BLOCKED', `git ${sub ?? ''} is not on the runner's approved list.`);
    }

    const inWorkspace = isInside(ctx.workspacesRoot, ctx.cwd);
    const rest = args.slice(args.indexOf(sub) + 1);

    // --force is only ever used to remove a task worktree the owner approved removing.
    if ((rest.includes('--force') || rest.includes('-f')) && !(sub === 'worktree' && rest[0] === 'remove') && sub !== 'clean') {
        throw new RunnerError('COMMAND_BLOCKED', 'Force operations are blocked on the runner.');
    }

    if (sub === 'config' && !rest.some((a) => a === '--get' || a === '--get-regexp')) {
        throw new RunnerError('COMMAND_BLOCKED', 'The runner may only read Git configuration.');
    }

    // Destructive working-tree operations: only inside a task workspace (Plan §48.4).
    if ((sub === 'reset' && rest.includes('--hard')) || sub === 'clean') {
        if (!inWorkspace) {
            throw new RunnerError('COMMAND_BLOCKED', `git ${sub} ${rest.join(' ')} is only allowed inside a task workspace.`);
        }
    }

    if (sub === 'stash' && !['create', 'store', 'list'].includes(rest[0] ?? '')) {
        throw new RunnerError('COMMAND_BLOCKED', 'Only non-destructive stash operations (create/store/list) are allowed.');
    }

    if (sub === 'worktree' && !['add', 'list', 'remove', 'prune'].includes(rest[0] ?? '')) {
        throw new RunnerError('COMMAND_BLOCKED', `git worktree ${rest[0] ?? ''} is not allowed.`);
    }

    if (sub === 'branch' && rest.some((a) => /^-(d|D|m|M|-delete|-move)$/.test(a))) {
        throw new RunnerError('COMMAND_BLOCKED', 'Deleting or renaming branches is not allowed from the runner.');
    }

    if (sub === 'push') {
        const ref = rest[2] ?? '';
        const [from, to] = ref.split(':');
        if (!inWorkspace || rest.length !== 3 || rest[0] !== '--set-upstream' || rest[1] !== 'origin' || !/^ai\/[A-Z][A-Z0-9]{1,9}-\d{1,7}-[a-z0-9-]+$/.test(from ?? '') || from !== to) {
            throw new RunnerError('COMMAND_BLOCKED', 'The runner may only publish the current ai/* task branch to the same origin branch.');
        }
    }
}

/**
 * `-c key=value` pairs precede the subcommand; their values are not subcommands.
 * @param {string[]} args
 * @param {string} a
 */
function isConfigValue(args, a) {
    const i = args.indexOf(a);

    return i > 0 && args[i - 1] === '-c';
}

/** Commands from Plan §85 that always need a human, whatever the context. */
export const DANGEROUS_PATTERNS = [
    /\brm\s+-[a-z]*r[a-z]*f|\brm\s+-[a-z]*f[a-z]*r/i,
    /\bdrop\s+database\b/i,
    /\btruncate\b/i,
    /\bgit\s+reset\s+--hard\b/i,
    /\bgit\s+clean\s+-[a-z]*f/i,
    /\bgit\s+push\b.*(--force|-f\b)/i,
    /\bcomposer\s+update\b/i,
    /\bnpm\s+audit\s+fix\s+--force\b/i,
    /\bmigrate(:fresh|:reset|:rollback)?\b.*--force\b/i,
    /(?:^|[;&|]\s*)(?:sudo\s+)?(shutdown|reboot|halt|poweroff)\b/i,
    /(?:^|[;&|]\s*)format\s+[a-z]:/i,
];

/**
 * @param {string} commandLine
 */
export function isDangerous(commandLine) {
    return DANGEROUS_PATTERNS.some((re) => re.test(commandLine));
}

/** Programs a project tool may start (Plan §84): language runtimes and package managers only. */
const APPROVED_PROGRAMS = new Set(['php', 'composer', 'node', 'npm', 'pnpm', 'yarn', 'bun', 'python', 'python3', 'py']);

/**
 * Throws unless a tool command built by the catalog may run.
 * The catalog only builds approved commands; this is the independent check.
 *
 * @param {string[]} argv
 */
export function assertToolCommandAllowed(argv) {
    const program = path.basename(argv[0] ?? '').toLowerCase().replace(/\.(exe|cmd|bat)$/, '');

    if (argv[0] !== process.execPath && !APPROVED_PROGRAMS.has(program)) {
        throw new RunnerError('COMMAND_BLOCKED', `${program || 'This program'} is not on the runner's approved tool list.`);
    }

    // Absolute paths are this PC's own (node, the report file); judge the command by its words.
    const line = argv.map((a) => (path.isAbsolute(a) ? path.basename(a) : a)).join(' ');

    if (isDangerous(line)) {
        throw new RunnerError('COMMAND_BLOCKED', `"${line.slice(0, 200)}" needs explicit approval (Plan §85).`);
    }
}

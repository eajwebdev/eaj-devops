// @ts-check
import { spawn } from 'node:child_process';
import { assertGitAllowed } from './policy.js';
import { RunnerError } from './paths.js';

const MAX_OUTPUT = 4 * 1024 * 1024;

/**
 * Run git with an argument array — never through a shell, so nothing in a path
 * or branch name can be interpreted as a command. Every call passes the local
 * policy first.
 *
 * @param {string[]} args
 * @param {{ cwd: string, workspacesRoot: string, timeoutMs?: number, signal?: AbortSignal, allowFailure?: boolean, env?: Record<string, string> }} options
 * @returns {Promise<{ code: number, stdout: string, stderr: string }>}
 */
export function git(args, options) {
    assertGitAllowed(args, { cwd: options.cwd, workspacesRoot: options.workspacesRoot });

    return run('git', args, options);
}

/**
 * @param {string} command
 * @param {string[]} args
 * @param {{ cwd: string, timeoutMs?: number, signal?: AbortSignal, allowFailure?: boolean, env?: Record<string, string> }} options
 * @returns {Promise<{ code: number, stdout: string, stderr: string }>}
 */
function run(command, args, { cwd, timeoutMs = 120_000, signal, allowFailure = false, env = {} }) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            cwd,
            shell: false,
            windowsHide: true,
            env: { ...process.env, GIT_TERMINAL_PROMPT: '0', GIT_OPTIONAL_LOCKS: '0', LC_ALL: 'C', ...env },
        });

        let stdout = '';
        let stderr = '';
        const timer = setTimeout(() => child.kill(), timeoutMs);
        const onAbort = () => child.kill();
        signal?.addEventListener('abort', onAbort, { once: true });

        child.stdout.on('data', (d) => {
            if (stdout.length < MAX_OUTPUT) stdout += d;
        });
        child.stderr.on('data', (d) => {
            if (stderr.length < MAX_OUTPUT) stderr += d;
        });

        child.on('error', (error) => {
            clearTimeout(timer);
            reject(new RunnerError('COMMAND_FAILED', `${command} could not start: ${error.message}`));
        });

        child.on('close', (code) => {
            clearTimeout(timer);
            signal?.removeEventListener('abort', onAbort);

            if (signal?.aborted) {
                reject(new RunnerError('CANCELLED', `${command} was stopped.`));
            } else if (code !== 0 && !allowFailure) {
                const sub = args.find((a, i) => !a.startsWith('-') && args[i - 1] !== '-c') ?? '';
                reject(new RunnerError('COMMAND_FAILED', `${command} ${sub} failed: ${(stderr.trim() || stdout.trim()).slice(0, 2000)}`));
            } else {
                resolve({ code: code ?? 1, stdout, stderr });
            }
        });
    });
}

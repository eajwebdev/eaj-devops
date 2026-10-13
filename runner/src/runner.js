// @ts-check
import { ApiError, RUNNER_VERSION, StudioClient, verifyCommand } from './api.js';
import { loadState, saveState } from './config.js';
import { initializeGit } from './git-init.js';
import { gitState } from './git-state.js';
import { indexFile, listFiles } from './indexer.js';
import { assertAllowedProjectPath, RunnerError } from './paths.js';
import { scanProject } from './scanner/scan.js';
import { checkpointWorkspace, createWorkspace, removeWorkspace, resetWorkspace } from './workspace.js';

const HEARTBEAT_MS = 15_000;
const IDLE_POLL_MS = 2_000;
const MAX_BACKOFF_MS = 30_000;
const INDEX_CHUNK = 400;

/**
 * @typedef {import('./config.js').RunnerConfig} RunnerConfig
 * @typedef {import('./config.js').CommandOutcome} CommandOutcome
 * @typedef {{ id: string, type: string, payload: Record<string, any>, progress: (message: string) => Promise<void> }} CommandContext
 */

/**
 * The runner loop: heartbeat every 15s (Plan §48.5), claim one signed
 * command at a time, run it locally, report the outcome. Outcomes that cannot
 * be delivered are kept and replayed on reconnect (Plan §89.2).
 */
export class Runner {
    /**
     * @param {RunnerConfig} config
     * @param {{ client?: StudioClient, log?: (line: string) => void }} [options]
     */
    constructor(config, { client, log = console.log } = {}) {
        if (!config.server || !config.token || !config.signingSecret) {
            throw new RunnerError('NOT_PAIRED', 'This runner is not paired yet. Run: eaj-runner pair <studio-url> <code>');
        }

        this.config = config;
        this.client = client ?? new StudioClient({ server: config.server, token: config.token, insecure: config.insecure });
        this.log = log;
        this.state = loadState();
        this.halted = false;
        this.stopped = false;
        /** @type {ReturnType<typeof setInterval> | undefined} */
        this.heartbeatTimer = undefined;
    }

    /**
     * @param {{ once?: boolean }} [options] once: process what is queued, then return
     */
    async start({ once = false } = {}) {
        await this.heartbeat();
        if (!once) this.heartbeatTimer = setInterval(() => void this.heartbeat(), HEARTBEAT_MS);

        let backoff = IDLE_POLL_MS;

        while (!this.stopped) {
            try {
                await this.flushPending();

                const { command, halted } = await this.client.claim();
                this.halted = halted;
                backoff = IDLE_POLL_MS;

                if (command) {
                    await this.handle(command);
                    continue;
                }

                if (once) break;
            } catch (error) {
                if (error instanceof ApiError && error.status === 401) {
                    this.log('The Studio rejected this runner (revoked or token invalid). Pair again with: eaj-runner pair <studio-url> <code>');
                    this.stop();
                    break;
                }

                this.log(`Studio unreachable (${/** @type {Error} */ (error).message}). Retrying in ${Math.round(backoff / 1000)}s.`);
                if (once) break;
                backoff = Math.min(backoff * 2, MAX_BACKOFF_MS);
            }

            await sleep(backoff);
        }

        this.stop();
    }

    stop() {
        this.stopped = true;
        clearInterval(this.heartbeatTimer);
    }

    async heartbeat() {
        try {
            const response = await this.client.heartbeat({
                version: RUNNER_VERSION,
                allowed_roots: this.config.allowedRoots,
                workspaces_root: this.config.workspacesRoot,
                capabilities: Object.keys(HANDLERS),
            });
            this.halted = response.halted;
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) this.stop();
        }
    }

    /**
     * @param {import('./api.js').SignedCommand} command
     */
    async handle(command) {
        // Already finished once (the result post was lost): answer from local state, never re-run.
        const previous = this.state.completed[command.id];
        if (previous) {
            const { at: _at, ...outcome } = previous;
            await this.deliver(command.id, outcome);

            return;
        }

        /** @type {CommandOutcome} */
        let outcome;
        const started = Date.now();

        try {
            const payload = verifyCommand(command, /** @type {string} */ (this.config.signingSecret));
            const handler = HANDLERS[command.type];

            if (!handler) throw new RunnerError('UNKNOWN_COMMAND', `This runner (v${RUNNER_VERSION}) does not support "${command.type}".`);

            this.log(`→ ${command.type} ${command.id}`);
            const result = await handler.call(this, {
                id: command.id,
                type: command.type,
                payload,
                progress: async (message) => {
                    await this.client.progress(command.id, message).catch(() => {});
                },
            });
            outcome = { status: 'succeeded', result };
        } catch (error) {
            const e = /** @type {Error & { code?: string }} */ (error);
            outcome = { status: 'failed', error: { code: e.code ?? 'RUNNER_ERROR', message: e.message.slice(0, 2000) } };
        }

        this.log(`${outcome.status === 'succeeded' ? '✓' : '✗'} ${command.type} ${command.id} (${Date.now() - started} ms)${outcome.error ? ` — ${outcome.error.message}` : ''}`);
        this.state.completed[command.id] = { ...outcome, at: new Date().toISOString() };
        saveState(this.state);

        await this.deliver(command.id, outcome);
    }

    /**
     * @param {string} id
     * @param {CommandOutcome} outcome
     */
    async deliver(id, outcome) {
        try {
            await this.client.complete(id, outcome);
        } catch (error) {
            if (error instanceof ApiError && error.status >= 400 && error.status < 500 && error.status !== 429) {
                this.log(`Studio refused the result for ${id}: ${error.message}`);

                return;
            }

            this.state.pending = [...this.state.pending.filter((p) => p.id !== id), { id, outcome }];
            saveState(this.state);
        }
    }

    async flushPending() {
        for (const item of [...this.state.pending]) {
            await this.client.complete(item.id, item.outcome);
            this.state.pending = this.state.pending.filter((p) => p.id !== item.id);
            saveState(this.state);
        }
    }

    get ctx() {
        return { allowedRoots: this.config.allowedRoots, workspacesRoot: this.config.workspacesRoot };
    }
}

/** @type {Record<string, (this: Runner, c: CommandContext) => Promise<unknown>>} */
const HANDLERS = {
    async 'project.scan'({ payload, progress }) {
        const folder = assertAllowedProjectPath(payload.project_path, this.config.allowedRoots);

        return scanProject(folder, { workspacesRoot: this.config.workspacesRoot, progress });
    },

    async 'project.index'({ id, payload, progress }) {
        const folder = assertAllowedProjectPath(payload.project_path, this.config.allowedRoots);
        const git = await gitState(folder, this.config.workspacesRoot);

        await progress('Listing files…');
        const paths = await listFiles(folder, { workspacesRoot: this.config.workspacesRoot, gitEnabled: git.enabled });

        let files = 0;
        let symbols = 0;
        /** @type {unknown[]} */
        let chunk = [];

        for (const rel of paths) {
            const entry = indexFile(folder, rel);
            if (!entry) continue;

            files++;
            symbols += entry.symbols.length;
            chunk.push(entry);

            if (chunk.length >= INDEX_CHUNK) {
                await this.client.indexChunk(id, chunk);
                chunk = [];
                await progress(`Indexed ${files.toLocaleString('en-US')} of ${paths.length.toLocaleString('en-US')} files…`);
            }
        }

        if (chunk.length) await this.client.indexChunk(id, chunk);

        return { files, symbols, listed: paths.length };
    },

    async 'git.init'({ payload, progress }) {
        await progress('Creating .gitignore and the baseline commit…');

        return initializeGit(/** @type {any} */ (payload), this.ctx);
    },

    async 'workspace.create'({ payload, progress }) {
        await progress('Checking repository, disk space and existing worktrees…');

        return createWorkspace(/** @type {any} */ (payload), this.ctx);
    },

    async 'workspace.checkpoint'({ payload }) {
        return checkpointWorkspace(/** @type {any} */ (payload), this.ctx);
    },

    async 'workspace.reset'({ payload }) {
        return resetWorkspace(/** @type {any} */ (payload), this.ctx);
    },

    async 'workspace.remove'({ payload }) {
        return removeWorkspace(/** @type {any} */ (payload), this.ctx);
    },
};

/** @param {number} ms */
function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

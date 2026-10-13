// @ts-check
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * @typedef {{
 *   server: string | null,
 *   runnerId: number | null,
 *   token: string | null,
 *   signingSecret: string | null,
 *   name: string,
 *   allowedRoots: string[],
 *   workspacesRoot: string,
 *   insecure: boolean,
 * }} RunnerConfig
 */

/** Where the runner keeps its config and state on this PC. */
export function configDir() {
    if (process.env.EAJ_RUNNER_HOME) return process.env.EAJ_RUNNER_HOME;
    if (process.platform === 'win32') return path.join(process.env.APPDATA ?? path.join(os.homedir(), 'AppData', 'Roaming'), 'EAJ', 'Runner');
    if (process.platform === 'darwin') return path.join(os.homedir(), 'Library', 'Application Support', 'EAJ Runner');

    return path.join(process.env.XDG_CONFIG_HOME ?? path.join(os.homedir(), '.config'), 'eaj-runner');
}

export function defaultWorkspacesRoot() {
    return process.platform === 'win32' ? 'C:\\EAJ\\workspaces' : path.join(os.homedir(), 'EAJ', 'workspaces');
}

/** @returns {RunnerConfig} */
export function loadConfig() {
    const file = path.join(configDir(), 'config.json');
    /** @type {Partial<RunnerConfig>} */
    let stored = {};

    try {
        stored = JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch {
        // First run: defaults below.
    }

    return {
        server: stored.server ?? null,
        runnerId: stored.runnerId ?? null,
        token: stored.token ?? null,
        signingSecret: stored.signingSecret ?? null,
        name: stored.name ?? os.hostname(),
        allowedRoots: stored.allowedRoots ?? [],
        workspacesRoot: stored.workspacesRoot ?? defaultWorkspacesRoot(),
        insecure: stored.insecure ?? false,
    };
}

/**
 * The file holds the runner token: readable by this OS user only.
 * @param {RunnerConfig} config
 */
export function saveConfig(config) {
    const dir = configDir();
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, 'config.json'), JSON.stringify(config, null, 2), { mode: 0o600 });
}

/**
 * Local state: results of recently finished commands (so a re-delivered
 * command is answered from here instead of running twice — guarantee G4) and
 * results that could not be posted yet (replayed on reconnect — Plan §89.2).
 *
 * @typedef {{ status: 'succeeded' | 'failed', result?: unknown, error?: { code: string, message: string } }} CommandOutcome
 * @typedef {{ completed: Record<string, CommandOutcome & { at: string }>, pending: { id: string, outcome: CommandOutcome }[] }} RunnerState
 */

/** @returns {RunnerState} */
export function loadState() {
    try {
        const state = JSON.parse(fs.readFileSync(path.join(configDir(), 'state.json'), 'utf8'));

        return { completed: state.completed ?? {}, pending: state.pending ?? [] };
    } catch {
        return { completed: {}, pending: [] };
    }
}

/** @param {RunnerState} state */
export function saveState(state) {
    const entries = Object.entries(state.completed).sort((a, b) => b[1].at.localeCompare(a[1].at)).slice(0, 200);
    const dir = configDir();
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, 'state.json'), JSON.stringify({ completed: Object.fromEntries(entries), pending: state.pending }, null, 2), { mode: 0o600 });
}

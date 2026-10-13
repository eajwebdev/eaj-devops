#!/usr/bin/env node
// @ts-check
import fs from 'node:fs';
import path from 'node:path';
import { ApiError, RUNNER_VERSION, StudioClient } from '../src/api.js';
import { configDir, loadConfig, saveConfig } from '../src/config.js';
import { gitState } from '../src/git-state.js';
import { indexFile, listFiles } from '../src/indexer.js';
import { isInside, normalize, RunnerError } from '../src/paths.js';
import { Runner } from '../src/runner.js';
import { scanProject } from '../src/scanner/scan.js';

const HELP = `EAJ Local Runner v${RUNNER_VERSION}

Usage:
  eaj-runner pair <studio-url> <code> [--name "My PC"] [--insecure]
  eaj-runner allow <folder>        Let the Studio scan projects inside this folder
  eaj-runner disallow <folder>
  eaj-runner roots                 Show allowed folders and the workspaces folder
  eaj-runner workspaces <folder>   Where task worktrees are created
  eaj-runner start [--once]        Connect to the Studio and process commands
  eaj-runner status
  eaj-runner scan <folder>         Scan locally and print the result (nothing is sent)

Only folders you allow on this PC can be read. The Studio cannot change this list.
Config: ${configDir()}`;

const [command, ...rest] = process.argv.slice(2);
const flags = new Set(rest.filter((a) => a.startsWith('--')));
const args = rest.filter((a, i) => !a.startsWith('--') && rest[i - 1] !== '--name');
const nameIndex = rest.indexOf('--name');
const name = nameIndex >= 0 ? rest[nameIndex + 1] : undefined;

try {
    await main();
} catch (error) {
    const e = /** @type {Error & { code?: string }} */ (error);
    console.error(e instanceof RunnerError || e instanceof ApiError ? `✗ ${e.message}` : `✗ ${e.stack ?? e.message}`);
    process.exitCode = 1;
}

async function main() {
    const config = loadConfig();

    switch (command) {
        case 'pair': {
            const [server, code] = args;
            if (!server || !code) throw new RunnerError('USAGE', 'Usage: eaj-runner pair <studio-url> <code>');

            const insecure = flags.has('--insecure');
            const result = await StudioClient.pair(server, code.trim().toUpperCase(), name ?? config.name, insecure);
            saveConfig({ ...config, server: new URL(server).origin, runnerId: result.runner_id, token: result.token, signingSecret: result.signing_secret, name: name ?? config.name, insecure });

            console.log(`✓ Paired with ${result.organization} as "${name ?? config.name}".`);
            if (config.allowedRoots.length === 0) console.log('Next: eaj-runner allow <folder containing your projects>, then eaj-runner start');
            break;
        }

        case 'allow': {
            const folder = requireFolder(args[0]);
            if (isInside(config.workspacesRoot, folder) || isInside(folder, config.workspacesRoot)) {
                throw new RunnerError('INVALID_ROOT', 'Allowed project folders must not overlap the workspaces folder.');
            }
            const roots = [...config.allowedRoots.filter((r) => normalize(r) !== normalize(folder)), folder];
            saveConfig({ ...config, allowedRoots: roots });
            console.log(`✓ Allowed: ${folder}`);
            break;
        }

        case 'disallow': {
            const folder = path.resolve(args[0] ?? '');
            saveConfig({ ...config, allowedRoots: config.allowedRoots.filter((r) => normalize(r) !== normalize(folder)) });
            console.log(`✓ Removed: ${folder}`);
            break;
        }

        case 'workspaces': {
            if (!args[0]) {
                console.log(config.workspacesRoot);
                break;
            }
            const folder = path.resolve(args[0]);
            if (config.allowedRoots.some((r) => isInside(r, folder) || isInside(folder, r))) {
                throw new RunnerError('INVALID_ROOT', 'The workspaces folder must not overlap an allowed project folder.');
            }
            fs.mkdirSync(folder, { recursive: true });
            saveConfig({ ...config, workspacesRoot: folder });
            console.log(`✓ Workspaces: ${folder}`);
            break;
        }

        case 'roots':
            console.log(`Allowed project folders:\n${config.allowedRoots.map((r) => `  ${r}`).join('\n') || '  (none — add one with eaj-runner allow <folder>)'}`);
            console.log(`Workspaces folder:\n  ${config.workspacesRoot}`);
            break;

        case 'status':
            console.log(config.token ? `Paired with ${config.server} as "${config.name}" (runner #${config.runnerId}).` : 'Not paired.');
            console.log(`Allowed folders: ${config.allowedRoots.length}. Workspaces: ${config.workspacesRoot}`);
            break;

        case 'start': {
            if (config.allowedRoots.length === 0) console.log('! No folders are allowed yet — scans will be refused. Add one with: eaj-runner allow <folder>');
            fs.mkdirSync(config.workspacesRoot, { recursive: true });

            const runner = new Runner(config, { log: (line) => console.log(`[${new Date().toLocaleTimeString()}] ${line}`) });
            process.on('SIGINT', () => runner.stop());
            process.on('SIGTERM', () => runner.stop());
            console.log(`EAJ Local Runner v${RUNNER_VERSION} connected to ${config.server}. Ctrl+C to stop.`);
            await runner.start({ once: flags.has('--once') });
            break;
        }

        case 'scan': {
            const folder = requireFolder(args[0]);
            const result = await scanProject(folder, { workspacesRoot: config.workspacesRoot });
            if (flags.has('--index')) {
                const git = await gitState(folder, config.workspacesRoot);
                const files = await listFiles(folder, { workspacesRoot: config.workspacesRoot, gitEnabled: git.enabled });
                const indexed = files.map((f) => indexFile(folder, f)).filter((f) => f !== null);
                /** @type {Record<string, number>} */
                const kinds = {};
                for (const f of indexed) for (const s of f.symbols) kinds[s.kind] = (kinds[s.kind] ?? 0) + 1;
                console.log(JSON.stringify({ ...result, index: { files: indexed.length, kinds } }, null, 2));
            } else {
                console.log(JSON.stringify(result, null, 2));
            }
            break;
        }

        default:
            console.log(HELP);
    }
}

/** @param {string | undefined} folder */
function requireFolder(folder) {
    if (!folder) throw new RunnerError('USAGE', 'A folder is required.');

    const resolved = path.resolve(folder);
    if (!fs.existsSync(resolved) || !fs.statSync(resolved).isDirectory()) throw new RunnerError('PATH_NOT_FOUND', `Folder not found: ${resolved}`);

    return resolved;
}

// @ts-check
import fs from 'node:fs';
import path from 'node:path';
import { git } from './exec.js';
import { redactRemoteUrl } from './redact.js';

/**
 * @typedef {{
 *   enabled: boolean,
 *   root: string | null,
 *   branch: string | null,
 *   head: string | null,
 *   hasCommits: boolean,
 *   modified: number,
 *   untracked: number,
 *   remotes: { name: string, url: string }[],
 *   worktrees: number,
 *   envTracked: boolean,
 * }} GitState
 */

/**
 * Read-only Git inspection of a project folder.
 *
 * @param {string} folder
 * @param {string} workspacesRoot
 * @returns {Promise<GitState>}
 */
export async function gitState(folder, workspacesRoot) {
    const opts = { cwd: folder, workspacesRoot, allowFailure: true };
    const top = await git(['rev-parse', '--show-toplevel'], opts).catch(() => null);

    if (!top || top.code !== 0) {
        return { enabled: false, root: null, branch: null, head: null, hasCommits: false, modified: 0, untracked: 0, remotes: [], worktrees: 0, envTracked: false };
    }

    const root = path.resolve(top.stdout.trim());
    const head = await git(['rev-parse', '--verify', '--quiet', 'HEAD'], opts);
    const branch = await git(['symbolic-ref', '--short', '-q', 'HEAD'], opts);
    const status = await git(['status', '--porcelain=v1', '--untracked-files=normal'], opts);
    const remotes = await git(['remote', '-v'], opts);
    const worktrees = await git(['worktree', 'list', '--porcelain'], opts);
    const envTracked = await git(['ls-files', '--error-unmatch', '.env'], opts);

    const lines = status.stdout.split(/\r?\n/).filter(Boolean);

    /** @type {Map<string, string>} */
    const remoteMap = new Map();
    for (const line of remotes.stdout.split(/\r?\n/)) {
        const [name, url] = line.split(/\s+/);
        if (name && url) remoteMap.set(name, redactRemoteUrl(url));
    }

    return {
        enabled: true,
        root,
        branch: branch.stdout.trim() || null,
        head: head.code === 0 ? head.stdout.trim() : null,
        hasCommits: head.code === 0,
        modified: lines.filter((l) => !l.startsWith('??')).length,
        untracked: lines.filter((l) => l.startsWith('??')).length,
        remotes: [...remoteMap].map(([name, url]) => ({ name, url })),
        worktrees: Math.max(0, worktrees.stdout.split(/\r?\n/).filter((l) => l.startsWith('worktree ')).length - 1),
        envTracked: envTracked.code === 0 && fs.existsSync(path.join(folder, '.env')),
    };
}

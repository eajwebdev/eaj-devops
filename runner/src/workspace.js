// @ts-check
import fs from 'node:fs';
import path from 'node:path';
import { git } from './exec.js';
import { assertAllowedProjectPath, assertInsideWorkspaces, isInside, RunnerError, samePath } from './paths.js';

/**
 * Task workspaces (Plan §13, §48.4). Agents only ever write inside these
 * worktrees; the original project folder is never modified (guarantee G1).
 */

export const TASK_KEY = /^[A-Z][A-Z0-9]{1,9}-\d{1,7}$/;
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const SHA = /^[0-9a-f]{7,64}$/;
const REF = /^(?!.*\.\.)(?!\/)(?!.*\/$)[\w./-]{1,200}$/;

/** Identity for commits the runner makes itself; never borrowed from the server. */
const IDENTITY = ['-c', 'user.name=EAJ AI Dev Studio', '-c', 'user.email=runner@eaj.local'];

/**
 * @typedef {{ allowedRoots: string[], workspacesRoot: string }} Ctx
 */

/**
 * @param {{ project_path: string, task_key: string, slug: string, base_branch?: string | null }} payload
 * @param {Ctx} ctx
 */
export async function createWorkspace(payload, ctx) {
    const project = assertAllowedProjectPath(payload.project_path, ctx.allowedRoots);

    if (!TASK_KEY.test(payload.task_key ?? '')) throw new RunnerError('INVALID_INPUT', 'Invalid task key.');
    if (!SLUG.test(payload.slug ?? '') || payload.slug.length > 40) throw new RunnerError('INVALID_INPUT', 'Invalid branch slug.');
    if (payload.base_branch && !REF.test(payload.base_branch)) throw new RunnerError('INVALID_INPUT', 'Invalid base branch name.');

    const opts = { cwd: project, workspacesRoot: ctx.workspacesRoot };

    if ((await git(['rev-parse', '--show-toplevel'], { ...opts, allowFailure: true })).code !== 0) {
        throw new RunnerError('NOT_A_GIT_REPO', 'The project is not a Git repository. Initialize Git from the Studio first.');
    }

    const head = await git(['rev-parse', '--verify', '--quiet', 'HEAD'], { ...opts, allowFailure: true });
    if (head.code !== 0) throw new RunnerError('NO_COMMITS', 'The repository has no commits yet. Create a baseline commit first.');

    const base = payload.base_branch || (await git(['symbolic-ref', '--short', '-q', 'HEAD'], { ...opts, allowFailure: true })).stdout.trim() || 'HEAD';
    const baseSha = await git(['rev-parse', '--verify', '--quiet', `${base}^{commit}`], { ...opts, allowFailure: true });
    if (baseSha.code !== 0) throw new RunnerError('BASE_NOT_FOUND', `Base branch "${base}" does not exist.`);

    const branch = `ai/${payload.task_key}-${payload.slug}`;
    const branchOk = await git(['check-ref-format', '--branch', branch], { ...opts, allowFailure: true });
    if (branchOk.code !== 0) throw new RunnerError('INVALID_INPUT', `"${branch}" is not a valid branch name.`);

    // §13 check 3 — no existing worktree or branch for this task.
    const workspace = workspacePath(ctx.workspacesRoot, project, payload.task_key);
    if (fs.existsSync(workspace)) throw new RunnerError('WORKSPACE_EXISTS', `A workspace already exists at ${workspace}.`);
    if ((await git(['show-ref', '--verify', '--quiet', `refs/heads/${branch}`], { ...opts, allowFailure: true })).code === 0) {
        throw new RunnerError('BRANCH_EXISTS', `Branch ${branch} already exists.`);
    }

    // §13 check 1 — uncommitted work in the original folder is snapshotted into a named
    // safety stash WITHOUT touching the working tree ("stash create" + "stash store").
    let safetyStash = null;
    const stash = await git(['stash', 'create', `eaj-safety: ${payload.task_key} before workspace`], opts);
    if (stash.stdout.trim()) {
        safetyStash = stash.stdout.trim();
        await git(['stash', 'store', '-m', `eaj-safety: ${payload.task_key} before workspace`, safetyStash], opts);
    }

    // §13 check 2 — free disk space at least twice the repository size.
    const repoBytes = await trackedSize(project, ctx.workspacesRoot);
    const free = freeBytes(ctx.workspacesRoot);
    if (free !== null && free < repoBytes * 2) {
        throw new RunnerError('LOW_DISK_SPACE', `Not enough free disk space: ${mb(free)} free, ${mb(repoBytes * 2)} needed.`);
    }

    fs.mkdirSync(path.dirname(workspace), { recursive: true });
    await git(['worktree', 'add', '-b', branch, workspace, baseSha.stdout.trim()], { ...opts, timeoutMs: 600_000 });

    return {
        path: workspace,
        branch,
        base_branch: base,
        base_sha: baseSha.stdout.trim(),
        safety_stash: safetyStash,
        repo_bytes: repoBytes,
    };
}

/**
 * Commit everything in the workspace as a checkpoint (Plan §48.4).
 * @param {{ workspace_path: string, task_key: string, step: number }} payload
 * @param {Ctx} ctx
 */
export async function checkpointWorkspace(payload, ctx) {
    const workspace = await assertWorktree(payload.workspace_path, ctx);

    if (!TASK_KEY.test(payload.task_key ?? '') || !Number.isInteger(payload.step) || payload.step < 0) {
        throw new RunnerError('INVALID_INPUT', 'Invalid task key or step.');
    }

    const opts = { cwd: workspace, workspacesRoot: ctx.workspacesRoot };
    await git(['add', '-A'], opts);

    const changed = (await git(['diff', '--cached', '--name-only', '-z'], opts)).stdout.split('\0').filter(Boolean);

    if (changed.length > 0) {
        // --no-verify: a project's own hooks never run as a side effect of checkpointing.
        await git([...IDENTITY, 'commit', '--no-verify', '-q', '-m', `eaj-checkpoint: ${payload.task_key} step ${payload.step}`], opts);
    }

    const sha = (await git(['rev-parse', 'HEAD'], opts)).stdout.trim();

    return { sha, changed: changed.length > 0, files: changed.slice(0, 500) };
}

/**
 * Publish an approved ai/* worktree branch using credentials configured on the
 * developer machine. No GitHub token is accepted in the command payload.
 * @param {{ workspace_path: string, branch: string, repository_full_name: string }} payload
 * @param {Ctx} ctx
 */
export async function pushWorkspace(payload, ctx) {
    const workspace = await assertWorktree(payload.workspace_path, ctx);
    const branch = String(payload.branch ?? '');
    const repository = String(payload.repository_full_name ?? '');
    if (!/^ai\/[A-Z][A-Z0-9]{1,9}-\d{1,7}-[a-z0-9]+(?:-[a-z0-9]+)*$/.test(branch)) {
        throw new RunnerError('INVALID_INPUT', 'Only an ai/<task>-<slug> branch can be published.');
    }
    if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository)) {
        throw new RunnerError('INVALID_INPUT', 'Invalid GitHub repository name.');
    }

    const opts = { cwd: workspace, workspacesRoot: ctx.workspacesRoot };
    const current = (await git(['branch', '--show-current'], opts)).stdout.trim();
    if (current !== branch) throw new RunnerError('BRANCH_MISMATCH', `Workspace is on ${current || 'detached HEAD'}, not ${branch}.`);

    const remote = (await git(['remote', 'get-url', 'origin'], opts)).stdout.trim();
    if (githubRepository(remote) !== repository.toLowerCase()) {
        throw new RunnerError('REMOTE_MISMATCH', 'The origin remote does not match the repository attached in the Studio.');
    }

    await git(['push', '--set-upstream', 'origin', `${branch}:${branch}`], { ...opts, timeoutMs: 600_000 });

    return { sha: (await git(['rev-parse', 'HEAD'], opts)).stdout.trim(), branch };
}

/** @param {string} url */
function githubRepository(url) {
    const cleaned = url.replace(/\?.*$/, '').replace(/\.git$/, '');
    const match = cleaned.match(/^(?:https?:\/\/(?:[^@/]+@)?github\.com\/|ssh:\/\/git@github\.com\/|git@github\.com:)([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)$/i);

    return match?.[1]?.toLowerCase() ?? null;
}

/**
 * Crash recovery: reset the workspace to a checkpoint (Plan §48.4). Only ever
 * inside a task worktree — the policy refuses it anywhere else.
 * @param {{ workspace_path: string, sha: string }} payload
 * @param {Ctx} ctx
 */
export async function resetWorkspace(payload, ctx) {
    const workspace = await assertWorktree(payload.workspace_path, ctx);

    if (!SHA.test(payload.sha ?? '')) throw new RunnerError('INVALID_INPUT', 'Invalid checkpoint SHA.');

    const opts = { cwd: workspace, workspacesRoot: ctx.workspacesRoot };
    await git(['reset', '--hard', '-q', payload.sha], opts);
    await git(['clean', '-fd', '-q'], opts);

    return { head: (await git(['rev-parse', 'HEAD'], opts)).stdout.trim() };
}

/**
 * Remove a task worktree. The branch is kept so the work can still be recovered.
 * @param {{ workspace_path: string, project_path: string, force?: boolean }} payload
 * @param {Ctx} ctx
 */
export async function removeWorkspace(payload, ctx) {
    const project = assertAllowedProjectPath(payload.project_path, ctx.allowedRoots);
    const workspace = assertInsideWorkspaces(payload.workspace_path, ctx.workspacesRoot);
    const opts = { cwd: project, workspacesRoot: ctx.workspacesRoot };

    if (!fs.existsSync(workspace)) {
        await git(['worktree', 'prune'], opts);

        return { removed: false, reason: 'already_gone' };
    }

    const result = await git(['worktree', 'remove', ...(payload.force ? ['--force'] : []), workspace], { ...opts, allowFailure: true });

    if (result.code !== 0) {
        throw new RunnerError(
            /contains modified or untracked files/.test(result.stderr) ? 'WORKSPACE_DIRTY' : 'COMMAND_FAILED',
            /contains modified or untracked files/.test(result.stderr)
                ? 'The workspace has uncommitted changes. Checkpoint them first, or approve a forced removal.'
                : result.stderr.trim(),
        );
    }

    await git(['worktree', 'prune'], opts);

    return { removed: true };
}

/**
 * @param {string} workspacesRoot
 * @param {string} project
 * @param {string} taskKey
 */
export function workspacePath(workspacesRoot, project, taskKey) {
    const projectSlug = path.basename(project).toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'project';

    return path.join(workspacesRoot, projectSlug, taskKey);
}

/**
 * @param {string} p
 * @param {Ctx} ctx
 */
export async function assertWorktree(p, ctx) {
    const workspace = assertInsideWorkspaces(p, ctx.workspacesRoot);
    const top = await git(['rev-parse', '--show-toplevel'], { cwd: workspace, workspacesRoot: ctx.workspacesRoot, allowFailure: true }).catch(() => null);

    // Git reports the long real path; the config may use an alias (e.g. C:\Users\ADMINI~1) — compare real paths.
    if (!top || top.code !== 0 || !samePath(top.stdout.trim(), workspace) || !isInside(ctx.workspacesRoot, top.stdout.trim())) {
        throw new RunnerError('NOT_A_WORKSPACE', `${workspace} is not a task workspace.`);
    }

    return workspace;
}

/**
 * @param {string} project
 * @param {string} workspacesRoot
 */
async function trackedSize(project, workspacesRoot) {
    const files = (await git(['ls-files', '-z'], { cwd: project, workspacesRoot })).stdout.split('\0').filter(Boolean);
    let total = 0;

    for (const f of files) {
        try {
            total += fs.statSync(path.join(project, f)).size;
        } catch {
            // Deleted in the working tree: not part of the checkout size.
        }
    }

    return total;
}

/**
 * Free space on the volume holding `dir` (or its nearest existing ancestor).
 * @param {string} dir
 * @returns {number | null}
 */
function freeBytes(dir) {
    let probe = path.resolve(dir);

    while (!fs.existsSync(probe) && path.dirname(probe) !== probe) probe = path.dirname(probe);

    try {
        const s = fs.statfsSync(probe);

        return s.bavail * s.bsize;
    } catch {
        return null;
    }
}

/** @param {number} bytes */
function mb(bytes) {
    return `${Math.round(bytes / 1024 / 1024)} MB`;
}

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, describe, test } from 'node:test';
import { initializeGit } from '../src/git-init.js';
import { checkpointWorkspace, createWorkspace, removeWorkspace, resetWorkspace } from '../src/workspace.js';
import { cleanup, gitSync, makeRepo, tempDir, writeFiles } from './helpers.js';

const root = tempDir();
const projects = path.join(root, 'www');
const workspacesRoot = path.join(root, 'EAJ', 'workspaces');
const ctx = { allowedRoots: [projects], workspacesRoot };
after(() => cleanup(root));

describe('task workspaces (Plan §13, §48.4)', () => {
    const project = path.join(projects, 'booking-saas');
    makeRepo(project, { 'app/Booking.php': '<?php class Booking {}', 'README.md': 'hi' });

    // Uncommitted work in the original folder must survive untouched.
    fs.writeFileSync(path.join(project, 'README.md'), 'local edit in progress');

    /** @type {Awaited<ReturnType<typeof createWorkspace>>} */
    let ws;

    test('creates an isolated worktree on ai/<task>-<slug> without touching the original', async () => {
        ws = await createWorkspace({ project_path: project, task_key: 'BOOK-101', slug: 'revenue-dashboard' }, ctx);

        assert.equal(ws.path, path.join(workspacesRoot, 'booking-saas', 'BOOK-101'));
        assert.equal(ws.branch, 'ai/BOOK-101-revenue-dashboard');
        assert.equal(ws.base_branch, 'main');
        assert.match(ws.base_sha, /^[0-9a-f]{40}$/);
        assert.equal(fs.readFileSync(path.join(ws.path, 'README.md'), 'utf8'), 'hi', 'workspace starts from the last commit');
        assert.equal(fs.readFileSync(path.join(project, 'README.md'), 'utf8'), 'local edit in progress', 'original working tree untouched');
        assert.ok(ws.safety_stash, 'dirty original is snapshotted into a safety stash');
        assert.match(gitSync(project, ['stash', 'list']), /eaj-safety: BOOK-101/);
    });

    test('refuses a second workspace for the same task', async () => {
        await assert.rejects(createWorkspace({ project_path: project, task_key: 'BOOK-101', slug: 'again' }, ctx), { code: 'WORKSPACE_EXISTS' });
    });

    test('refuses invalid task keys, slugs and base branches', async () => {
        await assert.rejects(createWorkspace({ project_path: project, task_key: 'book-1', slug: 'x' }, ctx), { code: 'INVALID_INPUT' });
        await assert.rejects(createWorkspace({ project_path: project, task_key: 'BOOK-2', slug: 'x; rm -rf' }, ctx), { code: 'INVALID_INPUT' });
        await assert.rejects(createWorkspace({ project_path: project, task_key: 'BOOK-2', slug: 'x', base_branch: '../../etc' }, ctx), { code: 'INVALID_INPUT' });
        await assert.rejects(createWorkspace({ project_path: project, task_key: 'BOOK-2', slug: 'x', base_branch: 'nope' }, ctx), { code: 'BASE_NOT_FOUND' });
    });

    test('checkpoints commit the workspace with an eaj-checkpoint message', async () => {
        writeFiles(ws.path, { 'app/Revenue.php': '<?php class Revenue {}' });

        const cp = await checkpointWorkspace({ workspace_path: ws.path, task_key: 'BOOK-101', step: 1 }, ctx);
        assert.equal(cp.changed, true);
        assert.deepEqual(cp.files, ['app/Revenue.php']);
        assert.match(gitSync(ws.path, ['log', '-1', '--format=%s']), /^eaj-checkpoint: BOOK-101 step 1$/);

        const noop = await checkpointWorkspace({ workspace_path: ws.path, task_key: 'BOOK-101', step: 2 }, ctx);
        assert.equal(noop.changed, false);
        assert.equal(noop.sha, cp.sha);

        // The original project branch is unaffected.
        assert.equal(gitSync(project, ['log', '-1', '--format=%s', 'main']), 'initial');
    });

    test('reset returns the workspace to a checkpoint (crash recovery)', async () => {
        const cp = gitSync(ws.path, ['rev-parse', 'HEAD']);
        writeFiles(ws.path, { 'app/Revenue.php': 'half-written', 'scratch.txt': 'junk' });

        const result = await resetWorkspace({ workspace_path: ws.path, sha: cp }, ctx);

        assert.equal(result.head, cp);
        assert.equal(fs.readFileSync(path.join(ws.path, 'app/Revenue.php'), 'utf8'), '<?php class Revenue {}');
        assert.equal(fs.existsSync(path.join(ws.path, 'scratch.txt')), false);
    });

    test('reset is refused anywhere but a task workspace', async () => {
        await assert.rejects(resetWorkspace({ workspace_path: project, sha: 'abcdef1' }, ctx), { code: 'PATH_NOT_ALLOWED' });
    });

    test('a dirty workspace is not removed without approval; the branch is always kept', async () => {
        writeFiles(ws.path, { 'unsaved.txt': 'x' });
        await assert.rejects(removeWorkspace({ workspace_path: ws.path, project_path: project }, ctx), { code: 'WORKSPACE_DIRTY' });

        const removed = await removeWorkspace({ workspace_path: ws.path, project_path: project, force: true }, ctx);
        assert.equal(removed.removed, true);
        assert.equal(fs.existsSync(ws.path), false);
        assert.match(gitSync(project, ['branch', '--list', 'ai/*']), /ai\/BOOK-101-revenue-dashboard/);
    });

    test('a repository without commits is refused', async () => {
        const empty = path.join(projects, 'empty');
        fs.mkdirSync(empty, { recursive: true });
        gitSync(empty, ['init', '-q']);

        await assert.rejects(createWorkspace({ project_path: empty, task_key: 'EMP-1', slug: 'x' }, ctx), { code: 'NO_COMMITS' });
    });
});

describe('initializing Git for a non-Git project (Plan §14)', () => {
    test('creates .gitignore and a baseline commit that never contains secrets', async () => {
        const project = path.join(projects, 'legacy-pos');
        writeFiles(project, { 'composer.json': '{}', 'index.php': '<?php', '.env': 'DB_PASSWORD=x', 'storage/oauth-private.key': 'KEY' });

        const result = await initializeGit({ project_path: project }, ctx);

        assert.equal(result.branch, 'main');
        assert.equal(result.gitignore_created, true);
        const tracked = gitSync(project, ['ls-files']).split('\n');
        assert.ok(tracked.includes('index.php') && tracked.includes('.gitignore'));
        assert.ok(!tracked.includes('.env') && !tracked.some((f) => f.endsWith('.key')));
        assert.match(gitSync(project, ['log', '-1', '--format=%s']), /EAJ baseline/);
    });

    test('an existing .gitignore is kept; secrets it misses are excluded and appended', async () => {
        const project = path.join(projects, 'old-site');
        writeFiles(project, { '.gitignore': 'vendor/\n', '.env': 'X=1', 'index.php': '<?php' });

        const result = await initializeGit({ project_path: project }, ctx);

        assert.equal(result.gitignore_created, false);
        assert.deepEqual(result.secrets_excluded, ['.env']);
        const gitignore = fs.readFileSync(path.join(project, '.gitignore'), 'utf8');
        assert.ok(gitignore.startsWith('vendor/\n'));
        assert.match(gitignore, /\/\.env/);
        assert.ok(!gitSync(project, ['ls-files']).split('\n').includes('.env'));
    });

    test('refuses a folder that is already a repository', async () => {
        await assert.rejects(initializeGit({ project_path: path.join(projects, 'booking-saas') }, ctx), { code: 'ALREADY_GIT' });
    });
});

describe('workspaces folder reached through an alias (8.3 short name, junction)', () => {
    // Regression: Git reports the real long path; the runner config may hold an alias
    // such as C:\Users\ADMINI~1\... — checkpointing must still recognise the worktree.
    test('checkpoint, reset and remove work when the configured root is an alias', async () => {
        const aliasRoot = path.join(root, 'ws-alias');
        const realRoot = path.join(root, 'ws-real');
        fs.mkdirSync(realRoot, { recursive: true });
        fs.symlinkSync(realRoot, aliasRoot, 'junction');

        const aliasCtx = { allowedRoots: [projects], workspacesRoot: aliasRoot };
        const project = path.join(projects, 'alias-shop');
        makeRepo(project, { 'index.php': '<?php' });

        const ws = await createWorkspace({ project_path: project, task_key: 'ALI-1', slug: 'x' }, aliasCtx);
        writeFiles(ws.path, { 'new.php': '<?php' });

        const cp = await checkpointWorkspace({ workspace_path: ws.path, task_key: 'ALI-1', step: 1 }, aliasCtx);
        assert.equal(cp.changed, true);

        await resetWorkspace({ workspace_path: ws.path, sha: cp.sha }, aliasCtx);
        assert.equal((await removeWorkspace({ workspace_path: ws.path, project_path: project }, aliasCtx)).removed, true);
    });
});

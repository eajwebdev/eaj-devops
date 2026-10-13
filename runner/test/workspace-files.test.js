import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, describe, test } from 'node:test';
import { createWorkspace } from '../src/workspace.js';
import { applyWorkspaceChanges, readWorkspaceFiles, workspaceDiff } from '../src/workspace-files.js';
import { cleanup, makeRepo, tempDir } from './helpers.js';

const root = tempDir();
after(() => cleanup(root));

describe('agent file access in a task workspace (Plan §13, §16, §48.8)', () => {
    const projects = path.join(root, 'www');
    const workspacesRoot = path.join(root, 'EAJ', 'workspaces');
    const ctx = { allowedRoots: [projects], workspacesRoot };
    const project = path.join(projects, 'shop');

    makeRepo(project, {
        'app/Cart.php': '<?php\nclass Cart\n{\n    public function total(): int\n    {\n        return 1;\n    }\n}\n',
        'app/Windows.php': '<?php\r\n// written on Windows\r\n',
        'config/services.php': "<?php\nreturn ['stripe' => 'sk_" + "live_abcdefghijklmnopqrstuvwx'];\n",
        '.env.example': 'APP_NAME=Shop\n',
        'logo.png': Buffer.from([0x89, 0x50, 0, 0, 1, 2]).toString('latin1'),
    });

    /** @type {string} */
    let ws;
    /** @type {string} */
    let base;

    test('setup', async () => {
        const created = await createWorkspace({ project_path: project, task_key: 'SHOP-9', slug: 'cart' }, ctx);
        ws = created.path;
        base = created.base_sha;
        fs.writeFileSync(path.join(ws, '.env'), 'DB_PASSWORD=hunter2\n');
    });

    test('reads redacted contents with hashes; secrets, binaries and escapes are refused', async () => {
        const { files, head_sha } = await readWorkspaceFiles({ workspace_path: ws, paths: ['app/Cart.php', 'config/services.php', '.env', 'logo.png', 'missing.php'] }, ctx);
        const byPath = Object.fromEntries(files.map((f) => [f.path, f]));

        assert.equal(head_sha, base);
        assert.equal(byPath['app/Cart.php'].status, 'ok');
        assert.match(byPath['app/Cart.php'].sha1, /^[0-9a-f]{40}$/);
        assert.equal(byPath['config/services.php'].redacted, true);
        assert.doesNotMatch(byPath['config/services.php'].content, /sk_live_/);
        assert.equal(byPath['.env'].status, 'secret');
        assert.equal(byPath['.env'].content, undefined);
        assert.equal(byPath['logo.png'].status, 'binary');
        assert.equal(byPath['missing.php'].status, 'missing');

        for (const bad of ['../shop/app/Cart.php', 'C:/Windows/win.ini', '/etc/passwd', '--output=x']) {
            await assert.rejects(readWorkspaceFiles({ workspace_path: ws, paths: [bad] }, ctx), { code: 'INVALID_PATH' }, bad);
        }
        await assert.rejects(readWorkspaceFiles({ workspace_path: project, paths: ['app/Cart.php'] }, ctx), { code: 'PATH_NOT_ALLOWED' });
    });

    test('writes only files that are exactly as the agent read them, all or nothing', async () => {
        const { files } = await readWorkspaceFiles({ workspace_path: ws, paths: ['app/Cart.php', 'app/Windows.php'] }, ctx);
        const [cart, win] = files;

        const stale = applyWorkspaceChanges(
            {
                workspace_path: ws,
                changes: [
                    { path: 'app/Cart.php', action: 'write', base_sha1: cart.sha1, content: 'changed' },
                    { path: 'app/Windows.php', action: 'write', base_sha1: '0'.repeat(40), content: 'x' },
                ],
            },
            ctx,
        );
        await assert.rejects(stale, { code: 'BASE_CHANGED' });
        assert.match(fs.readFileSync(path.join(ws, 'app/Cart.php'), 'utf8'), /return 1;/, 'nothing was written');

        const result = await applyWorkspaceChanges(
            {
                workspace_path: ws,
                changes: [
                    { path: 'app/Cart.php', action: 'write', base_sha1: cart.sha1, content: cart.content.replace('return 1;', 'return 2;') },
                    { path: 'app/Windows.php', action: 'write', base_sha1: win.sha1, content: '<?php\n// edited\n' },
                    { path: 'tests/CartTest.php', action: 'write', base_sha1: null, content: '<?php\n// new test\n' },
                    { path: 'logo.png', action: 'delete', base_sha1: (await readWorkspaceFiles({ workspace_path: ws, paths: ['logo.png'] }, ctx)).files[0].sha1 ?? null },
                ],
            },
            ctx,
        );

        assert.equal(result.applied, 4);
        assert.deepEqual(result.files.map((f) => f.path).sort(), ['app/Cart.php', 'app/Windows.php', 'logo.png', 'tests/CartTest.php']);
        assert.match(result.diff, /-\s+return 1;\n\+\s+return 2;/);
        assert.equal(fs.readFileSync(path.join(ws, 'app/Windows.php'), 'utf8'), '<?php\r\n// edited\r\n', 'Windows line endings are kept');
        assert.equal(fs.existsSync(path.join(ws, 'logo.png')), false);
        assert.match(fs.readFileSync(path.join(project, 'app/Cart.php'), 'utf8'), /return 1;/, 'the original project is untouched');
    });

    test('never writes secrets, protected folders, redaction markers or files holding credentials', async () => {
        const services = (await readWorkspaceFiles({ workspace_path: ws, paths: ['config/services.php'] }, ctx)).files[0];
        const cases = [
            [{ path: '.env', action: 'write', base_sha1: null, content: 'X=1' }, 'SECRET_FILE'],
            [{ path: 'vendor/autoload.php', action: 'write', base_sha1: null, content: '<?php' }, 'PATH_NOT_ALLOWED'],
            [{ path: '.git/hooks/pre-commit', action: 'write', base_sha1: null, content: 'rm -rf /' }, 'PATH_NOT_ALLOWED'],
            [{ path: 'config/services.php', action: 'write', base_sha1: services.sha1, content: services.content }, 'REDACTION_MARKER'],
            [{ path: 'config/services.php', action: 'write', base_sha1: services.sha1, content: '<?php return [];' }, 'SECRET_CONTENT'],
        ];

        for (const [change, code] of cases) {
            await assert.rejects(applyWorkspaceChanges({ workspace_path: ws, changes: [/** @type {any} */ (change)] }, ctx), { code }, JSON.stringify(change).slice(0, 60));
        }
    });

    test('the task diff since its base covers every change', async () => {
        const result = await workspaceDiff({ workspace_path: ws, base_sha: base }, ctx);

        assert.equal(result.base_sha, base);
        assert.deepEqual(result.files.map((f) => f.path).sort(), ['app/Cart.php', 'app/Windows.php', 'logo.png', 'tests/CartTest.php']);
        assert.equal(result.diff_truncated, false);
    });
});

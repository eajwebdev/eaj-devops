import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, describe, test } from 'node:test';
import { assertSafeServer, verifyCommand } from '../src/api.js';
import crypto from 'node:crypto';
import { assertAllowedProjectPath, assertInsideWorkspaces, isInside } from '../src/paths.js';
import { assertGitAllowed, isDangerous } from '../src/policy.js';
import { envShape, isSecretFile, redactRemoteUrl, redactText } from '../src/redact.js';
import { cleanup, tempDir } from './helpers.js';

const root = tempDir();
const projects = path.join(root, 'projects');
const workspaces = path.join(root, 'workspaces');
const outside = path.join(root, 'personal');
fs.mkdirSync(path.join(projects, 'shop'), { recursive: true });
fs.mkdirSync(workspaces, { recursive: true });
fs.mkdirSync(outside, { recursive: true });
after(() => cleanup(root));

describe('allowed folders (Plan §13, §17)', () => {
    test('accepts projects inside an allowed root', () => {
        assert.equal(assertAllowedProjectPath(path.join(projects, 'shop'), [projects]), path.join(projects, 'shop'));
    });

    test('refuses folders outside the allowed roots, with the fix in the message', () => {
        assert.throws(() => assertAllowedProjectPath(outside, [projects]), { code: 'PATH_NOT_ALLOWED', message: /eaj-runner allow/ });
    });

    test('refuses ".." traversal and relative paths', () => {
        assert.throws(() => assertAllowedProjectPath(path.join(projects, '..', 'personal'), [projects]), { code: 'PATH_NOT_ALLOWED' });
        assert.throws(() => assertAllowedProjectPath('projects/shop', [projects]), { code: 'INVALID_PATH' });
    });

    test('a junction/symlink inside an allowed root cannot escape it', () => {
        const link = path.join(projects, 'sneaky');
        fs.symlinkSync(outside, link, 'junction');

        assert.equal(isInside(projects, link), false);
        assert.throws(() => assertAllowedProjectPath(link, [projects]), { code: 'PATH_NOT_ALLOWED' });
    });

    test('workspace operations stay inside the workspaces folder, never on the root itself', () => {
        assert.throws(() => assertInsideWorkspaces(workspaces, workspaces), { code: 'PATH_NOT_ALLOWED' });
        assert.throws(() => assertInsideWorkspaces(path.join(projects, 'shop'), workspaces), { code: 'PATH_NOT_ALLOWED' });
        assert.ok(assertInsideWorkspaces(path.join(workspaces, 'shop', 'SHOP-1'), workspaces));
    });
});

describe('secret protection (Plan §16)', () => {
    test('secret files are never read', () => {
        for (const f of ['.env', 'app/.env.production', 'storage/oauth-private.key', 'certs/server.pem', 'home/.ssh/id_rsa', 'auth.json', '.npmrc']) {
            assert.equal(isSecretFile(f), true, f);
        }
        for (const f of ['.env.example', 'app/Models/User.php', 'resources/js/app.tsx', 'config/auth.php']) {
            assert.equal(isSecretFile(f), false, f);
        }
    });

    test('env files become shape: config values kept, credentials hidden', () => {
        const shape = envShape('APP_ENV=local\nDB_CONNECTION=mysql\nDB_PASSWORD=hunter2\nSTRIPE_SECRET="sk_live_abc"\nEMPTY=\n# DB_HOST=x\nQUEUE_CONNECTION=redis # comment');

        assert.deepEqual(shape, { APP_ENV: 'local', DB_CONNECTION: 'mysql', DB_PASSWORD: '<set>', STRIPE_SECRET: '<set>', EMPTY: '<empty>', QUEUE_CONNECTION: 'redis' });
    });

    test('credential-shaped text is redacted', () => {
        const text = [
            'const key = "sk-proj-abcdefghijklmnopqrstuvwxyz123456";',
            "Stripe::setApiKey('sk_" + "live_51Habcdefghijklmnop');", // split: a fake key, kept out of secret scanners
            'DB_PASSWORD=supersecret',
            'url = https://user:pass@example.com/repo.git',
            '-----BEGIN RSA PRIVATE KEY-----\nMIIabc\n-----END RSA PRIVATE KEY-----',
            'AKIAABCDEFGHIJKLMNOP',
        ].join('\n');
        const out = redactText(text);

        for (const secret of ['sk-proj-abc', 'sk_live_51H', 'supersecret', 'user:pass', 'MIIabc', 'AKIAABCDEFGHIJKLMNOP']) {
            assert.equal(out.includes(secret), false, secret);
        }
        assert.equal(redactRemoteUrl('https://token123@github.com/eaj/repo.git'), 'https://github.com/eaj/repo.git');
    });
});

describe('local command policy (Plan §84–85)', () => {
    const ctx = { cwd: path.join(projects, 'shop'), workspacesRoot: workspaces };
    const inWorkspace = { cwd: path.join(workspaces, 'shop', 'SHOP-1'), workspacesRoot: workspaces };

    test('read-only git is allowed in projects', () => {
        assert.doesNotThrow(() => assertGitAllowed(['status', '--porcelain'], ctx));
        assert.doesNotThrow(() => assertGitAllowed(['-c', 'user.name=x', 'commit', '-m', 'x'], ctx));
    });

    test('destructive git only inside a workspace', () => {
        assert.throws(() => assertGitAllowed(['reset', '--hard', 'HEAD'], ctx), { code: 'COMMAND_BLOCKED' });
        assert.throws(() => assertGitAllowed(['clean', '-fd'], ctx), { code: 'COMMAND_BLOCKED' });
        assert.doesNotThrow(() => assertGitAllowed(['reset', '--hard', 'abc1234'], inWorkspace));
        assert.doesNotThrow(() => assertGitAllowed(['clean', '-fd'], inWorkspace));
    });

    test('push, branch deletion, stash pop/drop and config writes are blocked', () => {
        for (const args of [['push', '--force'], ['push'], ['branch', '-D', 'main'], ['stash', 'drop'], ['stash', 'pop'], ['config', 'core.hooksPath', 'x'], ['fetch'], ['checkout', 'main']]) {
            assert.throws(() => assertGitAllowed(args, inWorkspace), { code: 'COMMAND_BLOCKED' }, args.join(' '));
        }
    });

    test('dangerous command lines are recognised', () => {
        for (const line of ['rm -rf /', 'DROP DATABASE shop', 'git reset --hard', 'git push --force origin main', 'composer update', 'php artisan migrate --force']) {
            assert.equal(isDangerous(line), true, line);
        }
        assert.equal(isDangerous('php artisan test'), false);
    });
});

describe('signed commands (Plan §89.2)', () => {
    const secret = 'test-secret';
    const sign = (/** @type {any} */ c) => crypto.createHmac('sha256', secret).update(`${c.id}\n${c.type}\n${c.issued_at}\n${c.payload_json}`).digest('hex');
    const base = { id: '01J', type: 'project.scan', issued_at: new Date().toISOString(), payload_json: '{"project_path":"C:\\\\x"}' };

    test('valid signatures are accepted', () => {
        assert.deepEqual(verifyCommand({ ...base, signature: sign(base) }, secret), { project_path: 'C:\\x' });
    });

    test('tampered, unsigned or stale commands are refused', () => {
        const tampered = { ...base, payload_json: '{"project_path":"C:\\\\Windows"}', signature: sign(base) };
        assert.throws(() => verifyCommand(tampered, secret), { code: 'BAD_SIGNATURE' });
        assert.throws(() => verifyCommand({ ...base, signature: '' }, secret), { code: 'BAD_SIGNATURE' });

        const old = { ...base, issued_at: new Date(Date.now() - 60 * 60_000).toISOString() };
        assert.throws(() => verifyCommand({ ...old, signature: sign(old) }, secret), { code: 'STALE_COMMAND' });
    });

    test('plain http is only allowed to this machine', () => {
        assert.equal(assertSafeServer('http://127.0.0.1:8000/x', false), 'http://127.0.0.1:8000');
        assert.equal(assertSafeServer('https://studio.eaj.dev', false), 'https://studio.eaj.dev');
        assert.throws(() => assertSafeServer('http://studio.eaj.dev', false), { code: 'INSECURE_SERVER' });
    });
});

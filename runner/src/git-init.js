// @ts-check
import fs from 'node:fs';
import path from 'node:path';
import { git } from './exec.js';
import { assertAllowedProjectPath, RunnerError } from './paths.js';
import { isSecretFile } from './redact.js';

/**
 * Bring a non-Git project under version control (Plan §14): .gitignore, init,
 * baseline commit. Only runs after the owner approved it in the Studio.
 * Existing files are never overwritten; secrets are never committed.
 *
 * @param {{ project_path: string }} payload
 * @param {{ allowedRoots: string[], workspacesRoot: string }} ctx
 */
export async function initializeGit(payload, ctx) {
    const project = assertAllowedProjectPath(payload.project_path, ctx.allowedRoots);
    const opts = { cwd: project, workspacesRoot: ctx.workspacesRoot };

    const existing = await git(['rev-parse', '--show-toplevel'], { ...opts, allowFailure: true });
    if (existing.code === 0) {
        throw new RunnerError('ALREADY_GIT', `This folder is already inside a Git repository (${existing.stdout.trim()}).`);
    }

    const gitignorePath = path.join(project, '.gitignore');
    const gitignoreCreated = !fs.existsSync(gitignorePath);

    if (gitignoreCreated) {
        fs.writeFileSync(gitignorePath, gitignoreFor(project));
    }

    await git(['init', '-q', '-b', 'main'], opts);
    await git(['add', '-A'], { ...opts, timeoutMs: 600_000 });

    // Whatever the existing .gitignore says, secret files never enter the baseline.
    const staged = (await git(['diff', '--cached', '--name-only', '-z'], opts)).stdout.split('\0').filter(Boolean);
    const secrets = staged.filter((f) => isSecretFile(f));

    if (secrets.length > 0) {
        for (const file of secrets) {
            await git(['rm', '--cached', '-q', '--', file], opts);
        }

        fs.appendFileSync(gitignorePath, `\n# Added by EAJ AI Dev Studio: secret files are never committed\n${secrets.map((f) => `/${f}`).join('\n')}\n`);
        await git(['add', '--', '.gitignore'], opts);
    }

    const name = (await git(['config', '--get', 'user.name'], { ...opts, allowFailure: true })).stdout.trim();
    const email = (await git(['config', '--get', 'user.email'], { ...opts, allowFailure: true })).stdout.trim();
    const identity = name && email ? [] : ['-c', 'user.name=EAJ AI Dev Studio', '-c', 'user.email=runner@eaj.local'];

    await git([...identity, 'commit', '--no-verify', '-q', '--allow-empty', '-m', 'EAJ baseline: initial commit before AI changes'], { ...opts, timeoutMs: 600_000 });

    const committed = (await git(['ls-files', '-z'], opts)).stdout.split('\0').filter(Boolean).length;

    return {
        head: (await git(['rev-parse', 'HEAD'], opts)).stdout.trim(),
        branch: 'main',
        files_committed: committed,
        gitignore_created: gitignoreCreated,
        secrets_excluded: secrets,
    };
}

/**
 * @param {string} project
 */
function gitignoreFor(project) {
    const has = (/** @type {string} */ f) => fs.existsSync(path.join(project, f));
    const lines = ['# Created by EAJ AI Dev Studio', '', '# Secrets', '.env', '.env.*', '!.env.example', '*.pem', '*.key', ''];

    if (has('composer.json')) {
        lines.push('# PHP / Laravel', '/vendor/', '/storage/*.key', '/storage/logs/', '/storage/framework/cache/', '/storage/framework/sessions/', '/storage/framework/views/', '/bootstrap/cache/*.php', '/public/hot', '/public/storage', '.phpunit.result.cache', '');
    }

    if (has('package.json')) {
        lines.push('# Node', 'node_modules/', '/public/build/', '/dist/', '/build/', '/.next/', '/out/', '/coverage/', 'npm-debug.log*', 'yarn-error.log*', '');
    }

    lines.push('# OS / editors', '.DS_Store', 'Thumbs.db', '.idea/', '.vscode/', '');

    return lines.join('\n');
}

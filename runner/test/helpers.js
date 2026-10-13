// Test helpers: throwaway folders and Git repos under the OS temp dir.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/** @param {string} prefix */
export function tempDir(prefix = 'eaj-runner-') {
    return fs.realpathSync.native(fs.mkdtempSync(path.join(os.tmpdir(), prefix)));
}

/**
 * @param {string} root
 * @param {Record<string, string | object>} files
 */
export function writeFiles(root, files) {
    for (const [rel, content] of Object.entries(files)) {
        const abs = path.join(root, rel);
        fs.mkdirSync(path.dirname(abs), { recursive: true });
        fs.writeFileSync(abs, typeof content === 'string' ? content : JSON.stringify(content, null, 2));
    }
}

/**
 * @param {string} cwd
 * @param {string[]} args
 */
export function gitSync(cwd, args) {
    return execFileSync('git', ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'init.defaultBranch=main', ...args], {
        cwd,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
}

/** @param {string} dir @param {Record<string, string | object>} files */
export function makeRepo(dir, files) {
    writeFiles(dir, files);
    gitSync(dir, ['init', '-q']);
    gitSync(dir, ['add', '-A']);
    gitSync(dir, ['commit', '-q', '-m', 'initial']);

    return dir;
}

/** @param {...string} dirs */
export function cleanup(...dirs) {
    for (const d of dirs) fs.rmSync(d, { recursive: true, force: true, maxRetries: 3 });
}

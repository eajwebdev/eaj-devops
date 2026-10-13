// @ts-check
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { git } from './exec.js';
import { isInside, RunnerError } from './paths.js';
import { isSecretFile, redactText } from './redact.js';
import { assertWorktree } from './workspace.js';

/**
 * File access for agents, only ever inside a task workspace (Plan §13, §16,
 * §48.8). Reads leave the PC redacted; writes are all-or-nothing and only
 * land on files that are exactly as the agent last saw them — a file that
 * changed since it was read is refused, never overwritten.
 *
 * @typedef {{ allowedRoots: string[], workspacesRoot: string }} Ctx
 */

const MAX_FILES = 60;
const MAX_FILE_BYTES = 256 * 1024;
const MAX_TOTAL_BYTES = 1536 * 1024;
const MAX_WRITE_BYTES = 512 * 1024;
const MAX_DIFF_BYTES = 400 * 1024;
const PROTECTED = /^(\.git|vendor|node_modules)(\/|$)/i;
const REDACTION_MARKER = /\[REDACTED[ A-Z]*\]/;

/**
 * @param {{ workspace_path: string, paths: string[] }} payload
 * @param {Ctx} ctx
 */
export async function readWorkspaceFiles(payload, ctx) {
    const workspace = await assertWorktree(payload.workspace_path, ctx);
    const paths = validPaths(payload.paths);
    let total = 0;

    const files = paths.map((rel) => {
        const abs = resolveInside(workspace, rel);

        if (isSecretFile(rel)) return { path: rel, status: 'secret' };
        if (!fs.existsSync(abs)) return { path: rel, status: 'missing' };

        const stat = fs.statSync(abs);
        if (!stat.isFile()) return { path: rel, status: 'not_a_file' };

        const bytes = fs.readFileSync(abs);
        const sha1 = hash(bytes);

        if (stat.size > MAX_FILE_BYTES) return { path: rel, status: 'too_large', sha1, size: stat.size };
        if (bytes.subarray(0, 8000).includes(0)) return { path: rel, status: 'binary', sha1, size: stat.size };
        if (total + stat.size > MAX_TOTAL_BYTES) return { path: rel, status: 'over_budget', sha1, size: stat.size };

        total += stat.size;
        const text = bytes.toString('utf8');
        const content = redactText(text);

        return {
            path: rel,
            status: 'ok',
            sha1,
            size: stat.size,
            lines: text.split('\n').length,
            // Holds credentials: the agent sees it redacted and may not rewrite it.
            redacted: content !== text,
            content,
        };
    });

    const head = (await git(['rev-parse', 'HEAD'], { cwd: workspace, workspacesRoot: ctx.workspacesRoot })).stdout.trim();

    return { head_sha: head, files };
}

/**
 * @param {{ workspace_path: string, changes: { path: string, action: 'write' | 'delete', base_sha1: string | null, content?: string }[] }} payload
 * @param {Ctx} ctx
 */
export async function applyWorkspaceChanges(payload, ctx) {
    const workspace = await assertWorktree(payload.workspace_path, ctx);
    const changes = Array.isArray(payload.changes) ? payload.changes : [];

    if (changes.length === 0 || changes.length > MAX_FILES) throw new RunnerError('INVALID_INPUT', `Between 1 and ${MAX_FILES} changes are allowed.`);

    const paths = validPaths(changes.map((c) => c.path));

    // 1. Check every change before touching anything.
    const plan = changes.map((change, i) => {
        const rel = paths[i];
        const abs = resolveInside(workspace, rel);

        if (PROTECTED.test(rel)) throw new RunnerError('PATH_NOT_ALLOWED', `${rel} is managed by Git or a package manager and is never edited by agents.`);
        if (isSecretFile(rel)) throw new RunnerError('SECRET_FILE', `${rel} is a secret file and is never written by agents.`);
        if (change.action !== 'write' && change.action !== 'delete') throw new RunnerError('INVALID_INPUT', `Unknown action for ${rel}.`);

        const exists = fs.existsSync(abs);
        if (exists && !fs.statSync(abs).isFile()) throw new RunnerError('INVALID_INPUT', `${rel} is not a file.`);

        const original = exists ? fs.readFileSync(abs) : null;
        const current = original ? hash(original) : null;

        if ((change.base_sha1 ?? null) !== current) {
            throw new RunnerError('BASE_CHANGED', `${rel} changed since the agent read it (or ${current ? 'already exists' : 'no longer exists'}). Nothing was written.`);
        }

        if (change.action === 'delete') {
            if (!original) throw new RunnerError('INVALID_INPUT', `${rel} does not exist.`);

            return { rel, abs, original, content: null };
        }

        if (typeof change.content !== 'string' || Buffer.byteLength(change.content) > MAX_WRITE_BYTES) {
            throw new RunnerError('INVALID_INPUT', `${rel}: content is missing or larger than ${MAX_WRITE_BYTES / 1024} KB.`);
        }
        if (REDACTION_MARKER.test(change.content)) {
            throw new RunnerError('REDACTION_MARKER', `${rel}: the new content contains a redaction marker; writing it would destroy a real value.`);
        }
        if (original && redactText(original.toString('utf8')) !== original.toString('utf8')) {
            throw new RunnerError('SECRET_CONTENT', `${rel} contains credentials, so agents may not rewrite it. Change it by hand.`);
        }

        return { rel, abs, original, content: matchLineEndings(change.content, original) };
    });

    // 2. Write all of them, or none: a failure restores what was there.
    /** @type {typeof plan} */
    const done = [];

    try {
        for (const item of plan) {
            if (item.content === null) {
                fs.rmSync(item.abs);
            } else {
                fs.mkdirSync(path.dirname(item.abs), { recursive: true });
                fs.writeFileSync(item.abs, item.content);
            }
            done.push(item);
        }
    } catch (error) {
        for (const item of done.reverse()) {
            if (item.original) fs.writeFileSync(item.abs, item.original);
            else fs.rmSync(item.abs, { force: true });
        }

        throw new RunnerError('WRITE_FAILED', `Could not write ${done.length < plan.length ? plan[done.length].rel : 'the changes'}: ${/** @type {Error} */ (error).message}. Nothing was changed.`);
    }

    // 3. Stage them so the diff includes new and deleted files.
    const opts = { cwd: workspace, workspacesRoot: ctx.workspacesRoot };
    const rels = plan.map((p) => p.rel);
    await git(['add', '-A', '--', ...rels], opts);

    return { applied: plan.length, ...(await diffOf(['diff', '--cached'], rels, opts)) };
}

/**
 * Everything the task changed since its base commit (Plan §53 "diff-first review").
 * @param {{ workspace_path: string, base_sha: string }} payload
 * @param {Ctx} ctx
 */
export async function workspaceDiff(payload, ctx) {
    const workspace = await assertWorktree(payload.workspace_path, ctx);

    if (!/^[0-9a-f]{7,64}$/.test(payload.base_sha ?? '')) throw new RunnerError('INVALID_INPUT', 'Invalid base SHA.');

    const opts = { cwd: workspace, workspacesRoot: ctx.workspacesRoot };
    const head = (await git(['rev-parse', 'HEAD'], opts)).stdout.trim();

    return { head_sha: head, base_sha: payload.base_sha, ...(await diffOf(['diff', payload.base_sha], [], opts)) };
}

/**
 * @param {string[]} args
 * @param {string[]} rels
 * @param {{ cwd: string, workspacesRoot: string }} opts
 */
async function diffOf(args, rels, opts) {
    const scope = rels.length ? ['--', ...rels] : [];
    const numstat = (await git([...args, '--numstat', '-z', '--find-renames', ...scope], opts)).stdout;
    const patch = (await git([...args, '--find-renames', '--unified=5', ...scope], { ...opts, timeoutMs: 120_000 })).stdout;

    const files = [];
    const parts = numstat.split('\0');

    for (let i = 0; i < parts.length; i++) {
        const m = /^(-|\d+)\t(-|\d+)\t(.*)$/s.exec(parts[i]);
        if (!m) continue;

        let file = m[3];
        // Renames: "adds\tdels\t" followed by old and new paths as separate entries.
        if (file === '') {
            file = parts[i + 2] ?? parts[i + 1] ?? '';
            i += 2;
        }

        files.push({ path: file, additions: m[1] === '-' ? null : Number(m[1]), deletions: m[2] === '-' ? null : Number(m[2]) });
    }

    const redacted = redactText(patch);

    return {
        files,
        diff: redacted.length > MAX_DIFF_BYTES ? redacted.slice(0, MAX_DIFF_BYTES) : redacted,
        diff_truncated: redacted.length > MAX_DIFF_BYTES,
    };
}

/**
 * Workspace-relative, forward-slash paths without "..", drive letters or
 * option-looking names.
 * @param {unknown} paths
 * @returns {string[]}
 */
function validPaths(paths) {
    if (!Array.isArray(paths) || paths.length === 0 || paths.length > MAX_FILES) {
        throw new RunnerError('INVALID_INPUT', `Between 1 and ${MAX_FILES} paths are allowed.`);
    }

    return paths.map((p) => {
        const rel = typeof p === 'string' ? p.replace(/\\/g, '/').replace(/^\.\//, '') : '';

        if (!rel || rel.length > 300 || rel.includes('\0') || rel.startsWith('/') || rel.startsWith('-') || /^[A-Za-z]:/.test(rel) || rel.split('/').some((s) => s === '..' || s === '')) {
            throw new RunnerError('INVALID_PATH', `Invalid workspace path: ${String(p).slice(0, 100)}`);
        }

        return rel;
    });
}

/**
 * @param {string} workspace
 * @param {string} rel
 */
function resolveInside(workspace, rel) {
    const abs = path.join(workspace, rel);
    // Real-path comparison of the nearest existing ancestor: a symlink cannot lead out of the workspace.
    let probe = abs;
    while (!fs.existsSync(probe) && path.dirname(probe) !== probe) probe = path.dirname(probe);

    if (!isInside(workspace, probe)) throw new RunnerError('PATH_NOT_ALLOWED', `${rel} leads outside the workspace.`);

    return abs;
}

/**
 * Keep a file's existing line endings, so a Windows file does not become a whole-file diff.
 * @param {string} content
 * @param {Buffer | null} original
 */
function matchLineEndings(content, original) {
    if (!original) return content;

    const text = original.toString('utf8');
    const crlf = (text.match(/\r\n/g) ?? []).length;
    const lf = (text.match(/\n/g) ?? []).length;

    return crlf > 0 && crlf >= lf / 2 ? content.replace(/\r?\n/g, '\r\n') : content;
}

/** @param {Buffer} bytes */
function hash(bytes) {
    return crypto.createHash('sha1').update(bytes).digest('hex');
}

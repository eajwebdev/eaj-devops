// @ts-check
import fs from 'node:fs';
import path from 'node:path';

const caseInsensitive = process.platform === 'win32' || process.platform === 'darwin';

/**
 * Normalize an absolute path for comparison (resolves "..", unifies separators,
 * folds case on case-insensitive file systems).
 * @param {string} p
 */
export function normalize(p) {
    const resolved = path.resolve(p);
    const trimmed = resolved.length > path.parse(resolved).root.length ? resolved.replace(/[\\/]+$/, '') : resolved;

    return caseInsensitive ? trimmed.toLowerCase() : trimmed;
}

/**
 * True when `child` is `parent` or inside it — compared on real paths so a
 * symlink or junction cannot be used to escape an allowed folder.
 * @param {string} parent
 * @param {string} child
 */
export function isInside(parent, child) {
    const a = normalize(realOrSelf(parent));
    const b = normalize(realOrSelf(child));

    if (a === b) return true;

    const rel = path.relative(a, b);

    return rel !== '' && !rel.startsWith('..') && !path.isAbsolute(rel);
}

/**
 * Same location on disk, whatever alias each path uses (8.3 short names such
 * as ADMINI~1, junctions, symlinks, separators, case).
 * @param {string} a
 * @param {string} b
 */
export function samePath(a, b) {
    return normalize(realOrSelf(a)) === normalize(realOrSelf(b));
}

/**
 * @param {string} p
 */
function realOrSelf(p) {
    try {
        return fs.realpathSync.native(p);
    } catch {
        return path.resolve(p);
    }
}

/**
 * @param {string} p
 */
export function isAbsoluteLocalPath(p) {
    return typeof p === 'string' && p.length < 1024 && !p.includes('\0') && path.isAbsolute(p);
}

/**
 * Validate a project folder the server asked about: absolute, inside one of the
 * roots the PC owner allowed with `eaj-runner allow`, and an existing directory.
 * The server can never widen these roots.
 *
 * @param {string} folder
 * @param {string[]} allowedRoots
 * @returns {string} the resolved folder
 */
export function assertAllowedProjectPath(folder, allowedRoots) {
    if (!isAbsoluteLocalPath(folder)) {
        throw new RunnerError('INVALID_PATH', 'The project path must be a full folder path.');
    }

    const resolved = path.resolve(folder);

    if (!allowedRoots.some((root) => isInside(root, resolved))) {
        throw new RunnerError(
            'PATH_NOT_ALLOWED',
            `This runner may not read ${resolved}. On this PC run: eaj-runner allow "${path.dirname(resolved)}"`,
        );
    }

    if (!fs.existsSync(resolved) || !fs.statSync(resolved).isDirectory()) {
        throw new RunnerError('PATH_NOT_FOUND', `Folder not found: ${resolved}`);
    }

    return resolved;
}

/**
 * @param {string} p
 * @param {string} workspacesRoot
 */
export function assertInsideWorkspaces(p, workspacesRoot) {
    if (!isAbsoluteLocalPath(p) || !isInside(workspacesRoot, p) || samePath(p, workspacesRoot)) {
        throw new RunnerError('PATH_NOT_ALLOWED', `Refusing to operate outside the workspaces folder (${workspacesRoot}).`);
    }

    return path.resolve(p);
}

/** Error with a stable code that the Studio can show and act on. */
export class RunnerError extends Error {
    /**
     * @param {string} code
     * @param {string} message
     */
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

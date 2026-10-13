// @ts-check
import fs from 'node:fs';
import path from 'node:path';
import { isSecretFile } from '../redact.js';

/**
 * Small, safe file helpers for detectors: every read goes through the secret
 * deny-list and a size cap, and failures read as "absent".
 */
export class ProjectFiles {
    /** @param {string} root */
    constructor(root) {
        this.root = root;
    }

    /** @param {string} rel */
    abs(rel) {
        return path.join(this.root, rel);
    }

    /** @param {string} rel */
    exists(rel) {
        return fs.existsSync(this.abs(rel));
    }

    /** @param {string} rel */
    isDir(rel) {
        try {
            return fs.statSync(this.abs(rel)).isDirectory();
        } catch {
            return false;
        }
    }

    /**
     * @param {string} rel
     * @param {number} [maxBytes]
     * @returns {string | null}
     */
    read(rel, maxBytes = 2 * 1024 * 1024) {
        if (isSecretFile(rel)) return null;

        try {
            const stat = fs.statSync(this.abs(rel));

            return stat.isFile() && stat.size <= maxBytes ? fs.readFileSync(this.abs(rel), 'utf8') : null;
        } catch {
            return null;
        }
    }

    /**
     * Env files are secret; only the runner's redactor may read them.
     * @param {string} rel
     * @returns {string | null}
     */
    readEnvForShape(rel) {
        try {
            const stat = fs.statSync(this.abs(rel));

            return stat.isFile() && stat.size <= 256 * 1024 ? fs.readFileSync(this.abs(rel), 'utf8') : null;
        } catch {
            return null;
        }
    }

    /**
     * @param {string} rel
     * @returns {any}
     */
    json(rel) {
        const text = this.read(rel, 20 * 1024 * 1024);

        if (text === null) return null;

        try {
            return JSON.parse(text.replace(/^\uFEFF/, ''));
        } catch {
            return null;
        }
    }

    /**
     * @param {string} rel
     * @returns {string[]}
     */
    list(rel) {
        try {
            return fs.readdirSync(this.abs(rel));
        } catch {
            return [];
        }
    }

    /**
     * Files under `rel` matching `re`, depth-limited, skipping dependency folders.
     * @param {string} rel
     * @param {RegExp} re
     * @param {number} [maxDepth]
     * @param {number} [limit]
     * @returns {string[]}
     */
    find(rel, re, maxDepth = 4, limit = 500) {
        /** @type {string[]} */
        const out = [];

        /** @param {string} dir @param {number} depth */
        const walk = (dir, depth) => {
            if (out.length >= limit || depth > maxDepth) return;

            for (const entry of this.list(dir)) {
                const child = dir === '.' ? entry : `${dir}/${entry}`;

                if (SKIP_DIRS.has(entry) || SKIP_DIRS.has(child)) continue;

                if (this.isDir(child)) walk(child, depth + 1);
                else if (re.test(child) && out.length < limit) out.push(child);
            }
        };

        walk(rel, 0);

        return out;
    }
}

export const SKIP_DIRS = new Set([
    '.git',
    'node_modules',
    'vendor',
    'storage',
    'bootstrap/cache',
    '.next',
    '.nuxt',
    'dist',
    'build',
    'out',
    'coverage',
    '.turbo',
    '.cache',
    'public/build',
    '.idea',
    '.vscode',
    'venv',
    '.venv',
    '__pycache__',
]);

// @ts-check
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { RunnerError } from '../paths.js';

/**
 * Local database safety for PHP test runs (Plan §15): agent tests never touch
 * the developer's database.
 *
 * - The tool never sees DB_* variables from the runner's own environment
 *   (process.js drops them), and workspaces have no .env.
 * - If phpunit.xml / .env.testing choose no database, tests get an in-memory
 *   SQLite database.
 * - If they name a server database, it must not be the one the project's .env
 *   uses; a server connection without a database name gets a per-task name
 *   (eaj_<project>_<task>_test), never the framework default.
 *
 * Values read here stay on the PC: only the connection, database name and
 * where they came from are reported.
 *
 * @typedef {{ connection: string, database: string, source: 'phpunit.xml' | '.env.testing' | 'injected' }} TestDatabase
 */

/**
 * @param {{ workspace: string, projectPath: string | null, taskKey: string }} input
 * @returns {{ env: Record<string, string>, database: TestDatabase, notes: string[] }}
 */
export function phpTestEnvironment({ workspace, projectPath, taskKey }) {
    const phpunit = phpunitEnv(workspace);
    const testing = readDotenv(path.join(workspace, '.env.testing'));
    // PHPUnit sets its <env> values before Laravel loads .env.testing, which never overrides them.
    const config = { ...testing, ...phpunit };
    const source = (/** @type {string} */ key) => (key in phpunit ? 'phpunit.xml' : '.env.testing');

    /** @type {Record<string, string>} */
    const env = {};
    /** @type {string[]} */
    const notes = [];

    if (!config.APP_KEY) {
        env.APP_KEY = `base64:${crypto.randomBytes(32).toString('base64')}`;
    }

    const connection = (config.DB_CONNECTION ?? '').toLowerCase();

    if (!connection) {
        env.DB_CONNECTION = 'sqlite';
        env.DB_DATABASE = ':memory:';
        notes.push('Tests ran on an in-memory SQLite database: phpunit.xml does not choose one, and agent tests never use your development database.');

        return { env, database: { connection: 'sqlite', database: ':memory:', source: 'injected' }, notes };
    }

    if (connection === 'sqlite') {
        return { env, database: { connection, database: config.DB_DATABASE || 'database/database.sqlite', source: source('DB_CONNECTION') }, notes };
    }

    const dev = projectPath ? readDotenv(path.join(projectPath, '.env')) : {};
    const devConnection = (dev.DB_CONNECTION ?? '').toLowerCase();
    const database = config.DB_DATABASE ?? '';

    if (database && dev.DB_DATABASE && database === dev.DB_DATABASE && (!devConnection || devConnection === connection)) {
        throw new RunnerError(
            'TEST_DB_UNSAFE',
            `The test configuration points at "${database}", the database the project's .env uses. Agent tests never run against your development database (Plan §15). Point phpunit.xml at SQLite or a separate test database.`,
        );
    }

    if (!database) {
        const name = `eaj_${path.basename(projectPath ?? workspace)}_${taskKey}_test`.toLowerCase().replace(/[^a-z0-9_]+/g, '_').slice(0, 64);
        env.DB_DATABASE = name;
        notes.push(`Tests use the ${connection} database "${name}" (one per task). Create it, or point phpunit.xml at SQLite.`);

        return { env, database: { connection, database: name, source: 'injected' }, notes };
    }

    return { env, database: { connection, database, source: source('DB_DATABASE') }, notes };
}

/**
 * <env name="X" value="Y"/> and <server …/> entries of phpunit.xml(.dist).
 * @param {string} root
 * @returns {Record<string, string>}
 */
export function phpunitEnv(root) {
    const file = ['phpunit.xml', 'phpunit.xml.dist'].map((f) => path.join(root, f)).find((f) => fs.existsSync(f));
    /** @type {Record<string, string>} */
    const out = {};

    if (!file) return out;

    const xml = fs.readFileSync(file, 'utf8').replace(/<!--[\s\S]*?-->/g, '');

    for (const m of xml.matchAll(/<(?:env|server)\b([^>]*)\/?>/g)) {
        const name = /\bname\s*=\s*"([^"]+)"/.exec(m[1])?.[1];
        const value = /\bvalue\s*=\s*"([^"]*)"/.exec(m[1])?.[1];

        if (name && value !== undefined) out[name] = value;
    }

    return out;
}

/**
 * Minimal dotenv reader for local comparisons only — values never leave the PC.
 * @param {string} file
 * @returns {Record<string, string>}
 */
function readDotenv(file) {
    /** @type {Record<string, string>} */
    const out = {};
    let text = '';

    try {
        text = fs.readFileSync(file, 'utf8');
    } catch {
        return out;
    }

    for (const raw of text.split(/\r?\n/)) {
        const m = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/.exec(raw);

        if (m && !raw.trim().startsWith('#')) out[m[1]] = m[2].replace(/\s+#.*$/, '').replace(/^(['"])(.*)\1$/, '$2').trim();
    }

    return out;
}

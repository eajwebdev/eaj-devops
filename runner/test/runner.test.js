import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import path from 'node:path';
import { after, beforeEach, describe, test } from 'node:test';
import { ApiError } from '../src/api.js';
import { Runner } from '../src/runner.js';
import { cleanup, tempDir, writeFiles } from './helpers.js';

const home = tempDir();
process.env.EAJ_RUNNER_HOME = home;
const projects = path.join(home, 'www');
writeFiles(projects, { 'shop/composer.json': { require: { 'laravel/framework': '^10' } }, 'shop/app/Shop.php': '<?php class Shop {}' });
after(() => cleanup(home));

const SECRET = 'signing-secret';

/** @param {string} type @param {object} payload @param {string} [id] */
function signed(type, payload, id = crypto.randomUUID()) {
    const c = { id, type, issued_at: new Date().toISOString(), payload_json: JSON.stringify(payload) };

    return { ...c, signature: crypto.createHmac('sha256', SECRET).update(`${c.id}\n${c.type}\n${c.issued_at}\n${c.payload_json}`).digest('hex') };
}

/** A fake Studio that hands out queued commands and records what the runner reports. */
class FakeStudio {
    constructor(/** @type {any[]} */ queue) {
        this.queue = queue;
        /** @type {{ id: string, outcome: any }[]} */
        this.completed = [];
        /** @type {any[][]} */
        this.chunks = [];
        this.failCompletes = 0;
    }
    async heartbeat() {
        return { halted: false, server_time: new Date().toISOString() };
    }
    async claim() {
        return { command: this.queue.shift() ?? null, halted: false };
    }
    async progress() {}
    async indexChunk(/** @type {string} */ _id, /** @type {any[]} */ files) {
        this.chunks.push(files);
    }
    async complete(/** @type {string} */ id, /** @type {any} */ outcome) {
        if (this.failCompletes > 0) {
            this.failCompletes--;
            throw new ApiError(503, 'Studio down');
        }
        this.completed.push({ id, outcome });
    }
}

const config = () => ({ server: 'http://127.0.0.1:8000', runnerId: 1, token: 't', signingSecret: SECRET, name: 'test', allowedRoots: [projects], workspacesRoot: path.join(home, 'ws'), insecure: false });

describe('runner loop (Plan §48.3, §89.2)', () => {
    beforeEach(() => cleanup(path.join(home, 'state.json')));

    test('runs a signed scan and reports the result', async () => {
        const studio = new FakeStudio([signed('project.scan', { project_path: path.join(projects, 'shop') })]);
        await new Runner(config(), { client: /** @type {any} */ (studio), log: () => {} }).start({ once: true });

        assert.equal(studio.completed.length, 1);
        assert.equal(studio.completed[0].outcome.status, 'succeeded');
        assert.equal(studio.completed[0].outcome.result.stack.backend.framework, 'Laravel');
    });

    test('streams the code index in chunks', async () => {
        const studio = new FakeStudio([signed('project.index', { project_path: path.join(projects, 'shop') })]);
        await new Runner(config(), { client: /** @type {any} */ (studio), log: () => {} }).start({ once: true });

        assert.equal(studio.completed[0].outcome.status, 'succeeded');
        assert.equal(studio.completed[0].outcome.result.files, 2);
        assert.deepEqual(studio.chunks.flat().map((f) => f.path).sort(), ['app/Shop.php', 'composer.json']);
    });

    test('refuses tampered commands and folders outside the allowed roots', async () => {
        const tampered = { ...signed('project.scan', { project_path: path.join(projects, 'shop') }), payload_json: JSON.stringify({ project_path: 'C:\\Windows' }) };
        const outside = signed('project.scan', { project_path: home });
        const studio = new FakeStudio([tampered, outside]);
        await new Runner(config(), { client: /** @type {any} */ (studio), log: () => {} }).start({ once: true });

        assert.deepEqual(studio.completed.map((c) => c.outcome.error?.code), ['BAD_SIGNATURE', 'PATH_NOT_ALLOWED']);
    });

    test('a re-delivered command is answered from local state, not run twice', async () => {
        const command = signed('git.init', { project_path: path.join(projects, 'shop') }, 'cmd-1');
        const studio = new FakeStudio([command, { ...command }]);
        await new Runner(config(), { client: /** @type {any} */ (studio), log: () => {} }).start({ once: true });

        assert.equal(studio.completed.length, 2);
        assert.deepEqual(studio.completed[1].outcome, studio.completed[0].outcome);
        assert.equal(studio.completed[0].outcome.status, 'succeeded', 'first run initialized Git');
    });

    test('results that cannot be delivered are kept and replayed on reconnect', async () => {
        const studio = new FakeStudio([signed('project.scan', { project_path: path.join(projects, 'shop') }, 'cmd-offline')]);
        studio.failCompletes = 2; // the first delivery and the next loop's replay both fail

        const runner = new Runner(config(), { client: /** @type {any} */ (studio), log: () => {} });
        await runner.start({ once: true });
        assert.equal(studio.completed.length, 0);
        assert.equal(runner.state.pending.length, 1);

        await new Runner(config(), { client: /** @type {any} */ (studio), log: () => {} }).start({ once: true });
        assert.deepEqual(studio.completed.map((c) => c.id), ['cmd-offline']);
    });
});

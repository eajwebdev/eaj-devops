// @ts-check
import crypto from 'node:crypto';
import os from 'node:os';
import { RunnerError } from './paths.js';

export const RUNNER_VERSION = '0.1.0';

/**
 * @typedef {{ id: string, type: string, issued_at: string, payload_json: string, signature: string }} SignedCommand
 */

export class ApiError extends Error {
    /**
     * @param {number} status
     * @param {string} message
     */
    constructor(status, message) {
        super(message);
        this.status = status;
    }
}

/**
 * Plain HTTP is only acceptable to this machine itself (single-user mode,
 * Plan §89.2); anything else must be HTTPS unless explicitly overridden.
 * @param {string} server
 * @param {boolean} insecure
 */
export function assertSafeServer(server, insecure) {
    const url = new URL(server);
    const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);

    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && (local || insecure))) {
        throw new RunnerError('INSECURE_SERVER', 'The Studio address must use https:// (http is only allowed for localhost).');
    }

    return url.origin;
}

/** Talks to the Studio's runner API over an outbound connection — the PC opens no ports. */
export class StudioClient {
    /**
     * @param {{ server: string, token: string, insecure?: boolean }} options
     */
    constructor({ server, token, insecure = false }) {
        this.base = assertSafeServer(server, insecure);
        this.token = token;
    }

    /**
     * @param {string} server
     * @param {string} code
     * @param {string} name
     * @param {boolean} insecure
     * @returns {Promise<{ runner_id: number, token: string, signing_secret: string, organization: string }>}
     */
    static async pair(server, code, name, insecure) {
        const base = assertSafeServer(server, insecure);

        return request(`${base}/api/runner/v1/pair`, null, 'POST', { code, ...machineInfo(name) });
    }

    /** @param {Record<string, unknown>} body */
    heartbeat(body) {
        return /** @type {Promise<{ halted: boolean, revoked?: boolean, server_time: string }>} */ (this.call('POST', '/heartbeat', body));
    }

    /** @returns {Promise<{ command: SignedCommand | null, halted: boolean }>} */
    claim() {
        return /** @type {any} */ (this.call('POST', '/commands/claim', {}));
    }

    /**
     * @param {string} id
     * @param {string} message
     */
    progress(id, message) {
        return this.call('POST', `/commands/${encodeURIComponent(id)}/progress`, { message });
    }

    /**
     * @param {string} id
     * @param {unknown[]} files
     */
    indexChunk(id, files) {
        return this.call('POST', `/commands/${encodeURIComponent(id)}/index-chunk`, { files });
    }

    /**
     * @param {string} id
     * @param {import('./config.js').CommandOutcome} outcome
     */
    complete(id, outcome) {
        return this.call('POST', `/commands/${encodeURIComponent(id)}/complete`, outcome);
    }

    /**
     * @param {string} method
     * @param {string} path
     * @param {unknown} body
     */
    call(method, path, body) {
        return request(`${this.base}/api/runner/v1${path}`, this.token, method, body);
    }
}

/**
 * @param {string} url
 * @param {string | null} token
 * @param {string} method
 * @param {unknown} body
 * @returns {Promise<any>}
 */
async function request(url, token, method, body) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'User-Agent': `eaj-runner/${RUNNER_VERSION}`,
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(60_000),
    });

    const text = await response.text();
    /** @type {any} */
    let json = null;
    try {
        json = text ? JSON.parse(text) : null;
    } catch {
        // Non-JSON error page.
    }

    if (!response.ok) {
        throw new ApiError(response.status, json?.message ?? `Studio returned HTTP ${response.status}`);
    }

    return json;
}

/** @param {string} name */
export function machineInfo(name) {
    return { name, hostname: os.hostname(), os: `${os.type()} ${os.release()}`, platform: process.platform, version: RUNNER_VERSION, node: process.version };
}

/**
 * Commands are signed by the Studio with this runner's secret; the runner
 * refuses anything unsigned, tampered with or stale.
 *
 * @param {SignedCommand} command
 * @param {string} secret
 * @param {Date} [now]
 * @returns {Record<string, any>} the verified payload
 */
export function verifyCommand(command, secret, now = new Date()) {
    const expected = crypto.createHmac('sha256', secret).update(`${command.id}\n${command.type}\n${command.issued_at}\n${command.payload_json}`).digest('hex');
    const given = Buffer.from(String(command.signature ?? ''), 'utf8');
    const want = Buffer.from(expected, 'utf8');

    if (given.length !== want.length || !crypto.timingSafeEqual(given, want)) {
        throw new RunnerError('BAD_SIGNATURE', 'Command signature is invalid — refusing to run it.');
    }

    const age = now.getTime() - new Date(command.issued_at).getTime();
    if (!Number.isFinite(age) || age > 15 * 60_000 || age < -5 * 60_000) {
        throw new RunnerError('STALE_COMMAND', 'Command is too old (or the PC clock is wrong) — refusing to run it.');
    }

    return JSON.parse(command.payload_json);
}

// @ts-check

/**
 * Secret protection (Plan §16). Runs on the PC, BEFORE anything leaves it.
 * The Studio repeats the same checks server-side as defence in depth.
 */

/** Files whose content is never read or sent, whatever the server asks. */
const SECRET_FILE_PATTERNS = [
    /(^|[\\/])\.env(\..+)?$/i,
    /\.(pem|key|p12|pfx|jks|keystore|crt|cer|der|kdbx|ovpn)$/i,
    /(^|[\\/])id_(rsa|dsa|ecdsa|ed25519)(\.pub)?$/i,
    /(^|[\\/])\.ssh([\\/]|$)/i,
    /(^|[\\/])\.aws([\\/]|$)/i,
    /(^|[\\/])\.npmrc$/i,
    /(^|[\\/])\.pypirc$/i,
    /(^|[\\/])\.netrc$/i,
    /(^|[\\/])auth\.json$/i,
    /(^|[\\/])credentials(\.json)?$/i,
    /(^|[\\/])service-account.*\.json$/i,
    /(^|[\\/])storage[\\/]oauth-(private|public)\.key$/i,
];

/** @param {string} filePath */
export function isSecretFile(filePath) {
    const example = /\.env\.(example|sample|dist|template)$/i.test(filePath);

    return !example && SECRET_FILE_PATTERNS.some((re) => re.test(filePath));
}

/**
 * Env keys whose VALUES are configuration shape, not secrets, and may be shown
 * (Plan §16: "agents may know configuration shape without receiving credentials").
 */
const SHAPE_KEYS = new Set([
    'APP_ENV',
    'APP_DEBUG',
    'APP_LOCALE',
    'APP_TIMEZONE',
    'DB_CONNECTION',
    'QUEUE_CONNECTION',
    'QUEUE_DRIVER',
    'CACHE_DRIVER',
    'CACHE_STORE',
    'SESSION_DRIVER',
    'BROADCAST_DRIVER',
    'BROADCAST_CONNECTION',
    'FILESYSTEM_DISK',
    'FILESYSTEM_DRIVER',
    'MAIL_MAILER',
    'MAIL_DRIVER',
    'LOG_CHANNEL',
    'LOG_LEVEL',
    'NODE_ENV',
]);

/**
 * Turn the text of an env file into its shape: allow-listed keys keep their
 * value, every other key becomes "<set>" or "<empty>".
 *
 * @param {string} text
 * @returns {Record<string, string>}
 */
export function envShape(text) {
    /** @type {Record<string, string>} */
    const shape = {};

    for (const raw of text.split(/\r?\n/)) {
        const line = raw.trim();
        const match = /^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/.exec(line);

        if (!match || line.startsWith('#')) continue;

        const [, key, rawValue] = match;
        const value = rawValue.replace(/\s+#.*$/, '').replace(/^(['"])(.*)\1$/, '$2').trim();

        shape[key] = SHAPE_KEYS.has(key.toUpperCase()) && /^[\w.\-:/]{0,64}$/.test(value) ? value : value === '' ? '<empty>' : '<set>';
    }

    return shape;
}

/** Credential patterns removed from any text that leaves the PC. */
const TEXT_PATTERNS = /** @type {const} */ ([
    [/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/g, '[REDACTED PRIVATE KEY]'],
    [/\b(AKIA|ASIA)[0-9A-Z]{16}\b/g, '[REDACTED AWS KEY]'],
    [/\bsk-(?:proj-|ant-)?[A-Za-z0-9_-]{20,}\b/g, '[REDACTED API KEY]'],
    [/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]{16,}\b/g, '[REDACTED STRIPE KEY]'],
    [/\bgh[pousr]_[A-Za-z0-9]{30,}\b/g, '[REDACTED GITHUB TOKEN]'],
    [/\bxox[abpr]-[A-Za-z0-9-]{10,}\b/g, '[REDACTED SLACK TOKEN]'],
    [/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/g, '[REDACTED JWT]'],
    [/\bbase64:[A-Za-z0-9+/]{40,}={0,2}/g, '[REDACTED APP KEY]'],
    [/(\/\/)[^/\s:@]+:[^/\s@]+@/g, '$1[REDACTED]@'],
    [
        /\b([A-Z0-9_]*(?:SECRET|PASSWORD|PASSWD|TOKEN|API_KEY|PRIVATE_KEY|ACCESS_KEY|CLIENT_SECRET)[A-Z0-9_]*)\s*[=:]\s*(['"]?)[^\s'"]{4,}\2/gi,
        '$1=[REDACTED]',
    ],
]);

/**
 * @param {string} text
 */
export function redactText(text) {
    let out = text;

    for (const [pattern, replacement] of TEXT_PATTERNS) {
        out = out.replace(pattern, replacement);
    }

    return out;
}

/**
 * Remove credentials embedded in a Git remote URL, keeping host and path.
 * @param {string} url
 */
export function redactRemoteUrl(url) {
    return url.replace(/^(https?:\/\/)[^@/]+@/i, '$1');
}

<?php

namespace App\Security;

/**
 * Server-side secret protection (Plan §16) — defence in depth behind the
 * runner's own redaction. Applied to everything a runner reports before it is
 * stored, and (from Phase 6) to every context sent to an AI provider.
 */
class SecretRedactor
{
    /** Env keys whose values are configuration shape, not secrets. Mirrors runner/src/redact.js. */
    private const SHAPE_KEYS = [
        'APP_ENV', 'APP_DEBUG', 'APP_LOCALE', 'APP_TIMEZONE', 'DB_CONNECTION', 'QUEUE_CONNECTION', 'QUEUE_DRIVER',
        'CACHE_DRIVER', 'CACHE_STORE', 'SESSION_DRIVER', 'BROADCAST_DRIVER', 'BROADCAST_CONNECTION', 'FILESYSTEM_DISK',
        'FILESYSTEM_DRIVER', 'MAIL_MAILER', 'MAIL_DRIVER', 'LOG_CHANNEL', 'LOG_LEVEL', 'NODE_ENV',
    ];

    /** @var array<string, string> */
    private const PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[REDACTED PRIVATE KEY]',
        '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/' => '[REDACTED AWS KEY]',
        '/\bsk-(?:proj-|ant-)?[A-Za-z0-9_-]{20,}\b/' => '[REDACTED API KEY]',
        '/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]{16,}\b/' => '[REDACTED STRIPE KEY]',
        '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/' => '[REDACTED GITHUB TOKEN]',
        '/\bxox[abpr]-[A-Za-z0-9-]{10,}\b/' => '[REDACTED SLACK TOKEN]',
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/' => '[REDACTED JWT]',
        '/\bbase64:[A-Za-z0-9+\/]{40,}={0,2}/' => '[REDACTED APP KEY]',
        '#(//)[^/\s:@]+:[^/\s@]+@#' => '$1[REDACTED]@',
        '/\b([A-Z0-9_]*(?:SECRET|PASSWORD|PASSWD|TOKEN|API_KEY|PRIVATE_KEY|ACCESS_KEY|CLIENT_SECRET)[A-Z0-9_]*)\s*[=:]\s*([\'"]?)[^\s\'"]{4,}\2/i' => '$1=[REDACTED]',
    ];

    public function redactText(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * Recursively redact a decoded payload: credential patterns in every string,
     * and any value stored under a credential-named key (e.g. "DB_PASSWORD").
     */
    public function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = is_string($key) && is_string($item) && $item !== '' && ! in_array($item, ['<set>', '<empty>'], true) && $this->isSecretKey($key)
                ? '<set>'
                : $this->redact($item);
        }

        return $out;
    }

    public function isSecretKey(string $key): bool
    {
        if (preg_match('/SECRET|PASSWORD|PASSWD|TOKEN|API_?KEY|PRIVATE_?KEY|ACCESS_?KEY|CREDENTIAL/i', $key)) {
            return true;
        }

        // Env-style names ending in _KEY (APP_KEY, PUSHER_APP_KEY, ...) — not ordinary fields like task_key.
        return (bool) preg_match('/^[A-Z0-9_]+_KEY$/', $key);
    }

    /**
     * Enforce env SHAPE: only allow-listed keys may carry a value; anything else
     * a runner reports becomes "<set>"/"<empty>", whatever it sent.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, string>
     */
    public function envShape(array $shape): array
    {
        $out = [];

        foreach (array_slice($shape, 0, 500, true) as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.]{0,127}$/', $key)) {
                continue;
            }

            $value = is_scalar($value) ? (string) $value : '';
            $isShape = in_array(strtoupper($key), self::SHAPE_KEYS, true) && preg_match('/^[\w.\-:\/]{0,64}$/', $value);

            $out[$key] = $isShape || in_array($value, ['<set>', '<empty>'], true) ? $value : ($value === '' ? '<empty>' : '<set>');
        }

        return $out;
    }
}

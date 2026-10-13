<?php

namespace App\Delivery;

/**
 * Turns an implementation answer into exact file writes (§48.8). Edits apply
 * only to the files the agent was shown, each search must match exactly once,
 * and anything that does not apply cleanly is a problem — never a guess.
 * Pure: no I/O, so the same answer always yields the same writes.
 */
final class EditApplier
{
    private const PROTECTED = '#^(\.git|vendor|node_modules)(/|$)#i';

    private const SECRET = '#(^|/)(\.env(\.(?!example$|sample$|dist$|template$)[^/]+)?|id_(rsa|dsa|ecdsa|ed25519)(\.pub)?|auth\.json|credentials(\.json)?|\.npmrc|\.netrc)$|\.(pem|key|p12|pfx|jks|keystore|crt|cer|kdbx)$#i';

    /**
     * @param  array<string, array{status: string, sha1?: string|null, content?: string|null, redacted?: bool}>  $files  what the runner returned, by path
     * @param  list<array<string, mixed>>  $changes  the agent's "changes"
     * @return array{writes: list<array{path: string, action: 'write'|'delete', base_sha1: string|null, content?: string}>, problems: list<string>}
     */
    public function apply(array $files, array $changes): array
    {
        $writes = [];
        $problems = [];
        $seen = [];

        foreach ($changes as $i => $change) {
            $path = $this->normalize((string) ($change['path'] ?? ''));
            $label = $path ?: "change #{$i}";

            if (! $path) {
                $problems[] = "Change #{$i} has an invalid path \"".mb_substr((string) ($change['path'] ?? ''), 0, 80).'"; use a workspace-relative path without "..".';

                continue;
            }
            if (isset($seen[$path])) {
                $problems[] = "{$path} appears in more than one change; put all edits for a file in one change.";

                continue;
            }
            $seen[$path] = true;

            if (preg_match(self::PROTECTED, $path) || preg_match(self::SECRET, $path)) {
                $problems[] = "{$path} may not be changed by agents (dependency, Git or secret file).";

                continue;
            }

            $file = $files[$path] ?? null;
            $exists = $file && ! in_array($file['status'], ['missing'], true);
            $action = $change['action'] ?? null;

            if ($action === 'create') {
                if ($exists) {
                    $problems[] = "{$path} already exists; change it with \"edit\".";
                } elseif (! is_string($change['content'] ?? null) || $change['content'] === '') {
                    $problems[] = "{$path}: \"create\" needs the full file content.";
                } elseif ($this->hasMarker($change['content'])) {
                    $problems[] = "{$path}: content contains a [REDACTED] marker.";
                } else {
                    $writes[] = ['path' => $path, 'action' => 'write', 'base_sha1' => null, 'content' => $change['content']];
                }

                continue;
            }

            if (! $file || $file['status'] !== 'ok') {
                $problems[] = "{$label} was not among the files you were shown (status: ".($file['status'] ?? 'not read').'); you can only edit or delete files from context.files.';

                continue;
            }
            if (! empty($file['redacted'])) {
                $problems[] = "{$path} contains credentials and is read-only for agents.";

                continue;
            }

            if ($action === 'delete') {
                $writes[] = ['path' => $path, 'action' => 'delete', 'base_sha1' => $file['sha1'] ?? null];

                continue;
            }

            if ($action !== 'edit' || ! is_array($change['edits'] ?? null) || $change['edits'] === []) {
                $problems[] = "{$path}: \"edit\" needs at least one search/replace edit.";

                continue;
            }

            // Compared without CR: the runner restores the file's own line endings when writing.
            $content = str_replace("\r\n", "\n", (string) $file['content']);
            $ok = true;

            foreach ($change['edits'] as $n => $edit) {
                $search = str_replace("\r\n", "\n", (string) ($edit['search'] ?? ''));
                $replace = str_replace("\r\n", "\n", (string) ($edit['replace'] ?? ''));
                $count = $search === '' ? 0 : substr_count($content, $search);

                if ($count !== 1) {
                    $problems[] = "{$path} edit #".($n + 1).': the search text '.($count === 0 ? 'was not found' : "occurs {$count} times").' — copy it exactly from the file and include enough lines to make it unique.';
                    $ok = false;

                    break;
                }
                if ($this->hasMarker($replace)) {
                    $problems[] = "{$path} edit #".($n + 1).': the replacement contains a [REDACTED] marker.';
                    $ok = false;

                    break;
                }

                $content = str_replace($search, $replace, $content);
            }

            if ($ok) {
                $writes[] = ['path' => $path, 'action' => 'write', 'base_sha1' => $file['sha1'] ?? null, 'content' => $content];
            }
        }

        return ['writes' => $writes, 'problems' => $problems];
    }

    public function normalize(string $path): ?string
    {
        $path = preg_replace('#^\./#', '', str_replace('\\', '/', trim($path)));

        if ($path === '' || strlen($path) > 300 || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '-')
            || preg_match('#^[A-Za-z]:#', $path) || in_array('..', explode('/', $path), true) || str_contains($path, '//')) {
            return null;
        }

        return $path;
    }

    private function hasMarker(string $text): bool
    {
        return (bool) preg_match('/\[REDACTED[ A-Z]*\]/', $text);
    }
}

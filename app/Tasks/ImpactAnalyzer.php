<?php

namespace App\Tasks;

use App\Models\ProjectIndexFile;
use App\Models\ProjectIndexSymbol;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Change-impact analysis (Plan §67), done deterministically against the code
 * index before any AI is involved (Plan §2.2, §2.4). Matches the request's
 * terms to symbols and paths, then derives areas, expected files and tests,
 * recommended agents, complexity and a token estimate — each with the
 * evidence it came from. The AI architect refines it in Phase 6.
 */
class ImpactAnalyzer
{
    public const VERSION = 'deterministic-v1';

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'only', 'must', 'should', 'will', 'can', 'can\'t', 'not',
        'add', 'make', 'new', 'create', 'update', 'change', 'remove', 'delete', 'fix', 'show', 'allow', 'when', 'have', 'has',
        'are', 'was', 'were', 'all', 'any', 'each', 'use', 'using', 'also', 'page', 'need', 'needs', 'able', 'user', 'users',
        'there', 'their', 'them', 'they', 'then', 'than', 'out', 'our', 'your', 'you', 'its', 'but', 'via', 'per', 'get', 'set',
        'remain', 'existing', 'work', 'works', 'like', 'list', 'view', 'data', 'file', 'files', 'button', 'test', 'tests',
    ];

    /** Kind weights: how strongly a matching symbol suggests its file will change. */
    private const KIND_WEIGHT = [
        'model' => 3.0, 'route' => 2.5, 'table' => 2.5, 'component' => 2.0, 'class' => 2.0, 'view' => 1.5,
        'hook' => 1.5, 'test' => 1.5, 'interface' => 1.5, 'trait' => 1.5, 'enum' => 1.5, 'type' => 1.0, 'function' => 1.0,
    ];

    private const SECURITY_WORDS = [
        'auth', 'login', 'logout', 'password', 'permission', 'permissions', 'role', 'roles', 'admin', 'access', 'policy',
        'token', 'payment', 'payments', 'checkout', 'stripe', 'paymongo', 'paypal', 'webhook', 'upload', 'export', 'import',
        'secret', 'session', 'csrf', 'personal', 'invoice', 'refund', 'billing', 'readonly', 'read-only', 'tenant', 'owner',
    ];

    private const DATABASE_WORDS = ['table', 'column', 'migration', 'database', 'schema', 'field', 'index', 'relation', 'relationship', 'store', 'record', 'records', 'report', 'reports'];

    private const UI_WORDS = ['dashboard', 'design', 'layout', 'ui', 'ux', 'screen', 'modal', 'form', 'chart', 'responsive', 'mobile', 'style', 'theme', 'component'];

    private const MODERNIZE = '/\b(upgrade|modernize|modernise|migrate (to|from)|convert (to|from)|rewrite)\b|laravel\s*\d+\s*(→|->|to)\s*\d+|\b(mix|webpack)\b.*\bvite\b|\bjavascript\b.*\btypescript\b|\bbootstrap\b.*\btailwind\b|pages router.*app router/i';

    /**
     * @return array<string, mixed>
     */
    public function analyze(Task $task): array
    {
        $project = $task->project;
        $text = trim($task->title."\n".$task->description."\n".implode("\n", $task->acceptance_criteria ?? []));
        $lower = mb_strtolower($text);
        $terms = $this->terms($text);
        $stack = $project->connection?->detected_stack ?? [];

        $notes = [];
        $modernization = (bool) preg_match(self::MODERNIZE, $text);
        if ($modernization) {
            $notes[] = 'Looks like a modernization request — treat it as a major migration: compatibility analysis, incremental steps, regression tests and a rollback plan (§71).';
        }
        if (($stack['backend']['framework'] ?? null) === 'Laravel' && ($v = $this->major($stack['backend']['version'] ?? $stack['backend']['constraint'] ?? null)) !== null && $v < 9) {
            $notes[] = "Legacy Laravel {$v}: changes follow the project's existing conventions; no unrelated modernization (§72).";
        }

        $files = $project->index_build_id ? $this->rankFiles($project->id, $project->index_build_id, $terms) : collect();

        if (! $project->index_build_id) {
            $notes[] = 'No code index yet — scan the project so expected files and tests can be found.';
        }

        $isTest = fn (array $f) => (bool) preg_match('#(^|/)tests?/|\.(test|spec)\.[jt]sx?$|Test\.php$#i', $f['path']);
        // Keep files that score at least a quarter of the best match: the strong hits, not every file sharing a word.
        $floor = max(3, ($files->reject($isTest)->max('score') ?? 0) * 0.25);
        $expectedFiles = $files->reject($isTest)->filter(fn ($f) => $f['score'] >= $floor)->take(20)->values();
        $expectedTests = $files->filter($isTest)->take(10)->values();

        $areas = [
            'backend' => $this->area($expectedFiles->filter(fn ($f) => $f['language'] === 'php' || str_starts_with($f['path'], 'routes/') || preg_match('#(^|/)(api|server)/#', $f['path']))),
            'frontend' => $this->area($expectedFiles->filter(fn ($f) => in_array($f['language'], ['typescript', 'javascript', 'vue', 'svelte', 'blade', 'css', 'scss'], true) && ! preg_match('#(^|/)(api|server)/#', $f['path']))),
            'database' => $this->area($expectedFiles->filter(fn ($f) => str_starts_with($f['path'], 'database/') || in_array('model', $f['kinds'], true) || in_array('table', $f['kinds'], true) || str_contains($f['path'], 'prisma/')), $this->words($lower, self::DATABASE_WORDS)),
            'security' => $this->area($expectedFiles->filter(fn ($f) => (bool) preg_match('#Polic(y|ies)|Middleware|Auth|Permission|Gate|Webhook|Payment#i', $f['path'])), $this->words($lower, self::SECURITY_WORDS)),
        ];

        // UI words with no matched frontend file still mean frontend work (a new screen).
        if ($areas['frontend']['level'] === 'none' && ($ui = $this->words($lower, self::UI_WORDS)) !== []) {
            $areas['frontend'] = ['level' => 'low', 'reasons' => array_map(fn ($w) => "mentions “{$w}”", $ui)];
        }

        $agents = $this->agents($areas, $lower, $expectedFiles->count());
        $complexity = $this->complexity($expectedFiles->count(), $areas, $modernization);

        return [
            'version' => self::VERSION,
            'terms' => array_slice($terms, 0, 20),
            'areas' => $areas,
            'expected_files' => $expectedFiles->map(fn ($f) => ['path' => $f['path'], 'reasons' => $f['reasons'], 'size' => $f['size']])->all(),
            'expected_tests' => $expectedTests->map(fn ($f) => ['path' => $f['path'], 'reasons' => $f['reasons']])->all(),
            'new_tests_needed' => $expectedTests->isEmpty(),
            'agents' => $agents,
            'complexity' => $complexity,
            'token_estimate' => $this->tokens($expectedFiles, $agents),
            'notes' => $notes,
            'basis' => $project->index_build_id
                ? 'Keyword match of the request against the code index. The AI team plans from it when the task is handed over.'
                : 'Keywords only — no code index yet.',
        ];
    }

    /**
     * @return list<string>
     */
    public function terms(string $text): array
    {
        // Split camelCase and snake_case, keep words of 3+ letters, singularize.
        $text = preg_replace('/([a-z])([A-Z])/', '$1 $2', $text);
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower(str_replace(['_', '-'], ' ', (string) $text)), $m);

        return collect($m[0])
            ->reject(fn ($w) => in_array($w, self::STOPWORDS, true) || ctype_digit($w))
            ->map(fn ($w) => mb_strlen($w) > 4 ? Str::singular($w) : $w)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $terms
     * @return Collection<int, array{path: string, language: string, size: int, score: float, reasons: list<string>, kinds: list<string>}>
     */
    private function rankFiles(int $projectId, string $buildId, array $terms): Collection
    {
        if ($terms === []) {
            return collect();
        }

        $terms = array_slice($terms, 0, 12);
        $like = fn ($q, string $column) => $q->where(function ($w) use ($terms, $column) {
            foreach ($terms as $term) {
                $w->orWhere($column, 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%');
            }
        });

        /** @var array<int, array{score: float, reasons: list<string>, kinds: list<string>}> $byFile */
        $byFile = [];

        $symbols = ProjectIndexSymbol::query()
            ->where('project_id', $projectId)
            ->where('build_id', $buildId)
            ->where('kind', '!=', 'dependency')
            ->tap(fn ($q) => $like($q, 'name'))
            ->limit(2000)
            ->get(['file_id', 'kind', 'name']);

        foreach ($symbols as $symbol) {
            [$raw, $matched] = $this->match($symbol->name, $terms);
            $score = $raw * (self::KIND_WEIGHT[$symbol->kind] ?? 1.0);

            if ($score <= 0) {
                continue;
            }

            $entry = $byFile[$symbol->file_id] ?? ['scores' => [], 'reasons' => [], 'kinds' => [], 'terms' => []];
            $entry['scores'][] = $score;
            $entry['terms'] = [...$entry['terms'], ...$matched];
            $entry['kinds'][] = $symbol->kind;
            if (count($entry['reasons']) < 3) {
                $entry['reasons'][] = "{$symbol->kind} {$symbol->name}";
            }
            $byFile[$symbol->file_id] = $entry;
        }

        $pathMatches = ProjectIndexFile::query()
            ->where('project_id', $projectId)
            ->where('build_id', $buildId)
            ->tap(fn ($q) => $like($q, 'path'))
            ->limit(500)
            ->get(['id', 'path']);

        foreach ($pathMatches as $file) {
            [$raw, $matched] = $this->match(pathinfo($file->path, PATHINFO_FILENAME).' '.str_replace('/', ' ', dirname($file->path)), $terms);
            $score = $raw * 1.5;

            if ($score > 0) {
                $entry = $byFile[$file->id] ?? ['scores' => [], 'reasons' => [], 'kinds' => [], 'terms' => []];
                $entry['scores'][] = $score;
                $entry['terms'] = [...$entry['terms'], ...$matched];
                if (count($entry['reasons']) < 3) {
                    $entry['reasons'][] = 'path matches';
                }
                $byFile[$file->id] = $entry;
            }
        }

        if ($byFile === []) {
            return collect();
        }

        return ProjectIndexFile::query()
            ->whereKey(array_keys($byFile))
            ->get(['id', 'path', 'language', 'size'])
            ->map(fn (ProjectIndexFile $f) => [
                'path' => $f->path,
                'language' => $f->language,
                'size' => (int) $f->size,
                'score' => $this->fileScore($byFile[$f->id]),
                'reasons' => array_values(array_unique($byFile[$f->id]['reasons'])),
                'kinds' => array_values(array_unique($byFile[$f->id]['kinds'])),
            ])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * A file's best match counts in full; further matches add a diminishing
     * share, so a catch-all file (routes, User model) with dozens of weak hits
     * does not outrank the file the request is actually about. Matching more of
     * the request's distinct terms still lifts a file.
     *
     * @param  array{scores: list<float>, terms: list<string>}  $entry
     */
    private function fileScore(array $entry): float
    {
        $scores = $entry['scores'];
        rsort($scores);
        $best = $scores[0] ?? 0.0;
        $rest = min(array_sum(array_slice($scores, 1)) * 0.2, $best);
        $coverage = 1 + 0.5 * (count(array_unique($entry['terms'])) - 1);

        return round(($best + $rest) * $coverage, 2);
    }

    /**
     * How well a name matches the terms: 3 per exact word, 2 per prefix, 1 per
     * substring (only for terms of 5+ letters, so "log" does not match "login").
     *
     * @param  list<string>  $terms
     * @return array{0: float, 1: list<string>} score and the terms that matched
     */
    private function match(string $name, array $terms): array
    {
        $parts = $this->terms($name);
        $haystack = mb_strtolower($name);
        $score = 0.0;
        $matched = [];

        foreach ($terms as $term) {
            $hit = match (true) {
                in_array($term, $parts, true) => 3,
                collect($parts)->contains(fn ($p) => str_starts_with($p, $term) || (str_starts_with($term, $p) && mb_strlen($p) >= 4)) => 2,
                mb_strlen($term) >= 5 && str_contains($haystack, $term) => 1,
                default => 0,
            };

            if ($hit > 0) {
                $score += $hit;
                $matched[] = $term;
            }
        }

        return [$score, $matched];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $files
     * @param  list<string>  $keywords
     * @return array{level: string, reasons: list<string>}
     */
    private function area(Collection $files, array $keywords = []): array
    {
        $count = $files->count();
        $level = match (true) {
            $count > 8 => 'high',
            $count > 3 => 'medium',
            $count > 0 => 'low',
            $keywords !== [] => 'low',
            default => 'none',
        };

        if ($keywords !== [] && $level === 'low' && $count > 0) {
            $level = 'medium';
        }

        return [
            'level' => $level,
            'reasons' => [
                ...($count ? ["{$count} matching file(s)"] : []),
                ...array_map(fn ($w) => "mentions “{$w}”", array_slice($keywords, 0, 4)),
            ],
        ];
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function words(string $lower, array $words): array
    {
        return array_values(array_filter($words, fn ($w) => preg_match('/\b'.preg_quote($w, '/').'\b/u', $lower)));
    }

    /**
     * Only the specialists the work needs (Plan §2.3, §68–70), by role monogram (§7.5).
     *
     * @param  array<string, array{level: string}>  $areas
     * @return list<array{role: string, reason: string}>
     */
    private function agents(array $areas, string $lower, int $files): array
    {
        $on = fn (string $a) => $areas[$a]['level'] !== 'none';
        $agents = [];

        if ($on('backend') && $on('frontend') && ($on('database') || $files > 8)) {
            $agents[] = ['role' => 'SA', 'reason' => 'Spans backend, frontend and data — architecture first (§70)'];
        }
        if ($on('frontend') && $this->words($lower, self::UI_WORDS) !== []) {
            $agents[] = ['role' => 'UX', 'reason' => 'UI/UX work requested (§68)'];
        }
        if ($on('database')) {
            $agents[] = ['role' => 'DB', 'reason' => 'Schema or query changes'];
        }
        if ($on('backend')) {
            $agents[] = ['role' => 'BE', 'reason' => 'Backend files affected'];
        }
        if ($on('frontend')) {
            $agents[] = ['role' => 'FE', 'reason' => 'Frontend files affected'];
        }
        if ($agents === []) {
            $agents[] = ['role' => 'BA', 'reason' => 'Nothing matched in the code index — clarify the requirement first'];
        }

        $agents[] = ['role' => 'QA', 'reason' => 'Every change is tested (§87)'];
        $agents[] = ['role' => 'SR', 'reason' => 'Senior review before approval (§87)'];

        if ($on('security')) {
            $agents[] = ['role' => 'SE', 'reason' => 'Touches auth, permissions, payments or data access (§74)'];
        }

        return $agents;
    }

    /**
     * Files touched plus how deep each area goes; a modernization is always large.
     *
     * @param  array<string, array{level: string}>  $areas
     */
    private function complexity(int $files, array $areas, bool $modernization): string
    {
        $depth = array_sum(array_map(fn ($a) => ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3][$a['level']], $areas));
        $score = $files + 1.5 * $depth + ($modernization ? 15 : 0);

        return match (true) {
            $score <= 6 => 'S',
            $score <= 14 => 'M',
            $score <= 24 => 'L',
            default => 'XL',
        };
    }

    /**
     * Rough budget (≈4 bytes per token). Authoring agents read the relevant
     * files (capped at 32 KB each — they work from symbols and regions, §2.4),
     * write ~25% of that, and go round ~2 cycles; reviewers (QA, SR, SE) mostly
     * read the diff (~30% of the context) twice.
     *
     * @param  Collection<int, array{size: int}>  $files
     * @param  list<array{role: string}>  $agents
     */
    private function tokens(Collection $files, array $agents): int
    {
        $context = (int) ($files->sum(fn ($f) => min($f['size'], 32 * 1024)) / 4) + 3000;
        $reviewers = count(array_filter($agents, fn ($a) => in_array($a['role'], ['QA', 'SR', 'SE'], true)));
        $authors = max(1, count($agents) - $reviewers);

        $estimate = $context * 1.25 * 2 * min($authors, 2) + $context * 0.3 * 2 * $reviewers;

        return (int) (ceil($estimate / 1000) * 1000);
    }

    private function major(?string $version): ?int
    {
        return $version && preg_match('/(\d+)/', $version, $m) ? (int) $m[1] : null;
    }
}

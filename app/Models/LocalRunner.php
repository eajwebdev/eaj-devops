<?php

namespace App\Models;

use App\Enums\RunnerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An EAJ Local Runner installed on a developer machine (Plan §89.2).
 */
class LocalRunner extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'signing_secret'];

    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'allowed_roots' => 'array',
            'capabilities' => 'array',
            'last_heartbeat_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<RunnerCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(RunnerCommand::class);
    }

    /**
     * @param  Builder<LocalRunner>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function status(): RunnerStatus
    {
        if ($this->revoked_at) {
            return RunnerStatus::Revoked;
        }

        $seconds = $this->last_heartbeat_at ? $this->last_heartbeat_at->diffInSeconds(now(), true) : PHP_INT_MAX;

        return match (true) {
            $seconds <= (int) config('eaj.heartbeat.stall_seconds') => RunnerStatus::Online,
            $seconds <= (int) config('eaj.heartbeat.fail_seconds') => RunnerStatus::Stale,
            default => RunnerStatus::Offline,
        };
    }

    public function isOnline(): bool
    {
        return $this->status() === RunnerStatus::Online;
    }

    /**
     * True when the folder sits inside a root the PC owner allowed. Comparison
     * mirrors the runner (case-insensitive on Windows and macOS); the runner
     * re-checks on the real file system, so this is only for early feedback.
     */
    public function allowsPath(string $path): bool
    {
        $insensitive = in_array($this->platform, ['win32', 'darwin'], true);
        $norm = fn (string $p) => rtrim($insensitive ? mb_strtolower(str_replace('\\', '/', $p)) : str_replace('\\', '/', $p), '/');
        $target = $norm($path);

        foreach ($this->allowed_roots ?? [] as $root) {
            $r = $norm($root);

            if ($target === $r || str_starts_with($target, $r.'/')) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Github;

use App\Models\GithubConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GithubApi
{
    public function user(GithubConnection $connection): array
    {
        return $this->get($connection, '/user');
    }

    /** @return list<array<string, mixed>> */
    public function repositories(GithubConnection $connection): array
    {
        return $this->get($connection, '/user/repos', ['affiliation' => 'owner,collaborator,organization_member', 'sort' => 'updated', 'per_page' => 100]);
    }

    public function repository(GithubConnection $connection, string $fullName): array
    {
        return $this->get($connection, '/repos/'.$this->repoPath($fullName));
    }

    public function branches(GithubConnection $connection, string $fullName): array
    {
        return $this->get($connection, '/repos/'.$this->repoPath($fullName).'/branches', ['per_page' => 100]);
    }

    public function pullRequest(GithubConnection $connection, string $fullName, int $number): array
    {
        return $this->get($connection, '/repos/'.$this->repoPath($fullName)."/pulls/{$number}");
    }

    /** @return list<array<string, mixed>> */
    public function pullRequests(GithubConnection $connection, string $fullName): array
    {
        return $this->get($connection, '/repos/'.$this->repoPath($fullName).'/pulls', ['state' => 'all', 'sort' => 'updated', 'direction' => 'desc', 'per_page' => 100]);
    }

    public function reviews(GithubConnection $connection, string $fullName, int $number): array
    {
        return $this->get($connection, '/repos/'.$this->repoPath($fullName)."/pulls/{$number}/reviews", ['per_page' => 100]);
    }

    public function createPullRequest(GithubConnection $connection, string $fullName, array $data): array
    {
        return $this->send($connection, 'post', '/repos/'.$this->repoPath($fullName).'/pulls', $data);
    }

    public function createReference(GithubConnection $connection, string $fullName, string $ref, string $sha): array
    {
        return $this->send($connection, 'post', '/repos/'.$this->repoPath($fullName).'/git/refs', ['ref' => 'refs/'.$ref, 'sha' => $sha]);
    }

    public function merge(GithubConnection $connection, string $fullName, int $number, string $sha, string $method): array
    {
        return $this->send($connection, 'put', '/repos/'.$this->repoPath($fullName)."/pulls/{$number}/merge", ['sha' => $sha, 'merge_method' => $method]);
    }

    private function request(GithubConnection $connection): PendingRequest
    {
        if ($connection->revoked_at) {
            throw new GithubException('The GitHub connection was disconnected.');
        }

        if ($connection->expires_at?->isPast()) {
            $this->refresh($connection);
        }

        $connection->forceFill(['last_used_at' => now()])->save();

        return Http::baseUrl(config('services.github.api_url'))
            ->acceptJson()
            ->withToken($connection->access_token)
            ->withHeaders([
                'X-GitHub-Api-Version' => config('services.github.api_version'),
                'User-Agent' => 'EAJ-AI-Dev-Studio',
            ])->timeout(20)->retry(2, 250, throw: false);
    }

    private function get(GithubConnection $connection, string $path, array $query = []): array
    {
        return $this->response(fn () => $this->request($connection)->get($path, $query));
    }

    private function send(GithubConnection $connection, string $method, string $path, array $data): array
    {
        return $this->response(fn () => $this->request($connection)->{$method}($path, $data));
    }

    private function response(callable $send): array
    {
        try {
            $response = $send();
            if (! $response->successful()) {
                throw new GithubException('GitHub returned '.$response->status().': '.($response->json('message') ?: 'request failed').'.');
            }

            return (array) $response->json();
        } catch (RequestException $e) {
            throw new GithubException('GitHub could not be reached: '.$e->getMessage(), previous: $e);
        }
    }

    private function refresh(GithubConnection $connection): void
    {
        if (! $connection->refresh_token || $connection->refresh_expires_at?->isPast()) {
            throw new GithubException('The GitHub session expired. Reconnect GitHub.');
        }

        $data = Http::acceptJson()->asForm()->post(config('services.github.token_url'), [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ])->throw()->json();

        $connection->forceFill([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
            'refresh_expires_at' => isset($data['refresh_token_expires_in']) ? now()->addSeconds((int) $data['refresh_token_expires_in']) : $connection->refresh_expires_at,
        ])->save();
    }

    private function repoPath(string $fullName): string
    {
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName)) {
            throw new GithubException('Invalid GitHub repository name.');
        }

        return collect(explode('/', $fullName))->map(fn ($part) => rawurlencode($part))->join('/');
    }
}

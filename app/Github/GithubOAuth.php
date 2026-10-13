<?php

namespace App\Github;

use App\Models\GithubConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GithubOAuth
{
    public function authorizationUrl(Request $request, Organization $organization): string
    {
        if (! config('services.github.client_id') || ! config('services.github.client_secret')) {
            throw new GithubException('GitHub OAuth is not configured. Add GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET.');
        }

        $state = Str::random(64);
        $request->session()->put('github_oauth', ['state' => $state, 'organization_id' => $organization->id, 'user_id' => $request->user()->id]);

        return config('services.github.authorize_url').'?'.http_build_query([
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => route('github.callback'),
            'scope' => 'repo read:user',
            'state' => $state,
        ]);
    }

    public function callback(Request $request, Organization $organization, User $user): GithubConnection
    {
        $expected = (array) $request->session()->pull('github_oauth', []);
        if (! isset($expected['state']) || ! hash_equals($expected['state'], (string) $request->query('state'))
            || (int) ($expected['organization_id'] ?? 0) !== $organization->id || (int) ($expected['user_id'] ?? 0) !== $user->id) {
            throw new GithubException('GitHub authentication state is invalid or expired. Try connecting again.');
        }

        $response = Http::acceptJson()->asForm()->post(config('services.github.token_url'), [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'code' => $request->string('code')->toString(),
            'redirect_uri' => route('github.callback'),
        ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new GithubException('GitHub did not authorize the connection: '.($response->json('error_description') ?: 'token exchange failed').'.');
        }

        $data = $response->json();
        $profile = Http::baseUrl(config('services.github.api_url'))->acceptJson()->withToken($data['access_token'])
            ->withHeaders(['X-GitHub-Api-Version' => config('services.github.api_version'), 'User-Agent' => 'EAJ-AI-Dev-Studio'])
            ->get('/user')->throw()->json();

        return GithubConnection::updateOrCreate(['organization_id' => $organization->id], [
            'connected_by' => $user->id,
            'github_user_id' => $profile['id'],
            'login' => $profile['login'],
            'avatar_url' => $profile['avatar_url'] ?? null,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'scopes' => array_values(array_filter(explode(',', (string) $response->header('X-OAuth-Scopes')))),
            'expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
            'refresh_expires_at' => isset($data['refresh_token_expires_in']) ? now()->addSeconds((int) $data['refresh_token_expires_in']) : null,
            'revoked_at' => null,
        ]);
    }
}

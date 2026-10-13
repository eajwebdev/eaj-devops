<?php

namespace App\AI\Providers;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Data\ProviderRequest;
use App\AI\Data\ProviderResponse;
use App\AI\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class AnthropicProvider implements AIProviderInterface
{
    public function key(): string
    {
        return 'anthropic';
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        $key = (string) config('ai.providers.anthropic.key');
        if ($key === '') {
            throw new ProviderException('Anthropic is not configured. Add ANTHROPIC_API_KEY.', $this->key(), 'not_configured');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('ai.providers.anthropic.base_url'), '/'))
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => (string) config('ai.providers.anthropic.version'),
                    'Idempotency-Key' => $request->idempotencyKey,
                ])->withOptions($request->httpOptions())->acceptJson()->timeout((int) config('ai.timeout_seconds'))->post('/v1/messages', [
                    'model' => $request->model,
                    'max_tokens' => $request->maxOutputTokens,
                    'system' => $request->system,
                    'messages' => [['role' => 'user', 'content' => $request->input]],
                    'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $request->schema]],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderException('Anthropic request timed out or could not connect.', $this->key(), 'connection_error', true, previous: $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $message = (string) ($response->json('error.message') ?: "Anthropic returned HTTP {$status}.");
            throw new ProviderException($message, $this->key(), (string) ($response->json('error.type') ?: 'http_error'), $status === 429 || $status >= 500, $status, ctype_digit((string) $response->header('Retry-After')) ? (int) $response->header('Retry-After') : 0);
        }

        $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? null;
        try {
            $output = is_string($text) ? json_decode($text, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new ProviderException('Anthropic returned malformed JSON.', $this->key(), 'invalid_json', previous: $e);
        }
        if (! is_array($output)) {
            throw new ProviderException('Anthropic response did not contain structured output.', $this->key(), 'missing_output');
        }

        return new ProviderResponse(
            $output,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.cache_read_input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            $response->header('request-id') ?: $response->json('id'),
        );
    }
}

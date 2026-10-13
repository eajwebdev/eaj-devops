<?php

namespace App\AI\Providers;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Data\ProviderRequest;
use App\AI\Data\ProviderResponse;
use App\AI\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class OpenAIProvider implements AIProviderInterface
{
    public function key(): string
    {
        return 'openai';
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        $key = (string) config('ai.providers.openai.key');
        if ($key === '') {
            throw new ProviderException('OpenAI is not configured. Add OPENAI_API_KEY.', $this->key(), 'not_configured');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('ai.providers.openai.base_url'), '/'))
                ->withToken($key)
                ->withOptions($request->httpOptions())
                ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
                ->acceptJson()
                ->timeout((int) config('ai.timeout_seconds'))
                ->post('/v1/responses', [
                    'model' => $request->model,
                    'instructions' => $request->system,
                    'input' => $request->input,
                    'max_output_tokens' => $request->maxOutputTokens,
                    'store' => false,
                    'text' => ['format' => ['type' => 'json_schema', 'name' => $request->schemaName, 'schema' => $request->schema, 'strict' => true]],
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderException('OpenAI request timed out or could not connect.', $this->key(), 'connection_error', true, previous: $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $message = (string) ($response->json('error.message') ?: "OpenAI returned HTTP {$status}.");
            throw new ProviderException($message, $this->key(), (string) ($response->json('error.code') ?: 'http_error'), $status === 429 || $status >= 500, $status, $this->retryAfter($response->header('Retry-After')));
        }

        $text = $response->json('output_text');
        if (! is_string($text)) {
            $text = collect($response->json('output', []))->flatMap(fn ($item) => $item['content'] ?? [])->firstWhere('type', 'output_text')['text'] ?? null;
        }

        try {
            $output = is_string($text) ? json_decode($text, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new ProviderException('OpenAI returned malformed JSON.', $this->key(), 'invalid_json', previous: $e);
        }
        if (! is_array($output)) {
            throw new ProviderException('OpenAI response did not contain structured output.', $this->key(), 'missing_output');
        }

        return new ProviderResponse(
            $output,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.input_tokens_details.cached_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            $response->header('x-request-id') ?: $response->json('id'),
        );
    }

    private function retryAfter(?string $value): int
    {
        return ctype_digit((string) $value) ? (int) $value : 0;
    }
}

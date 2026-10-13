<?php

namespace App\AI\Providers;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Data\ProviderRequest;
use App\AI\Data\ProviderResponse;
use App\AI\Exceptions\ProviderException;
use SplQueue;
use Throwable;

class FakeAIProvider implements AIProviderInterface
{
    /** @var SplQueue<ProviderResponse|Throwable> */
    private SplQueue $script;

    /** @var list<ProviderRequest> what the agents were asked, for tests */
    public array $requests = [];

    public function __construct()
    {
        $this->script = new SplQueue;
    }

    public function key(): string
    {
        return 'fake';
    }

    public function push(ProviderResponse|Throwable $result): self
    {
        $this->script->enqueue($result);

        return $this;
    }

    public function clear(): void
    {
        $this->script = new SplQueue;
        $this->requests = [];
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;

        if ($this->script->isEmpty()) {
            throw new ProviderException('The fake provider has no scripted response.', $this->key(), 'script_exhausted');
        }
        $result = $this->script->dequeue();
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
